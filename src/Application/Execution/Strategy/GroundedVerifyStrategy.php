<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use InvalidArgumentException;
use LogicException;
use Rick\Laravel\Application\Execution\Exception\GroundedVerificationFailedException;
use Rick\Laravel\Application\Execution\Support\Grounding\ExactQuoteVerifier;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingClaim;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingReport;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingResponseSchema;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingSegmenter;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingUnit;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\LlmOperationBase;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\OperationContext;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\GroundedVerifyStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class GroundedVerifyStrategy implements InvocationReductionBase, StepStrategyBase
{
    /** @var positive-int */
    private int $verificationBatchSize;

    /** @var non-negative-int */
    private int $maxVerificationRetries;

    /** @var positive-int */
    private int $verificationResponseAttempts;

    public function __construct(
        private LlmOperationRegistry $operations,
        private GroundingSegmenter $segmenter,
        private ExactQuoteVerifier $verifier,
        int $verificationBatchSize = 20,
        int $maxVerificationRetries = 2,
        int $verificationResponseAttempts = 2,
    ) {
        if ($verificationBatchSize < 1) {
            throw new InvalidArgumentException('Grounded verification batch size must be positive.');
        }
        if ($maxVerificationRetries < 0) {
            throw new InvalidArgumentException('Grounded verification retries must not be negative.');
        }
        if ($verificationResponseAttempts < 1) {
            throw new InvalidArgumentException('Grounded response attempts must be positive.');
        }
        $this->verificationBatchSize = $verificationBatchSize;
        $this->maxVerificationRetries = $maxVerificationRetries;
        $this->verificationResponseAttempts = $verificationResponseAttempts;
    }

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'grounded_verify';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        $step = $this->step($step);
        $state = $run->stepState($step->id()->toString());
        $phase = is_string($state['phase'] ?? null) ? $state['phase'] : 'verify';
        if ($phase === 'failed') {
            throw new GroundedVerificationFailedException(
                self::strings($state['violations'] ?? []),
            );
        }
        if ($phase === 'repair') {
            return new InvocationStepPlan(
                $this->repairOperation($step)->requests(
                    $this->context($step, $run, $state, [
                        'phase' => 'repair',
                        'violations' => self::strings($state['violations'] ?? []),
                        'reports' => self::repairReportSummaries($state['reports'] ?? []),
                    ]),
                ),
            );
        }

        $target = $this->currentArtifact($step, $run, $state);
        $requests = [];
        $batches = array_chunk($this->segmenter->units($target), $this->verificationBatchSize);
        $operation = $this->verificationOperation($step);
        $baseSchema = $operation->definition()->prompt->outputSchema
            ?? throw new LogicException('Grounded verification operation requires an output schema.');
        $evidenceKeys = array_keys($this->evidence($step, $run));
        foreach ($batches as $batchIndex => $batch) {
            $unitIds = array_map(
                static fn (GroundingUnit $unit): string => $unit->id,
                $batch,
            );
            $responseSchema = GroundingResponseSchema::forBatch(
                $baseSchema,
                $unitIds,
                $evidenceKeys,
            );
            $planned = $operation->requests(
                $this->context($step, $run, $state, [
                    'phase' => 'verify',
                    'batch_index' => $batchIndex,
                    'batch_count' => count($batches),
                    'units' => array_map(
                        static fn (GroundingUnit $unit): array => $unit->toArray(),
                        $batch,
                    ),
                    'previous_protocol_violations' => self::strings(
                        $state['protocol_violations'] ?? [],
                    ),
                ], $responseSchema, $this->verificationResponseAttempts),
            );
            if (count($planned) !== 1) {
                throw new LogicException(
                    'Each grounded-verification batch must create exactly one request.',
                );
            }
            $requests[] = $planned[0]->withMetadata([
                'grounding_batch' => $batchIndex,
                'grounding_unit_ids' => array_map(
                    static fn (GroundingUnit $unit): string => $unit->id,
                    $batch,
                ),
            ]);
        }

        return new InvocationStepPlan($requests);
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        $responses = InvocationResponses::successful($outcomes);
        $step = $this->step($step);
        $state = $run->stepState($step->id()->toString());

        return ($state['phase'] ?? 'verify') === 'repair'
            ? $this->reduceRepair($step, $run, $state, $responses)
            : $this->reduceVerification($step, $run, $state, $responses);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  non-empty-list<CompletionResponse>  $responses
     */
    private function reduceVerification(
        GroundedVerifyStep $step,
        WorkflowRunSnapshot $run,
        array $state,
        array $responses,
    ): StepOutcome {
        $target = $this->currentArtifact($step, $run, $state);
        $units = $this->segmenter->units($target);
        $expectedUnits = array_column(
            array_map(
                static fn (GroundingUnit $unit): array => [$unit->id, $unit->content],
                $units,
            ),
            1,
            0,
        );
        $claims = [];
        foreach ($responses as $response) {
            $structured = $response->structured;
            if (! is_array($structured)) {
                throw new LogicException('Grounded verification requires a structured response.');
            }
            $values = $structured['claims'] ?? null;
            if (! is_array($values)) {
                throw new LogicException('Grounded verification claims must be an array.');
            }
            foreach ($values as $value) {
                if (! is_array($value)) {
                    throw new LogicException('Grounded verification claim must be an object.');
                }
                $claim = GroundingClaim::fromArray(self::map($value));
                $claims[] = new GroundingClaim(
                    $claim->unitId,
                    $claim->claim,
                    $expectedUnits[trim($claim->unitId)] ?? $claim->sourceQuote,
                    $claim->verdict,
                    $claim->evidence,
                );
            }
        }
        $modelPassed = self::claimsDeclarePass($claims);

        $report = $this->verifier->verify(
            $target,
            $this->evidence($step, $run),
            $modelPassed,
            $claims,
            $step->minimumQuoteCharacters,
            $expectedUnits,
        );
        $reports = is_array($state['reports'] ?? null) ? $state['reports'] : [];
        $reports[] = $report->toArray();
        $repairs = is_int($state['repairs_used'] ?? null) ? $state['repairs_used'] : 0;
        $verificationRetries = is_int($state['verification_retries_used'] ?? null)
            ? $state['verification_retries_used']
            : 0;
        if ($report->passed) {
            $nextPhase = 'passed';
        } elseif ($report->protocolViolations !== []) {
            if ($verificationRetries < $this->maxVerificationRetries) {
                $nextPhase = 'verify';
                $verificationRetries++;
            } else {
                $nextPhase = $report->missingUnitIds === []
                    && $step->repairOperationId !== null
                    && $repairs < $step->maxRepairs
                        ? 'repair'
                        : 'failed';
            }
        } else {
            $nextPhase = $step->repairOperationId !== null && $repairs < $step->maxRepairs
                ? 'repair'
                : 'failed';
        }
        $nextState = [
            'phase' => $nextPhase,
            'repairs_used' => $repairs,
            'verification_retries_used' => $verificationRetries,
            'reports' => $reports,
            'violations' => $report->violations,
            'protocol_violations' => $report->protocolViolations,
        ];
        $artifacts = [$this->reportArtifact($step, $report)];
        if ($report->passed) {
            $artifacts[] = new Artifact(
                $step->resolvedOutputKey(),
                $target->type,
                $target->content,
                $target->payload,
                $target->metadata + ['verified_by' => $step->verificationOperationId],
            );

            return StepOutcome::completion(
                $nextState,
                metadata: [
                    'grounded_verification' => 'passed',
                    'repairs_used' => $repairs,
                    'verification_retries_used' => $verificationRetries,
                ],
                artifacts: $artifacts,
            );
        }

        return StepOutcome::continuation(
            $nextState,
            metadata: [
                'grounded_verification' => $nextPhase,
                'repairs_used' => $repairs,
                'verification_retries_used' => $verificationRetries,
            ],
            artifacts: $artifacts,
        );
    }

    /** @param list<GroundingClaim> $claims */
    private static function claimsDeclarePass(array $claims): bool
    {
        if ($claims === []) {
            return false;
        }

        foreach ($claims as $claim) {
            $verdict = strtolower(trim($claim->verdict));
            if (! in_array($verdict, ['supported', 'no_claims'], true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private static function repairReportSummaries(mixed $reports): array
    {
        if (! is_array($reports)) {
            return [];
        }

        $summaries = [];
        foreach ($reports as $report) {
            if (! is_array($report)) {
                continue;
            }
            $summaries[] = [
                'artifact_key' => is_string($report['artifact_key'] ?? null)
                    ? $report['artifact_key']
                    : null,
                'passed' => ($report['passed'] ?? false) === true,
                'violations' => self::strings($report['violations'] ?? []),
                'protocol_violations' => self::strings($report['protocol_violations'] ?? []),
                'content_violations' => self::strings($report['content_violations'] ?? []),
                'missing_unit_ids' => self::strings($report['missing_unit_ids'] ?? []),
            ];
        }

        return $summaries;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  non-empty-list<CompletionResponse>  $responses
     */
    private function reduceRepair(
        GroundedVerifyStep $step,
        WorkflowRunSnapshot $run,
        array $state,
        array $responses,
    ): StepOutcome {
        $operationId = $step->repairOperationId
            ?? throw new LogicException('Grounded repair has no configured operation.');
        $current = $this->currentArtifact($step, $run, $state);
        $produced = $this->repairOperation($step)
            ->reduce($this->context($step, $run, $state, ['phase' => 'repair']), $responses)
            ->artifacts[0];
        $repairs = (is_int($state['repairs_used'] ?? null) ? $state['repairs_used'] : 0) + 1;
        $repaired = new Artifact(
            $step->resolvedOutputKey(),
            $current->type,
            $produced->content,
            $produced->payload,
            array_replace($current->metadata, $produced->metadata, [
                'repaired_by' => $operationId,
                'grounding_repairs' => $repairs,
            ]),
        );

        return StepOutcome::continuation(
            [
                'phase' => 'verify',
                'repairs_used' => $repairs,
                'verification_retries_used' => is_int($state['verification_retries_used'] ?? null)
                    ? $state['verification_retries_used']
                    : 0,
                'reports' => is_array($state['reports'] ?? null) ? $state['reports'] : [],
                'violations' => self::strings($state['violations'] ?? []),
                'protocol_violations' => [],
            ],
            metadata: [
                'grounded_verification' => 'repaired',
                'repairs_used' => $repairs,
                'verification_retries_used' => is_int($state['verification_retries_used'] ?? null)
                    ? $state['verification_retries_used']
                    : 0,
            ],
            artifacts: [$repaired],
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $responseSchema
     */
    private function context(
        GroundedVerifyStep $step,
        WorkflowRunSnapshot $run,
        array $state,
        array $parameters,
        ?array $responseSchema = null,
        ?int $structuredResponseAttempts = null,
    ): OperationContext {
        $evidence = $this->evidence($step, $run);
        $target = $this->currentArtifact($step, $run, $state);

        return new OperationContext(
            $run,
            ['target' => $this->verificationTarget($target, $parameters)]
                + $evidence,
            $step->resolvedOutputKey(),
            [
                'target_input_key' => 'target',
                'evidence_artifact_keys' => array_keys($evidence),
            ] + $parameters,
            ($parameters['phase'] ?? null) === 'repair'
                ? (is_int($state['repairs_used'] ?? null) ? $state['repairs_used'] : 0) + 1
                : (is_int($state['verification_retries_used'] ?? null)
                    ? $state['verification_retries_used']
                    : 0) + 1,
            $responseSchema,
            $structuredResponseAttempts,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function verificationTarget(Artifact $target, array $parameters): Artifact
    {
        if (($parameters['phase'] ?? null) !== 'verify' || ! is_array($parameters['units'] ?? null)) {
            return $target;
        }

        $contents = [];
        foreach ($parameters['units'] as $unit) {
            if (is_array($unit) && is_string($unit['content'] ?? null)) {
                $contents[] = $unit['content'];
            }
        }
        if ($contents === []) {
            return $target;
        }

        return new Artifact(
            $target->key,
            $target->type,
            implode("\n", $contents),
            metadata: [
                'grounding_batch_view' => true,
                'source_version' => $target->version,
            ],
            version: $target->version,
        );
    }

    /** @return array<string, Artifact> */
    private function evidence(
        GroundedVerifyStep $step,
        WorkflowRunSnapshot $run,
    ): array {
        $evidence = [];
        foreach ($step->evidenceKeys as $key) {
            $evidence[$key] = $run->artifact($key);
        }

        return $evidence;
    }

    /** @param array<string, mixed> $state */
    private function currentArtifact(
        GroundedVerifyStep $step,
        WorkflowRunSnapshot $run,
        array $state,
    ): Artifact {
        $repairs = is_int($state['repairs_used'] ?? null) ? $state['repairs_used'] : 0;

        return $repairs > 0 && $run->hasArtifact($step->resolvedOutputKey())
            ? $run->artifact($step->resolvedOutputKey())
            : $run->artifact($step->artifactKey);
    }

    private function verificationOperation(
        GroundedVerifyStep $step,
    ): LlmOperationBase {
        return $this->operations->get(
            $step->verificationOperationId,
            $step->verificationOperationVersion,
        );
    }

    private function repairOperation(
        GroundedVerifyStep $step,
    ): LlmOperationBase {
        return $this->operations->get(
            $step->repairOperationId
                ?? throw new LogicException('Grounded repair has no configured operation.'),
            $step->repairOperationVersion,
        );
    }

    private function reportArtifact(
        GroundedVerifyStep $step,
        GroundingReport $report,
    ): Artifact {
        $data = $report->toArray();

        return new Artifact(
            $step->reportKey(),
            ArtifactType::fromString('verification_report'),
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $data,
        );
    }

    private function step(StepBase $step): GroundedVerifyStep
    {
        return $step instanceof GroundedVerifyStep
            ? $step
            : throw new LogicException(
                'Grounded-verification strategy received an incompatible step.',
            );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new LogicException('Grounded verification claim must be an object.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
