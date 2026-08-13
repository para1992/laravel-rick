<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Event\StepCompleted;
use Rick\Laravel\Domain\Event\WorkflowCompleted;
use Rick\Laravel\Domain\Event\WorkflowCreated;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\StepExecutionStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Memory\UnitCard;
use Rick\Laravel\Domain\Memory\WorkingMemory;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class ExecutionDomainTest extends TestCase
{
    public function test_workflow_run_keeps_core_transition_and_event_order(): void
    {
        $stepId = StepId::fromString('step-1');
        $run = WorkflowRun::start(
            RunId::fromString('run-1'),
            new CompiledWorkflow('workflow', '1.0.0', [
                new ResolveStep($stepId, 'Task', DefinitionOfDone::automatic()),
            ]),
            new RunInput([]),
            10,
            new DateTimeImmutable('2026-07-24T12:00:00+00:00'),
        );

        self::assertInstanceOf(WorkflowCreated::class, $run->releaseEvents()[0]);

        $run->beginStep($stepId);
        $run->releaseEvents();
        $run->completeStep($stepId, StepOutcome::resolved(
            'Done',
            DefinitionOfDone::automatic(),
        ));

        $events = $run->releaseEvents();

        self::assertSame(3, $run->version());
        self::assertSame(RunStatus::Completed, $run->snapshot()->status);
        self::assertInstanceOf(StepCompleted::class, $events[0]);
        self::assertInstanceOf(WorkflowCompleted::class, $events[1]);
    }

    public function test_step_execution_keeps_core_batch_transition_counters(): void
    {
        $execution = StepExecution::waiting(
            StepExecutionId::fromString('execution-1'),
            RunId::fromString('run-1'),
            StepId::fromString('step-1'),
            2,
        );

        $execution->markDispatched(2);
        $execution->beginReduction();
        $execution->continueAfterReduction();
        $execution->beginNextBatch(1);

        self::assertSame(StepExecutionStatus::Waiting, $execution->status());
        self::assertSame(1, $execution->expectedInvocations());
        self::assertSame(0, $execution->dispatchedInvocations());
        self::assertSame(4, $execution->version());
    }

    public function test_llm_invocation_keeps_core_retry_and_lease_transitions(): void
    {
        $invocation = LlmInvocation::pending(
            InvocationId::fromString('invocation-1'),
            StepExecutionId::fromString('execution-1'),
            RunId::fromString('run-1'),
            StepId::fromString('step-1'),
            0,
            new CompletionRequest(
                [new Message('user', 'Complete the task.')],
                ResponseContract::Text,
                'test',
            ),
        );
        $lease = new DateTimeImmutable('2026-07-24T12:05:00+00:00');

        $invocation->start($lease);
        $invocation->release('retryable_provider_error', 'Try again.');
        $invocation->start($lease);
        $invocation->succeed(new CompletionResponse(text: 'Done.'));

        self::assertSame('succeeded', $invocation->status()->value);
        self::assertSame(2, $invocation->attempts());
        self::assertSame(4, $invocation->version());
        self::assertNull($invocation->leaseExpiresAt());
    }

    public function test_ambiguous_invocation_has_a_terminal_attempt_state_without_becoming_failed(): void
    {
        $invocation = LlmInvocation::pending(
            InvocationId::fromString('invocation-ambiguous'),
            StepExecutionId::fromString('execution-ambiguous'),
            RunId::fromString('run-ambiguous'),
            StepId::fromString('step-ambiguous'),
            0,
            new CompletionRequest(
                [new Message('user', 'Complete the paid task.')],
                ResponseContract::Text,
                'test',
            ),
        );

        $invocation->start();
        $invocation->markIndeterminate('provider_outcome_indeterminate', 'Unknown outcome.');

        self::assertSame('indeterminate', $invocation->status()->value);
        self::assertSame(1, $invocation->attempts());
        self::assertNull($invocation->leaseExpiresAt());
    }

    public function test_provider_identifiers_keep_gateway_and_provider_semantics_separate(): void
    {
        $identifiers = ProviderIdentifiers::fromMetadata([
            'gateway_invocation_id' => '019fbdd1-ab7c-73c6-bbe7-6f0e223b2c44',
            'provider_request_id' => 'request-123',
            'provider_generation_id' => 'gen-456',
            'provider_id_source' => 'body',
        ]);

        self::assertSame(
            '019fbdd1-ab7c-73c6-bbe7-6f0e223b2c44',
            $identifiers->gatewayInvocationId,
        );
        self::assertSame('request-123', $identifiers->providerRequestId);
        self::assertSame('gen-456', $identifiers->providerGenerationId);
        self::assertSame(ProviderIdSource::Body, $identifiers->source);

        $unsafe = ProviderIdentifiers::fromMetadata([
            'provider_request_id' => "request\nsecret",
            'provider_id_source' => 'body',
        ]);
        self::assertNull($unsafe->providerRequestId);
        self::assertSame(ProviderIdSource::Unavailable, $unsafe->source);
    }

    public function test_working_memory_round_trips_and_hashes_deterministically(): void
    {
        $memory = new WorkingMemory(
            version: 1,
            facts: ['Fact'],
            unitCards: [
                new UnitCard(
                    'unit-1',
                    1,
                    'Summary',
                    ['Requirement'],
                    ['Fact'],
                    ['Decision'],
                    [],
                    'Next',
                    'hash',
                ),
            ],
        );
        $restored = WorkingMemory::fromArray($memory->toArray());

        self::assertSame($memory->toArray(), $restored->toArray());
        self::assertSame($memory->hash(), $restored->hash());
    }
}
