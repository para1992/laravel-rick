<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Execution\Support\Memory\MemoryMerger;
use Rick\Laravel\Application\Execution\Support\Planning\UnfoldUnitExtractor;
use Rick\Laravel\Application\Execution\Support\Quality\ContentDistinctness;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Application\Execution\Support\Schema\UnfoldCandidateSchema;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Domain\Execution\Interface\CandidateSelectionBase;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\AwaitingCandidateSelectionPlan;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Execution\UnfoldPhase;
use Rick\Laravel\Domain\Execution\UnfoldProgress;
use Rick\Laravel\Domain\Execution\UnfoldUnit;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Memory\MemoryDelta;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\CandidateDecision;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\UnfoldStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class UnfoldStrategy implements CandidateSelectionBase, InvocationReductionBase, StepStrategyBase
{
    public function __construct(
        private IdGeneratorBase $ids,
        private UnfoldUnitExtractor $units,
        private MemoryMerger $memory,
        private CompletionRequestFactory $requests,
        private ContentDistinctness $distinctness,
        private UnfoldCandidateSchema $candidateSchema,
    ) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'unfold';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        $step = $this->step($step);
        $progress = $this->progress($step, $run);

        return match ($progress->phase) {
            UnfoldPhase::Explode => $this->explosionPlan($step, $run),
            UnfoldPhase::Generate => $this->generationPlan($step, $run, $progress),
            UnfoldPhase::Judge => $this->judgePlan($step, $run, $progress),
            UnfoldPhase::Complete => throw new LogicException(
                'A completed UNFOLD step cannot plan another phase.',
            ),
        };
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        $responses = InvocationResponses::successful($outcomes);
        $step = $this->step($step);
        $progress = $this->progress($step, $run);

        return match ($progress->phase) {
            UnfoldPhase::Explode => $this->reduceExplosion($step, $responses),
            UnfoldPhase::Generate => $this->reduceGeneration(
                $step,
                $run,
                $progress,
                $responses,
            ),
            UnfoldPhase::Judge => throw new LogicException(
                'UNFOLD manual selection does not reduce invocation responses.',
            ),
            UnfoldPhase::Complete => throw new LogicException(
                'A completed UNFOLD step cannot reduce another phase.',
            ),
        };
    }

    public function select(
        StepBase $step,
        WorkflowRunSnapshot $run,
        CandidateId $candidateId,
    ): StepOutcome {
        $step = $this->step($step);
        $progress = $this->progress($step, $run);
        if (! $step->judge || $progress->phase !== UnfoldPhase::Judge) {
            throw new LogicException('UNFOLD is not waiting for a manual unit decision.');
        }

        $selected = null;
        foreach ($run->currentCandidates as $candidate) {
            if ($candidate->id->toString() === $candidateId->toString()) {
                $selected = $candidate;
                break;
            }
        }
        if (! $selected instanceof Candidate) {
            throw new LogicException(sprintf(
                'Candidate [%s] is not available in the pending UNFOLD review.',
                $candidateId->toString(),
            ));
        }

        return $this->acceptedOutcome(
            $step,
            $run,
            $progress,
            $selected,
            new CandidateDecision(
                $step->id(),
                $selected->id,
                null,
                'Selected by a human reviewer.',
                'unfold_manual_judge',
            ),
        );
    }

    private function explosionPlan(
        UnfoldStep $step,
        WorkflowRunSnapshot $run,
    ): InvocationStepPlan {
        $source = $run->artifact($step->sourceArtifact->toString());

        return new InvocationStepPlan([$this->requests->create(
            'rick.step.unfold.units',
            "Task:\n{$run->task}\n\nDefinition of done:\n{$run->dod->toPromptString()}"
                ."\n\nSplit the source into at most {$step->maxUnits} ordered executable units."
                ."\nReturn stable unit_id, title, source_order, content, constraints,"
                .' must_preserve, dependencies, must_cover, must_not_repeat,'
                .' and memory read/write keys.'
                ."\nCopy every literal uppercase marker from the source into that unit's must_preserve list exactly."
                ."\n\nSource:\n{$source->content}",
            ResponseContract::UnfoldUnits,
            'unfold_units',
            'cheap',
        )]);
    }

    private function generationPlan(
        UnfoldStep $step,
        WorkflowRunSnapshot $run,
        UnfoldProgress $progress,
    ): InvocationStepPlan {
        $unit = $progress->currentUnit();
        $selected = $this->selectedCandidates($run, $progress);
        $priorSummaries = array_values(array_filter(array_map(
            static fn ($card): string => trim($card->summary),
            $progress->memory->unitCards,
        ), static fn (string $summary): bool => $summary !== ''));
        $unitContext = $unit->toArray();
        $unitContext['must_not_repeat'] = array_values(array_unique([
            ...$unit->mustNotRepeat,
            ...$priorSummaries,
        ]));
        $context = json_encode([
            'child_artifact' => $step->childArtifact->toString(),
            'current_unit' => $unitContext,
            'continuity_memory' => $progress->memory->promptProjection(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $priorSignatures = array_map(
            fn (Candidate $candidate): array => $this->distinctness->signature($candidate->content),
            $selected,
        );
        $requiredLiterals = self::requiredLiterals($unit);
        $requiredLiteralsJson = json_encode(
            $requiredLiterals,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $requests = [];

        for ($index = 0; $index < $step->candidateCount; $index++) {
            $requests[] = $this->requests->create(
                'rick.step.unfold.candidate',
                "Task:\n{$run->task}\n\nDefinition of done:\n{$run->dod->toPromptString()}"
                    ."\n\nRequired artifact type: {$step->childArtifact->toString()}."
                    ."\nProduce exactly one {$step->childArtifact->toString()} for the current unit only."
                    ."\nDo not output or restate the source outline."
                    ."\nUse continuity_memory only for continuity."
                    ."\nDo not copy, summarize, or repeat previous units."
                    ."\nCover current_unit.content and every current_unit.must_cover requirement."
                    ."\nPreserve every current_unit.must_preserve value exactly."
                    ."\nRequired literal values: {$requiredLiteralsJson}. Each value must appear verbatim in content."
                    ."\n\nExecution context:\n{$context}"
                    ."\n\nProduce candidate ".($index + 1)." of {$step->candidateCount}."
                    ."\nReturn artifact_type, title, a concise continuity summary, content,"
                    .' and memory_delta describing only durable facts, decisions, loops,'
                    .' and requirement coverage.',
                ResponseContract::MemoryCandidate,
                sprintf(
                    'unfold_candidate_%d_of_%d',
                    $progress->unitIndex + 1,
                    count($progress->units),
                ),
                $step->modelPolicyId,
                [
                    'candidate_index' => $index,
                    'unit_id' => $unit->id,
                    'unit_index' => $progress->unitIndex,
                    'expected_artifact_type' => $step->childArtifact->toString(),
                    'required_literals' => $requiredLiterals,
                    'source_unit_signature' => $this->distinctness->signature($unit->content),
                    'content_distinctness' => [
                        'algorithm' => 'normalized_sha256_bottom_k_5gram_v1',
                        'prior_signatures' => $priorSignatures,
                    ],
                ],
                $this->candidateSchema->for($step->childArtifact),
            );
        }
        if ($requests === []) {
            throw new LogicException('UNFOLD requires at least one candidate request.');
        }

        return new InvocationStepPlan(
            $requests,
            InvocationCompletionPolicy::minimumSuccessful(1),
        );
    }

    private function judgePlan(
        UnfoldStep $step,
        WorkflowRunSnapshot $run,
        UnfoldProgress $progress,
    ): AwaitingCandidateSelectionPlan {
        if (! $step->judge || $run->currentCandidates === []) {
            throw new LogicException('UNFOLD judge phase requires generated candidates.');
        }

        return new AwaitingCandidateSelectionPlan([
            'scope' => 'unfold_unit',
            'unit_index' => $progress->unitIndex,
            'unit_number' => $progress->unitIndex + 1,
            'total_units' => count($progress->units),
            'current_unit' => $progress->currentUnit()->toArray(),
            'working_memory' => $progress->memory->toArray(),
        ]);
    }

    /** @param list<CompletionResponse> $responses */
    private function reduceExplosion(UnfoldStep $step, array $responses): StepOutcome
    {
        $structured = $responses[0]->structured ?? [];
        $values = $structured['units'] ?? null;
        if (! is_array($values)) {
            throw new LogicException('UNFOLD explosion response contains no units.');
        }
        $next = UnfoldProgress::forUnits(
            $this->units->fromValues(array_values($values), $step->maxUnits),
        );

        return StepOutcome::continuation(
            $next->toArray(),
            metadata: $this->metadata('exploded', $next),
        );
    }

    /** @param list<CompletionResponse> $responses */
    private function reduceGeneration(
        UnfoldStep $step,
        WorkflowRunSnapshot $run,
        UnfoldProgress $progress,
        array $responses,
    ): StepOutcome {
        $unit = $progress->currentUnit();
        $candidates = [];
        foreach ($responses as $index => $response) {
            $structured = $response->structured ?? [];
            $id = $this->ids->generate();
            $memoryDelta = self::map($structured['memory_delta'] ?? [], 'memory_delta');
            $title = self::string(
                $structured['title'] ?? null,
                'title',
            );
            $summary = self::string($structured['summary'] ?? null, 'summary');
            $payload = self::map($structured['payload'] ?? [], 'payload');
            $content = self::string($structured['content'] ?? $response->text, 'content');
            $candidates[] = new Candidate(
                CandidateId::fromString($id),
                $step->id(),
                $step->childArtifact,
                $title,
                $summary,
                $payload,
                $content,
                $id,
                'independent UNFOLD unit candidate',
                [
                    'output_key' => $step->childArtifact->toString(),
                    'memory_delta' => $memoryDelta,
                    'unfold' => [
                        'source_artifact' => $step->sourceArtifact->toString(),
                        'unit_id' => $unit->id,
                        'unit_index' => $progress->unitIndex + 1,
                        'total_units' => count($progress->units),
                        'source_order' => $unit->sourceOrder,
                    ],
                ],
            );
        }

        if ($step->judge) {
            $next = $progress->awaitingJudge();

            return StepOutcome::continuation(
                $next->toArray(),
                candidates: $candidates,
                metadata: $this->metadata('generated', $next),
            );
        }

        $selected = $candidates[0] ?? throw new LogicException(
            'UNFOLD generation produced no candidates.',
        );

        return $this->acceptedOutcome(
            $step,
            $run,
            $progress,
            $selected,
            new CandidateDecision(
                $step->id(),
                $selected->id,
                100,
                'First candidate selected by deterministic UNFOLD policy.',
                'unfold_first_candidate',
            ),
            $candidates,
        );
    }

    /**
     * @param  list<Candidate>  $candidates
     */
    private function acceptedOutcome(
        UnfoldStep $step,
        WorkflowRunSnapshot $run,
        UnfoldProgress $progress,
        Candidate $selected,
        CandidateDecision $decision,
        array $candidates = [],
    ): StepOutcome {
        $deltaValue = $selected->metadata['memory_delta'] ?? null;
        $delta = $deltaValue === null
            ? new MemoryDelta
            : MemoryDelta::fromArray(self::map($deltaValue, 'memory_delta'));
        $nextMemory = $this->memory->commit(
            $progress->memory,
            $delta,
            $progress->currentUnit(),
            $selected,
        );
        $next = $progress->accept($selected->id, $nextMemory);
        $metadata = $this->metadata('selected', $next) + [
            'selected_candidate_id' => $selected->id->toString(),
            'memory_version' => $nextMemory->version,
            'memory_hash' => $nextMemory->hash(),
        ];

        if (! $next->isComplete()) {
            return StepOutcome::continuation(
                $next->toArray(),
                candidates: $candidates,
                decision: $decision,
                metadata: $metadata,
            );
        }

        $parts = [...$this->selectedCandidates($run, $progress), $selected];
        $output = implode("\n\n", array_values(array_filter(
            array_map(
                static fn (Candidate $candidate): string => trim($candidate->content),
                $parts,
            ),
            static fn (string $content): bool => $content !== '',
        )));
        if ($output === '') {
            throw new LogicException('UNFOLD assembled an empty output.');
        }

        return StepOutcome::completion(
            $next->toArray(),
            candidates: $candidates,
            decision: $decision,
            rawOutput: $output,
            metadata: $metadata + ['assembled_characters' => strlen($output)],
            artifacts: [new Artifact(
                $step->childArtifact->toString(),
                $step->childArtifact,
                $output,
                [
                    'units' => array_map(
                        static fn ($unit): array => $unit->toArray(),
                        $next->units,
                    ),
                    'selected_candidate_ids' => $next->selectedCandidateIds,
                ],
                [
                    'memory_version' => $nextMemory->version,
                    'memory_hash' => $nextMemory->hash(),
                ],
            )],
        );
    }

    private function progress(
        UnfoldStep $step,
        WorkflowRunSnapshot $run,
    ): UnfoldProgress {
        $state = $run->stepState($step->id()->toString());
        if ($state !== []) {
            return UnfoldProgress::fromArray($state);
        }

        $source = $run->artifact($step->sourceArtifact->toString());
        if (array_is_list($source->payload) && $source->payload !== []) {
            return UnfoldProgress::forUnits($this->units->fromValues(
                $source->payload,
                $step->maxUnits,
            ));
        }
        foreach (['sections', 'units', 'items', 'files'] as $key) {
            if (is_array($source->payload[$key] ?? null)) {
                return UnfoldProgress::forUnits($this->units->fromValues(
                    array_values($source->payload[$key]),
                    $step->maxUnits,
                ));
            }
        }

        return UnfoldProgress::needsExplosion();
    }

    /** @return list<Candidate> */
    private function selectedCandidates(
        WorkflowRunSnapshot $run,
        UnfoldProgress $progress,
    ): array {
        $byId = [];
        foreach ($run->acceptedCandidates as $candidate) {
            $byId[$candidate->id->toString()] = $candidate;
        }

        return array_map(
            static fn (string $id): Candidate => $byId[$id] ?? throw new LogicException(
                "Persisted UNFOLD candidate [{$id}] was not found.",
            ),
            $progress->selectedCandidateIds,
        );
    }

    /** @return array<string, mixed> */
    private function metadata(string $phase, UnfoldProgress $progress): array
    {
        return ['unfold' => [
            'phase' => $phase,
            'unit_index' => $progress->unitIndex + 1,
            'total_units' => count($progress->units),
            'selected_units' => count($progress->selectedCandidateIds),
        ]];
    }

    private function step(StepBase $step): UnfoldStep
    {
        return $step instanceof UnfoldStep
            ? $step
            : throw new LogicException('Unfold strategy received an incompatible step.');
    }

    private static function string(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new LogicException("UNFOLD response field [{$field}] must be a string.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new LogicException("UNFOLD response field [{$field}] must be an object.");
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new LogicException("UNFOLD response field [{$field}] must be an object.");
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /** @return list<string> */
    private static function requiredLiterals(UnfoldUnit $unit): array
    {
        $source = implode("\n", [$unit->content, ...$unit->mustPreserve]);
        preg_match_all('/\b[A-Z][A-Z0-9_]{2,}\b/', $source, $matches);
        $literals = array_values(array_unique($matches[0]));
        sort($literals);

        return $literals;
    }
}
