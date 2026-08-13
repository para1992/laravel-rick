<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\RecoverRunRequest;
use Rick\Laravel\Application\Execution\Result\RecoverRunResult;
use Rick\Laravel\Application\Execution\Support\Dispatch\InvocationDispatch;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationDefinition;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\PromptTemplate;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\TemplateLlmOperation;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunObservation;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class TerminalRunRecoveryTest extends TestCase
{
    public function test_retry_failed_reuses_success_and_queues_only_failed_slot_idempotently(): void
    {
        $phase = 'parent';
        $gateway = new class($phase) implements GatewayBase
        {
            public int $calls = 0;

            /** @var list<int> */
            public array $indices = [];

            public function __construct(public string $phase) {}

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->calls++;
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                );
                $this->indices[] = $index;

                return new CompletionResponse(
                    structured: $this->phase === 'parent' && $index === 1
                        ? ['wrong' => 'failed-second-slot']
                        : ['content' => 'Candidate '.($index + 1).' '.$this->phase],
                    provider: 'fake',
                    model: 'fake-model',
                    metrics: new CompletionMetrics(new TokenUsage(2, 3)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $parent = $rick->run($rick->workflow('terminal-recovery-retry')
            ->resolve('Generate two candidates', 'Both candidates are available')
            ->plan(candidates: 2)
            ->manualJudge()
            ->build());

        self::assertSame(RunStatus::Failed, $parent->status);
        self::assertSame(2, $gateway->calls);
        $parentVersion = $parent->version;
        $handler = $this->application()->make(Handler::class);
        $childId = RunId::fromString('00000000-0000-4000-8000-000000000101');
        $gateway->phase = 'recovery';
        $result = $this->recover($handler, $parent->id, $childId, RunRecoveryAction::RetryFailed);

        self::assertSame(1, $result->reusedInvocations);
        self::assertSame(1, $result->queuedInvocations);
        self::assertSame(0, $result->copiedFailures);
        self::assertFalse($result->alreadyExists);
        self::assertSame(1, $result->run->callsUsed);
        self::assertNotNull($result->run->recovery);
        self::assertSame($parent->id->toString(), $result->run->recovery->parentRunId->toString());
        self::assertSame(RunRecoveryAction::RetryFailed, $result->run->recovery->action);

        $metrics = $rick->metrics($childId);
        self::assertSame(2, $metrics->totals->calls);
        self::assertSame(2, $metrics->totals->succeededCalls);
        self::assertSame(0, $metrics->totals->pendingCalls);
        self::assertSame(1, $metrics->totals->providerRequests);
        self::assertSame(5, $metrics->totals->tokens->totalTokens);
        $reused = array_values(array_filter(
            $metrics->invocations,
            static fn ($invocation): bool => $invocation->sourceInvocationId !== null,
        ));
        self::assertCount(1, $reused);
        self::assertTrue($reused[0]->toArray()['reused']);
        self::assertSame(2, $reused[0]->toArray()['schema_version']);
        self::assertSame($parent->id->toString(), $reused[0]->sourceRunId?->toString());

        $second = $this->recover(
            $handler,
            $parent->id,
            RunId::fromString('00000000-0000-4000-8000-000000000102'),
            RunRecoveryAction::RetryFailed,
        );
        self::assertTrue($second->alreadyExists);
        self::assertSame($childId->toString(), $second->run->id->toString());

        $waiting = $rick->snapshot($childId);
        self::assertSame(RunStatus::AwaitingInput, $waiting->status);
        self::assertSame(1, $waiting->callsUsed);
        self::assertSame(3, $gateway->calls);
        self::assertSame([0, 1, 1], $gateway->indices);
        self::assertSame(
            ['Candidate 1', 'Candidate 2'],
            array_map(static fn ($candidate): string => $candidate->title, $rick->pendingReview($childId)->candidates),
        );
        self::assertSame(RunStatus::Failed, $rick->snapshot($parent->id)->status);
        self::assertSame($parentVersion, $rick->snapshot($parent->id)->version);

        $timeline = $rick->timeline($childId)->observations;
        $recoveryEvents = array_values(array_filter(
            $timeline,
            static fn (RunObservation $observation): bool => $observation->type === 'run.recovery.started',
        ));
        self::assertCount(1, $recoveryEvents);
        self::assertSame($parent->id->toString(), $recoveryEvents[0]->details['parent_run_id']);
        self::assertSame('retry_failed', $recoveryEvents[0]->details['action']);
        self::assertTrue((bool) array_values(array_filter(
            $timeline,
            static fn (RunObservation $observation): bool => ($observation->details['reused'] ?? false) === true,
        )));

        $summaries = $rick->runs(limit: 10)->runs;
        $childSummary = array_values(array_filter(
            $summaries,
            static fn ($summary): bool => $summary->id->toString() === $childId->toString(),
        ));
        self::assertCount(1, $childSummary);
        self::assertSame($parent->id->toString(), $childSummary[0]->recovery?->parentRunId->toString());

        $forkId = RunId::fromString('00000000-0000-4000-8000-000000000103');
        $fork = $this->recover($handler, $parent->id, $forkId, RunRecoveryAction::ForkFailedStep);
        self::assertSame(0, $fork->reusedInvocations);
        self::assertSame(2, $fork->queuedInvocations);
        self::assertSame(2, $fork->run->callsUsed);
        self::assertSame(RunStatus::AwaitingInput, $rick->snapshot($forkId)->status);
        self::assertSame(2, $rick->metrics($forkId)->totals->providerRequests);
        self::assertSame(5, $gateway->calls);
        $forkIndices = array_slice($gateway->indices, -2);
        sort($forkIndices);
        self::assertSame([0, 1], $forkIndices);
    }

    public function test_continue_successful_reduces_without_another_provider_request(): void
    {
        $gateway = new class implements GatewayBase
        {
            public int $calls = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                );
                $this->calls++;

                return new CompletionResponse(
                    structured: $index === 1
                        ? ['wrong' => 'failed-second-slot']
                        : ['content' => 'Only valid candidate'],
                    provider: 'fake',
                    model: 'fake-model',
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $parent = $rick->run($rick->workflow('terminal-recovery-continue')
            ->resolve('Generate two candidates', 'Use a valid candidate')
            ->plan(candidates: 2)
            ->manualJudge()
            ->build());
        self::assertSame(RunStatus::Failed, $parent->status);

        $handler = $this->application()->make(Handler::class);
        $childId = RunId::fromString('00000000-0000-4000-8000-000000000201');
        $result = $this->recover(
            $handler,
            $parent->id,
            $childId,
            RunRecoveryAction::ContinueSuccessful,
        );
        self::assertSame(1, $result->reusedInvocations);
        self::assertSame(0, $result->queuedInvocations);
        self::assertSame(1, $result->copiedFailures);
        self::assertSame(0, $result->run->callsUsed);
        self::assertSame(2, $gateway->calls);
        self::assertSame(RunStatus::AwaitingInput, $rick->snapshot($childId)->status);
        self::assertCount(1, $rick->pendingReview($childId)->candidates);
        self::assertSame(0, $rick->metrics($childId)->totals->providerRequests);
        $degraded = array_values(array_filter(
            $rick->timeline($childId)->observations,
            static fn (RunObservation $observation): bool => $observation->type === 'step.degraded',
        ));
        self::assertCount(1, $degraded);
        self::assertSame(2, $degraded[0]->details['expected']);
        self::assertSame(1, $degraded[0]->details['succeeded']);
    }

    public function test_retry_failed_dispatches_failed_and_previously_undispatched_slots(): void
    {
        $this->application()->instance(InvocationDispatch::class, new InvocationDispatch(1));
        $gateway = new class implements GatewayBase
        {
            public string $phase = 'parent';

            /** @var list<int> */
            public array $indices = [];

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                );
                $this->indices[] = $index;

                return new CompletionResponse(
                    structured: $this->phase === 'parent'
                        ? ['wrong' => 'first-window-failure']
                        : ['content' => 'Recovered candidate '.($index + 1)],
                    provider: 'fake',
                    model: 'fake-model',
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $parent = $rick->run($rick->workflow('terminal-recovery-undispatched-retry')
            ->resolve('Generate three candidates', 'All candidates are available')
            ->plan(candidates: 3)
            ->manualJudge()
            ->build());

        self::assertSame(RunStatus::Failed, $parent->status);
        self::assertSame(1, $rick->metrics($parent->id)->totals->failedCalls);
        self::assertSame(2, $rick->metrics($parent->id)->totals->pendingCalls);
        self::assertSame([0], $gateway->indices);

        $childId = RunId::fromString('00000000-0000-4000-8000-000000000301');
        $gateway->phase = 'recovery';
        $result = $this->recover(
            $this->application()->make(Handler::class),
            $parent->id,
            $childId,
            RunRecoveryAction::RetryFailed,
        );

        self::assertSame(0, $result->reusedInvocations);
        self::assertSame(3, $result->queuedInvocations);
        self::assertSame(3, $result->run->callsUsed);
        self::assertSame(RunStatus::AwaitingInput, $rick->snapshot($childId)->status);
        self::assertCount(3, $rick->pendingReview($childId)->candidates);
        self::assertSame(0, $gateway->indices[0]);
        $recoveryIndices = array_slice($gateway->indices, 1);
        sort($recoveryIndices);
        self::assertSame([0, 1, 2], $recoveryIndices);
    }

    public function test_continue_successful_audits_undispatched_slots_without_marking_failures_reused(): void
    {
        $this->application()->instance(InvocationDispatch::class, new InvocationDispatch(2));
        $gateway = new class implements GatewayBase
        {
            public int $calls = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                );
                $this->calls++;

                return new CompletionResponse(
                    structured: $index === 0
                        ? ['content' => 'Only dispatched success']
                        : ['wrong' => 'dispatched failure'],
                    provider: 'fake',
                    model: 'fake-model',
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $parent = $rick->run($rick->workflow('terminal-recovery-undispatched-continue')
            ->resolve('Generate three candidates', 'One candidate is enough for operator recovery')
            ->plan(candidates: 3)
            ->manualJudge()
            ->build());

        self::assertSame(RunStatus::Failed, $parent->status);
        self::assertSame(2, $gateway->calls);

        $childId = RunId::fromString('00000000-0000-4000-8000-000000000302');
        $result = $this->recover(
            $this->application()->make(Handler::class),
            $parent->id,
            $childId,
            RunRecoveryAction::ContinueSuccessful,
        );

        self::assertSame(1, $result->reusedInvocations);
        self::assertSame(0, $result->queuedInvocations);
        self::assertSame(2, $result->copiedFailures);
        self::assertSame(2, $gateway->calls);
        self::assertCount(1, $rick->pendingReview($childId)->candidates);

        $serialized = array_map(
            static fn ($invocation): array => $invocation->toArray(),
            $rick->metrics($childId)->invocations,
        );
        $reused = array_values(array_filter(
            $serialized,
            static fn (array $invocation): bool => ($invocation['reused'] ?? false) === true,
        ));
        $copied = array_values(array_filter(
            $serialized,
            static fn (array $invocation): bool => $invocation['status'] === 'failed',
        ));
        self::assertCount(1, $reused);
        self::assertCount(2, $copied);
        foreach ($copied as $invocation) {
            self::assertFalse($invocation['reused']);
            self::assertSame($parent->id->toString(), $invocation['source_run_id']);
            self::assertIsString($invocation['source_invocation_id']);
        }
    }

    public function test_recovery_uses_persisted_requests_when_operation_planning_changes(): void
    {
        $operations = $this->application()->make(LlmOperationRegistry::class);
        $definition = $operations->get('rick.text')->definition();
        $gateway = new class implements GatewayBase
        {
            public string $phase = 'parent';

            /** @var list<string> */
            public array $systems = [];

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->systems[] = $request->messages[0]->content;
                if ($this->phase === 'parent') {
                    throw new ProviderRequestException(
                        'provider_rejected',
                        'Provider rejected the original request.',
                        false,
                        ProviderRequestOutcome::NotAccepted,
                    );
                }

                return new CompletionResponse(
                    text: 'Recovered output',
                    provider: 'fake',
                    model: 'fake-model',
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $parent = $rick->run($rick->workflow('terminal-recovery-request-drift')
            ->resolve('Produce one operation result', 'The result is available')
            ->operation('rick.text', 'result', version: $definition->version)
            ->build());

        self::assertSame(RunStatus::Failed, $parent->status);
        self::assertSame([$definition->prompt->system], $gateway->systems);

        $changedSystem = 'Changed system prompt that must not replace the persisted request.';
        $operations->register(new TemplateLlmOperation(new LlmOperationDefinition(
            $definition->id,
            $definition->version,
            new PromptTemplate(
                $changedSystem,
                $definition->prompt->instruction,
                $definition->prompt->outputSchema,
            ),
            $definition->responseContract,
            $definition->outputType,
            $definition->model,
            $definition->validatorSetIds,
        )));
        $gateway->phase = 'recovery';
        $childId = RunId::fromString('00000000-0000-4000-8000-000000000303');
        $result = $this->recover(
            $this->application()->make(Handler::class),
            $parent->id,
            $childId,
            RunRecoveryAction::RetryFailed,
        );

        self::assertSame(1, $result->queuedInvocations);
        self::assertSame(RunStatus::Completed, $rick->snapshot($childId)->status);
        self::assertSame(
            [$definition->prompt->system, $definition->prompt->system],
            $gateway->systems,
        );
        self::assertNotContains($changedSystem, $gateway->systems);
    }

    public function test_retry_failed_restarts_a_strategy_level_failed_step_with_fresh_invocations(): void
    {
        $gateway = new class implements GatewayBase
        {
            public string $phase = 'parent';

            public int $calls = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->calls++;

                return new CompletionResponse(
                    structured: [
                        // Deliberately inconsistent in recovery: claim-level
                        // verdicts are authoritative and verified exactly.
                        'passed' => false,
                        'claims' => [[
                            'unit_id' => 'unit-00001',
                            'claim' => 'Grounded target sentence.',
                            'source_quote' => 'Grounded target sentence.',
                            'verdict' => $this->phase === 'parent' ? 'unsupported' : 'supported',
                            'evidence' => $this->phase === 'parent' ? [] : [[
                                'artifact_key' => 'evidence',
                                'quote' => 'Grounded target sentence.',
                            ]],
                        ]],
                    ],
                    provider: 'fake',
                    model: 'fake-model',
                    metrics: new CompletionMetrics(new TokenUsage(2, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $parent = $rick->run($rick->workflow('terminal-strategy-recovery')
            ->resolve('Verify one sentence', 'The sentence is grounded')
            ->context('target')
            ->context('evidence')
            ->groundedVerify(
                'target',
                ['evidence'],
                maxRepairs: 0,
                output: 'verified',
                minimumQuoteCharacters: 7,
            )
            ->outputGlue('verified')
            ->build(), [
                'target' => 'Grounded target sentence.',
                'evidence' => 'Grounded target sentence.',
            ]);

        self::assertSame(RunStatus::Failed, $parent->status);
        self::assertSame('failed', $parent->stepStates['004_grounded_verify']['phase'] ?? null);
        self::assertSame(1, $gateway->calls);

        $gateway->phase = 'recovery';
        $childId = RunId::fromString('00000000-0000-4000-8000-000000000305');
        $result = $this->recover(
            $this->application()->make(Handler::class),
            $parent->id,
            $childId,
            RunRecoveryAction::RetryFailed,
        );

        self::assertSame(0, $result->reusedInvocations);
        self::assertSame(1, $result->queuedInvocations);
        self::assertSame(2, $gateway->calls);
        $child = $rick->snapshot($childId);
        self::assertSame(RunStatus::Completed, $child->status);
        self::assertSame('Grounded target sentence.', $child->output());
        self::assertSame('passed', $child->stepStates['004_grounded_verify']['phase'] ?? null);
    }

    public function test_lost_idempotency_race_reads_the_winner_after_the_transaction(): void
    {
        $inner = $this->application()->make(RunRepositoryBase::class);
        $database = $this->application()->make(ConnectionInterface::class);
        $runs = new class($inner, $database) implements RunRepositoryBase
        {
            public bool $simulateLostRace = false;

            private bool $lostRace = false;

            public function __construct(
                private RunRepositoryBase $inner,
                private ConnectionInterface $database,
            ) {}

            public function add(WorkflowRun $run): void
            {
                $this->inner->add($run);
            }

            public function addRecovery(WorkflowRun $run): bool
            {
                if (! $this->simulateLostRace) {
                    return $this->inner->addRecovery($run);
                }
                if (! $this->inner->addRecovery($run)) {
                    throw new \LogicException('The simulated recovery winner was not inserted.');
                }
                $this->lostRace = true;

                return false;
            }

            public function findRecovery(RunId $parentRunId, RunRecoveryAction $action): ?WorkflowRun
            {
                if ($this->lostRace && $this->database->transactionLevel() > 0) {
                    return null;
                }

                return $this->inner->findRecovery($parentRunId, $action);
            }

            public function get(RunId $id): WorkflowRun
            {
                return $this->inner->get($id);
            }

            public function save(WorkflowRun $run, int $expectedVersion): void
            {
                $this->inner->save($run, $expectedVersion);
            }
        };
        $this->application()->instance(RunRepositoryBase::class, $runs);
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                return new CompletionResponse(
                    structured: ['wrong' => 'terminal-parent'],
                    provider: 'fake',
                    model: 'fake-model',
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $parent = $rick->run($rick->workflow('terminal-recovery-race')
            ->resolve('Generate one candidate', 'The candidate is available')
            ->plan(candidates: 1)
            ->build());
        self::assertSame(RunStatus::Failed, $parent->status);

        $runs->simulateLostRace = true;
        $childId = RunId::fromString('00000000-0000-4000-8000-000000000304');
        $result = $this->recover(
            $this->application()->make(Handler::class),
            $parent->id,
            $childId,
            RunRecoveryAction::RetryFailed,
        );

        self::assertTrue($result->alreadyExists);
        self::assertSame($childId->toString(), $result->run->id->toString());
        self::assertSame(0, $database->transactionLevel());
        self::assertNotNull($inner->findRecovery($parent->id, RunRecoveryAction::RetryFailed));
    }

    private function recover(
        Handler $handler,
        RunId $parentRunId,
        RunId $childRunId,
        RunRecoveryAction $action,
    ): RecoverRunResult {
        return $handler->handle(Parcel::fromArray([new RecoverRunRequest(
            $parentRunId,
            $childRunId,
            $action,
        )]))->get(RecoverRunResult::class);
    }
}
