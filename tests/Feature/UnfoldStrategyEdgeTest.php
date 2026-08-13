<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use LogicException;
use Rick\Laravel\Application\Execution\Strategy\UnfoldStrategy;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Execution\Support\Memory\MemoryMerger;
use Rick\Laravel\Application\Execution\Support\Planning\UnfoldUnitExtractor;
use Rick\Laravel\Application\Execution\Support\Quality\ContentDistinctness;
use Rick\Laravel\Application\Execution\Support\Schema\UnfoldCandidateSchema;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\Plan\AwaitingCandidateSelectionPlan;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Execution\UnfoldProgress;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionMode;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Memory\MemoryDelta;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\Step\UnfoldStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Tests\TestCase;

final class UnfoldStrategyEdgeTest extends TestCase
{
    public function test_type_support_and_incompatible_step_are_exact(): void
    {
        $strategy = $this->strategy();
        self::assertTrue($strategy->supports(StepType::unfold()));
        self::assertFalse($strategy->supports(StepType::rawPrompt()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unfold strategy received an incompatible step.');
        $strategy->plan(new RawPromptStep(StepId::fromString('raw'), 'prompt'), $this->snapshot());
    }

    public function test_explosion_plan_and_reduction_are_exact_and_fail_closed(): void
    {
        $strategy = $this->strategy();
        $step = $this->step(maxUnits: 2);
        $plan = $strategy->plan($step, $this->snapshot(content: 'Source outline'));

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(1, $plan->requests);
        $request = $plan->requests[0];
        self::assertSame(ResponseContract::UnfoldUnits, $request->responseContract);
        self::assertSame('unfold_units', $request->purpose);
        self::assertSame('cheap', $request->modelTier);
        self::assertSame(['prompt_profile_id', 'prompt_profile_version', 'prompt_profile_hash'], array_keys($request->metadata));
        self::assertSame('rick.step.unfold.units', $request->metadata['prompt_profile_id']);
        self::assertSame(
            "Task:\nExpand source\n\nDefinition of done:\nEvery unit is expanded"
            ."\n\nSplit the source into at most 2 ordered executable units."
            ."\nReturn stable unit_id, title, source_order, content, constraints, must_preserve, dependencies, must_cover, must_not_repeat, and memory read/write keys."
            ."\nCopy every literal uppercase marker from the source into that unit's must_preserve list exactly."
            ."\n\nSource:\nSource outline",
            $request->messages[1]->content,
        );

        $reduced = $strategy->reduce($step, $this->snapshot(content: 'Source outline'), [
            $this->outcome(new CompletionResponse(structured: ['units' => ['Second', 'First']])),
        ]);
        self::assertTrue($reduced->continuesStep);
        $stepState = self::arrayValue($reduced->stepState);
        $units = self::arrayValue($stepState['units'] ?? null);
        self::assertSame('generate', $stepState['phase']);
        self::assertSame(0, $stepState['unit_index']);
        self::assertSame(['unit_1', 'unit_2'], array_column($units, 'unit_id'));
        self::assertSame(['Second', 'First'], array_column($units, 'content'));
        self::assertSame([
            'unfold' => ['phase' => 'exploded', 'unit_index' => 1, 'total_units' => 2, 'selected_units' => 0],
        ], $reduced->metadata);

        foreach ([null, 'invalid'] as $malformedUnits) {
            try {
                $strategy->reduce($step, $this->snapshot(), [
                    $this->outcome(new CompletionResponse(
                        structured: $malformedUnits === null ? [] : ['units' => $malformedUnits],
                    )),
                ]);
                self::fail('Malformed explosion response was accepted.');
            } catch (LogicException $error) {
                self::assertSame('UNFOLD explosion response contains no units.', $error->getMessage());
            }
        }
    }

    public function test_generation_plan_contains_exact_context_metadata_and_schema(): void
    {
        $strategy = $this->strategy();
        $step = $this->step(candidateCount: 2, maxUnits: 2, policy: 'writing');
        $run = $this->snapshot(payload: ['First unit CLOCK_0317', 'Second unit']);
        $plan = $strategy->plan($step, $run);

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(2, $plan->requests);
        self::assertSame(InvocationCompletionMode::MinimumSuccessful, $plan->completionPolicy->mode);
        self::assertSame(1, $plan->completionPolicy->minimumSuccessful);
        self::assertSame(1, $plan->completionPolicy->required(2));
        foreach ($plan->requests as $index => $request) {
            self::assertSame(ResponseContract::MemoryCandidate, $request->responseContract);
            self::assertSame('unfold_candidate_1_of_2', $request->purpose);
            self::assertSame('writing', $request->modelTier);
            self::assertSame($index, $request->metadata['candidate_index']);
            self::assertSame('unit_1', $request->metadata['unit_id']);
            self::assertSame(0, $request->metadata['unit_index']);
            self::assertSame('section', $request->metadata['expected_artifact_type']);
            self::assertSame(['CLOCK_0317'], $request->metadata['required_literals']);
            $distinctness = self::arrayValue($request->metadata['content_distinctness'] ?? null);
            $schema = self::arrayValue($request->responseSchema);
            $properties = self::arrayValue($schema['properties'] ?? null);
            $artifactType = self::arrayValue($properties['artifact_type'] ?? null);
            self::assertSame('normalized_sha256_bottom_k_5gram_v1', $distinctness['algorithm']);
            self::assertSame([], $distinctness['prior_signatures']);
            self::assertSame('object', $schema['type']);
            self::assertSame(['section'], $artifactType['enum']);
            self::assertStringContainsString('Required artifact type: section.', $request->messages[1]->content);
            self::assertStringContainsString(
                'Preserve every current_unit.must_preserve value exactly.',
                $request->messages[1]->content,
            );
            self::assertStringContainsString(
                'Required literal values: ["CLOCK_0317"]. Each value must appear verbatim in content.',
                $request->messages[1]->content,
            );
            self::assertStringContainsString('"unit_id":"unit_1"', $request->messages[1]->content);
            self::assertStringContainsString('"must_preserve":["Preserve unit intent: First unit CLOCK_0317"]', $request->messages[1]->content);
            self::assertStringContainsString('Produce candidate '.($index + 1).' of 2.', $request->messages[1]->content);
            self::assertStringNotContainsString('Second unit', $request->messages[1]->content);
        }
    }

    public function test_automatic_generation_selects_first_candidate_and_continues_exactly(): void
    {
        $strategy = $this->strategy(['candidate-a', 'candidate-b']);
        $step = $this->step(candidateCount: 2, maxUnits: 2);
        $run = $this->snapshot(payload: ['First unit', 'Second unit']);
        $outcome = $strategy->reduce($step, $run, [
            $this->outcome($this->candidateResponse('First title', 'First summary', 'First result', ['fact-a'], ['req-a'])),
            $this->outcome($this->candidateResponse('Second title', 'Second summary', 'Second result')),
        ]);

        self::assertTrue($outcome->continuesStep);
        $stepState = self::arrayValue($outcome->stepState);
        $memory = self::arrayValue($stepState['memory'] ?? null);
        self::assertSame('generate', $stepState['phase']);
        self::assertSame(1, $stepState['unit_index']);
        self::assertSame(['candidate-a'], $stepState['selected_candidate_ids']);
        self::assertSame(1, $memory['version']);
        self::assertSame(['fact-a'], $memory['facts']);
        self::assertCount(2, $outcome->candidates);
        self::assertSame(['candidate-a', 'candidate-b'], array_map(static fn (Candidate $candidate): string => $candidate->id->toString(), $outcome->candidates));
        self::assertNotNull($outcome->decision);
        self::assertSame('candidate-a', $outcome->decision->selectedCandidateId->toString());
        self::assertSame(100.0, $outcome->decision->score);
        self::assertSame('unfold_first_candidate', $outcome->decision->policy);
        self::assertSame('First title', $outcome->candidates[0]->title);
        self::assertSame('First summary', $outcome->candidates[0]->summary);
        self::assertSame(['kind' => 'section'], $outcome->candidates[0]->payload);
        self::assertSame('First result', $outcome->candidates[0]->content);
        self::assertSame('candidate-a', $outcome->candidates[0]->seedRandomString);
        self::assertSame('independent UNFOLD unit candidate', $outcome->candidates[0]->seedInterpretation);
        self::assertSame([
            'output_key' => 'section',
            'memory_delta' => [
                'facts_added' => ['fact-a'],
                'decisions_added' => [],
                'loops_opened' => [],
                'loops_resolved' => [],
                'requirements_covered' => ['req-a'],
                'requirements_violated' => [],
            ],
            'unfold' => [
                'source_artifact' => 'source',
                'unit_id' => 'unit_1',
                'unit_index' => 1,
                'total_units' => 2,
                'source_order' => 1,
            ],
        ], $outcome->candidates[0]->metadata);
        $metadataUnfold = self::arrayValue($outcome->metadata['unfold'] ?? null);
        self::assertSame('selected', $metadataUnfold['phase']);
        self::assertSame(2, $metadataUnfold['unit_index']);
        self::assertSame(2, $metadataUnfold['total_units']);
        self::assertSame(1, $metadataUnfold['selected_units']);
        self::assertSame('candidate-a', $outcome->metadata['selected_candidate_id']);
        self::assertSame(1, $outcome->metadata['memory_version']);
    }

    public function test_final_generation_assembles_exact_output_payload_and_metadata(): void
    {
        $first = $this->candidate('candidate-a', 'First result', 'First summary');
        $progress = UnfoldProgress::forUnits((new UnfoldUnitExtractor)->fromValues(['First unit', 'Second unit'], 2));
        $progress = $progress->accept($first->id, (new MemoryMerger)->commit(
            $progress->memory,
            new MemoryDelta,
            $progress->currentUnit(),
            $first,
        ));
        $run = $this->snapshot(state: $progress->toArray(), accepted: [$first]);
        $outcome = $this->strategy(['candidate-b'])->reduce(
            $this->step(maxUnits: 2),
            $run,
            [$this->outcome($this->candidateResponse('Second title', 'Second summary', ' Second result '))],
        );

        self::assertFalse($outcome->continuesStep);
        $stepState = self::arrayValue($outcome->stepState);
        self::assertSame('complete', $stepState['phase']);
        self::assertSame(['candidate-a', 'candidate-b'], $stepState['selected_candidate_ids']);
        self::assertSame("First result\n\nSecond result", $outcome->rawOutput);
        self::assertSame(strlen($outcome->rawOutput), $outcome->metadata['assembled_characters']);
        self::assertSame(2, $outcome->metadata['memory_version']);
        self::assertCount(1, $outcome->artifacts);
        self::assertSame('section', $outcome->artifacts[0]->key);
        self::assertSame($outcome->rawOutput, $outcome->artifacts[0]->content);
        self::assertSame(['candidate-a', 'candidate-b'], $outcome->artifacts[0]->payload['selected_candidate_ids']);
        self::assertCount(2, self::arrayValue($outcome->artifacts[0]->payload['units'] ?? null));
        self::assertSame($stepState['units'], $outcome->artifacts[0]->payload['units']);
        self::assertSame([
            'memory_version' => 2,
            'memory_hash' => $outcome->metadata['memory_hash'],
        ], $outcome->artifacts[0]->metadata);
        self::assertCount(1, $outcome->candidates);
        self::assertSame('candidate-b', $outcome->candidates[0]->id->toString());
        self::assertNotNull($outcome->decision);
        self::assertSame('candidate-b', $outcome->decision->selectedCandidateId->toString());
    }

    public function test_manual_judge_plan_selection_and_invalid_candidate_paths_are_exact(): void
    {
        $candidate = $this->candidate('candidate-review', 'Reviewed result', 'Reviewed summary');
        $progress = UnfoldProgress::forUnits((new UnfoldUnitExtractor)->fromValues(['Only unit'], 1))->awaitingJudge();
        $run = $this->snapshot(
            state: $progress->toArray(),
            current: [$candidate],
        );
        $strategy = $this->strategy();
        $step = $this->step(judge: true);
        $plan = $strategy->plan($step, $run);

        self::assertInstanceOf(AwaitingCandidateSelectionPlan::class, $plan);
        self::assertSame('unfold_unit', $plan->metadata['scope']);
        self::assertSame(0, $plan->metadata['unit_index']);
        self::assertSame(1, $plan->metadata['unit_number']);
        self::assertSame(1, $plan->metadata['total_units']);
        self::assertSame('unit_1', self::arrayValue($plan->metadata['current_unit'] ?? null)['unit_id']);
        self::assertSame(0, self::arrayValue($plan->metadata['working_memory'] ?? null)['version']);

        $selected = $strategy->select($step, $run, $candidate->id);
        self::assertFalse($selected->continuesStep);
        self::assertNotNull($selected->decision);
        self::assertSame('candidate-review', $selected->decision->selectedCandidateId->toString());
        self::assertSame('unfold_manual_judge', $selected->decision->policy);
        self::assertSame('Selected by a human reviewer.', $selected->decision->reason);

        try {
            $strategy->select($step, $run, CandidateId::fromString('missing'));
            self::fail('Unknown candidate was selected.');
        } catch (LogicException $error) {
            self::assertSame('Candidate [missing] is not available in the pending UNFOLD review.', $error->getMessage());
        }
        try {
            $strategy->select($this->step(judge: false), $run, $candidate->id);
            self::fail('Non-judge step accepted manual selection.');
        } catch (LogicException $error) {
            self::assertSame('UNFOLD is not waiting for a manual unit decision.', $error->getMessage());
        }
    }

    public function test_every_named_payload_collection_and_associative_normalization_are_supported(): void
    {
        foreach (['sections', 'units', 'items', 'files'] as $key) {
            $plan = $this->strategy()->plan(
                $this->step(),
                $this->snapshot(payload: [$key => [10 => 'Named unit']]),
            );
            self::assertInstanceOf(InvocationStepPlan::class, $plan, $key);
            self::assertSame('unit_1', $plan->requests[0]->metadata['unit_id'], $key);
            self::assertStringContainsString('Named unit', $plan->requests[0]->messages[1]->content, $key);
        }
    }

    public function test_empty_prior_summary_is_filtered_and_prior_signature_is_preserved(): void
    {
        $first = $this->candidate('candidate-empty-summary', 'First body', '');
        $progress = UnfoldProgress::forUnits((new UnfoldUnitExtractor)->fromValues(['First unit', 'Second unit'], 2));
        $progress = $progress->accept($first->id, (new MemoryMerger)->commit(
            $progress->memory,
            new MemoryDelta,
            $progress->currentUnit(),
            $first,
        ));
        $plan = $this->strategy()->plan(
            $this->step(),
            $this->snapshot(state: $progress->toArray(), accepted: [$first]),
        );

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertStringContainsString('"must_not_repeat":[]', $plan->requests[0]->messages[1]->content);
        $distinctness = self::arrayValue($plan->requests[0]->metadata['content_distinctness'] ?? null);
        $priorSignatures = self::arrayValue($distinctness['prior_signatures'] ?? null);
        self::assertCount(1, $priorSignatures);
        self::assertSame(
            (new ContentDistinctness)->signature('First body'),
            $priorSignatures[0],
        );
    }

    public function test_manual_selection_stops_at_the_first_matching_candidate(): void
    {
        $first = $this->candidate('duplicate', 'First matching body', 'First');
        $second = $this->candidate('duplicate', 'Second matching body', 'Second');
        $progress = UnfoldProgress::forUnits((new UnfoldUnitExtractor)->fromValues(['Only unit'], 1))->awaitingJudge();
        $outcome = $this->strategy()->select(
            $this->step(judge: true),
            $this->snapshot(state: $progress->toArray(), current: [$first, $second]),
            CandidateId::fromString('duplicate'),
        );

        self::assertSame('First matching body', $outcome->rawOutput);
        self::assertSame('First matching body', $outcome->artifacts[0]->content);
    }

    public function test_generation_defaults_optional_maps_and_rejects_every_wrong_field_type(): void
    {
        $run = $this->snapshot(payload: ['Only unit']);
        $minimal = new CompletionResponse(
            text: 'fallback content',
            structured: ['title' => 'Title', 'summary' => 'Summary'],
        );
        $outcome = $this->strategy(['minimal'])->reduce($this->step(), $run, [$this->outcome($minimal)]);
        self::assertSame('fallback content', $outcome->candidates[0]->content);
        self::assertSame([], $outcome->candidates[0]->payload);
        self::assertSame([], $outcome->candidates[0]->metadata['memory_delta']);

        $valid = [
            'title' => 'Title',
            'summary' => 'Summary',
            'payload' => [],
            'content' => 'Content',
            'memory_delta' => [],
        ];
        foreach ([
            'title' => 1,
            'summary' => 1,
            'payload' => 'invalid',
            'content' => 1,
            'memory_delta' => 'invalid',
        ] as $field => $invalid) {
            try {
                $strategy = $this->strategy(["invalid-{$field}"]);
                $strategy->reduce($this->step(), $run, [
                    $this->outcome(new CompletionResponse(structured: array_replace($valid, [$field => $invalid]))),
                ]);
                self::fail("Invalid {$field} was accepted.");
            } catch (LogicException $error) {
                self::assertSame(
                    in_array($field, ['payload', 'memory_delta'], true)
                        ? "UNFOLD response field [{$field}] must be an object."
                        : "UNFOLD response field [{$field}] must be a string.",
                    $error->getMessage(),
                );
            }
        }
    }

    public function test_missing_persisted_candidate_empty_output_and_absent_memory_delta_fail_or_default_exactly(): void
    {
        $first = $this->candidate('persisted', 'First', 'Summary');
        $progress = UnfoldProgress::forUnits((new UnfoldUnitExtractor)->fromValues(['First', 'Second'], 2));
        $progress = $progress->accept($first->id, (new MemoryMerger)->commit(
            $progress->memory,
            new MemoryDelta,
            $progress->currentUnit(),
            $first,
        ));
        try {
            $this->strategy()->plan($this->step(), $this->snapshot(state: $progress->toArray()));
            self::fail('Missing persisted selected candidate was accepted.');
        } catch (LogicException $error) {
            self::assertSame('Persisted UNFOLD candidate [persisted] was not found.', $error->getMessage());
        }

        try {
            $this->strategy(['blank'])->reduce(
                $this->step(),
                $this->snapshot(payload: ['Only']),
                [$this->outcome($this->candidateResponse('Blank', '', '   '))],
            );
            self::fail('Empty assembled output was accepted.');
        } catch (LogicException $error) {
            self::assertSame('UNFOLD assembled an empty output.', $error->getMessage());
        }

        $withoutDelta = new Candidate(
            CandidateId::fromString('without-delta'),
            StepId::fromString('unfold'),
            ArtifactType::fromString('section'),
            'No delta',
            'Summary',
            [],
            'Selected body',
            'without-delta',
            'test candidate',
        );
        $judge = UnfoldProgress::forUnits((new UnfoldUnitExtractor)->fromValues(['Only'], 1))->awaitingJudge();
        $selected = $this->strategy()->select(
            $this->step(judge: true),
            $this->snapshot(state: $judge->toArray(), current: [$withoutDelta]),
            $withoutDelta->id,
        );
        $selectedState = self::arrayValue($selected->stepState);
        $selectedMemory = self::arrayValue($selectedState['memory'] ?? null);
        self::assertSame(1, $selectedMemory['version']);
        self::assertSame([], $selectedMemory['facts']);
        self::assertSame('Selected body', $selected->rawOutput);
    }

    public function test_final_assembly_removes_an_empty_prior_part_without_leading_separator(): void
    {
        $blank = $this->candidate('blank-prior', '   ', 'Blank');
        $progress = UnfoldProgress::forUnits((new UnfoldUnitExtractor)->fromValues(['First', 'Second'], 2));
        $progress = $progress->accept($blank->id, (new MemoryMerger)->commit(
            $progress->memory,
            new MemoryDelta,
            $progress->currentUnit(),
            $blank,
        ));
        $outcome = $this->strategy(['non-empty'])->reduce(
            $this->step(),
            $this->snapshot(state: $progress->toArray(), accepted: [$blank]),
            [$this->outcome($this->candidateResponse('Second', 'Summary', 'Second body'))],
        );

        self::assertSame('Second body', $outcome->rawOutput);
        self::assertSame('Second body', $outcome->artifacts[0]->content);
        self::assertSame(11, $outcome->metadata['assembled_characters']);
    }

    /** @param list<string> $ids */
    private function strategy(array $ids = ['candidate-default']): UnfoldStrategy
    {
        $generator = new class($ids) implements IdGeneratorBase
        {
            private int $index = 0;

            /** @param list<string> $ids */
            public function __construct(private readonly array $ids) {}

            public function generate(): string
            {
                return $this->ids[$this->index++] ?? 'candidate-overflow';
            }
        };

        return new UnfoldStrategy(
            $generator,
            new UnfoldUnitExtractor,
            new MemoryMerger,
            $this->application()->make(CompletionRequestFactory::class),
            new ContentDistinctness,
            new UnfoldCandidateSchema,
        );
    }

    private function step(
        int $candidateCount = 1,
        bool $judge = false,
        int $maxUnits = 2,
        string $policy = 'default',
    ): UnfoldStep {
        return new UnfoldStep(
            StepId::fromString('unfold'),
            ArtifactType::fromString('source'),
            ArtifactType::fromString('section'),
            $candidateCount,
            $judge,
            $maxUnits,
            $policy,
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, mixed>  $state
     * @param  list<Candidate>  $accepted
     * @param  list<Candidate>  $current
     */
    private function snapshot(
        string $content = 'Source',
        array $payload = [],
        array $state = [],
        array $accepted = [],
        array $current = [],
    ): WorkflowRunSnapshot {
        return new WorkflowRunSnapshot(
            RunId::fromString('unfold-run'),
            RunStatus::Running,
            1,
            new RunInput([]),
            'Expand source',
            DefinitionOfDone::fromString('Every unit is expanded'),
            [],
            $current,
            $accepted,
            [],
            $state === [] ? [] : ['unfold' => $state],
            null,
            null,
            0,
            10,
            ['source' => new Artifact('source', ArtifactType::fromString('source'), $content, $payload)],
        );
    }

    /**
     * @param  list<string>  $facts
     * @param  list<string>  $covered
     */
    private function candidateResponse(
        string $title,
        string $summary,
        string $content,
        array $facts = [],
        array $covered = [],
    ): CompletionResponse {
        return new CompletionResponse(structured: [
            'title' => $title,
            'summary' => $summary,
            'payload' => ['kind' => 'section'],
            'content' => $content,
            'memory_delta' => [
                'facts_added' => $facts,
                'decisions_added' => [],
                'loops_opened' => [],
                'loops_resolved' => [],
                'requirements_covered' => $covered,
                'requirements_violated' => [],
            ],
        ], provider: 'fake', model: 'fake-unfold');
    }

    /** @return array<mixed> */
    private static function arrayValue(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private function candidate(string $id, string $content, string $summary): Candidate
    {
        return new Candidate(
            CandidateId::fromString($id),
            StepId::fromString('unfold'),
            ArtifactType::fromString('section'),
            $id,
            $summary,
            [],
            $content,
            $id,
            'test candidate',
            ['memory_delta' => []],
        );
    }

    private function outcome(CompletionResponse $response): InvocationOutcome
    {
        return new InvocationOutcome(
            InvocationId::fromString('unfold-invocation'),
            0,
            1,
            InvocationStatus::Succeeded,
            $response,
            null,
            null,
        );
    }
}
