<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Event\CandidateReviewRequested;
use Rick\Laravel\Domain\Event\ExternalInputRequested;
use Rick\Laravel\Domain\Event\LlmCallReserved;
use Rick\Laravel\Domain\Event\MemoryCommitted;
use Rick\Laravel\Domain\Event\StepContinued;
use Rick\Laravel\Domain\Event\StepDegraded;
use Rick\Laravel\Domain\Event\StepFailed;
use Rick\Laravel\Domain\Event\StepStarted;
use Rick\Laravel\Domain\Event\WorkflowCompleted;
use Rick\Laravel\Domain\Event\WorkflowRecoveryStarted;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\CandidateDecision;
use Rick\Laravel\Domain\Run\ContextDocument;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Run\WorkflowRunState;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class WorkflowRunDomainTest extends TestCase
{
    public function test_start_begin_and_release_events_are_exact(): void
    {
        $startedAt = new DateTimeImmutable('2026-08-08T08:00:00+00:00');
        $run = $this->createRun(['step-1'], 5, $startedAt);

        self::assertSame('run-1', $run->id()->toString());
        self::assertSame(0, $run->version());
        self::assertNull($run->runningStepId());
        self::assertSame('step-1', $run->nextStep()?->id()->toString());
        self::assertSame($startedAt, $run->snapshot()->startedAt);
        self::assertCount(1, $run->releaseEvents());
        self::assertSame([], $run->releaseEvents());

        $run->beginStep(StepId::fromString('step-1'));
        $events = $run->releaseEvents();

        self::assertSame(1, $run->version());
        self::assertSame(RunStatus::Running, $run->snapshot()->status);
        self::assertSame('step-1', $run->runningStepId()?->toString());
        self::assertCount(1, $events);
        self::assertInstanceOf(StepStarted::class, $events[0]);
        self::assertSame('run-1', $events[0]->runId->toString());
        self::assertSame('step-1', $events[0]->stepId->toString());
        self::assertSame('resolve', $events[0]->stepType->toString());
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_runs_cannot_begin_a_step(RunStatus $status): void
    {
        $run = $this->restored($status, position: 0);

        $this->expectException(InvalidStateTransitionException::class);
        $run->beginStep(StepId::fromString('step-1'));
    }

    /** @return iterable<string, array{RunStatus}> */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [RunStatus::Completed];
        yield 'failed' => [RunStatus::Failed];
        yield 'cancelled' => [RunStatus::Cancelled];
    }

    public function test_begin_rejects_wrong_and_exhausted_steps(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        $this->createRun()->beginStep(StepId::fromString('wrong-step'));
    }

    public function test_begin_rejects_a_missing_next_step(): void
    {
        $run = $this->restored(RunStatus::Created, position: 1);

        $this->expectException(InvalidStateTransitionException::class);
        $run->beginStep(StepId::fromString('step-1'));
    }

    public function test_review_barrier_filters_context_and_exposes_candidate_ids(): void
    {
        $run = $this->running();
        $candidate = $this->candidate('candidate-1');
        $run->continueStep(StepId::fromString('step-1'), StepOutcome::continuation(
            ['phase' => 'review'],
            [$candidate],
            metadata: ['continued' => true],
        ));
        $run->releaseEvents();

        $run->awaitInput(StepId::fromString('step-1'), [
            'scope' => 'unit',
            'unit_index' => 2,
            'unit_number' => 3,
            'total_units' => 4,
            'secret' => 'must-not-leak',
        ]);
        $events = $run->releaseEvents();

        self::assertSame(RunStatus::AwaitingInput, $run->snapshot()->status);
        self::assertSame(3, $run->version());
        self::assertCount(1, $events);
        self::assertInstanceOf(CandidateReviewRequested::class, $events[0]);
        self::assertSame('unit', $events[0]->scope);
        self::assertSame(['candidate-1'], array_map(static fn (CandidateId $id): string => $id->toString(), $events[0]->candidateIds));
        self::assertSame([
            'scope' => 'unit',
            'unit_index' => 2,
            'unit_number' => 3,
            'total_units' => 4,
        ], $events[0]->context);
    }

    public function test_await_input_is_idempotent_for_the_same_barrier(): void
    {
        $run = $this->running();
        $candidate = $this->candidate('candidate-1');
        $run->continueStep(StepId::fromString('step-1'), StepOutcome::continuation([], [$candidate]));
        $run->releaseEvents();

        $run->awaitInput(StepId::fromString('step-1'), ['scope' => 'unit']);
        $versionAfterFirst = $run->version();
        $run->releaseEvents();

        $run->awaitInput(StepId::fromString('step-1'), ['scope' => 'unit']);

        self::assertSame(RunStatus::AwaitingInput, $run->snapshot()->status);
        self::assertSame($versionAfterFirst, $run->version());
        self::assertSame([], $run->releaseEvents());
    }

    public function test_review_barrier_uses_default_scope_for_non_string_metadata(): void
    {
        $run = $this->running();
        $run->releaseEvents();
        $run->awaitInput(StepId::fromString('step-1'), ['scope' => 42]);

        $event = $run->releaseEvents()[0];
        self::assertInstanceOf(CandidateReviewRequested::class, $event);
        self::assertSame('candidate', $event->scope);
        self::assertSame(['scope' => 42], $event->context);
    }

    public function test_external_input_barrier_resume_and_submission_are_exact(): void
    {
        $run = $this->running();
        $run->releaseEvents();
        $schema = ['type' => 'string', 'minLength' => 2];

        $run->awaitExternalInput(StepId::fromString('step-1'), 'answer', 'Give an answer', $schema);
        $event = $run->releaseEvents()[0];
        self::assertInstanceOf(ExternalInputRequested::class, $event);
        self::assertSame('answer', $event->key);
        self::assertSame('Give an answer', $event->prompt);
        self::assertSame($schema, $event->schema);
        self::assertSame(2, $run->version());

        $run->submitInput('answer', 'yes');
        $snapshot = $run->snapshot();
        self::assertSame(RunStatus::Completed, $snapshot->status);
        self::assertSame(['external_input' => ['key' => 'answer', 'value' => 'yes']], $snapshot->stepState('step-1'));
        self::assertSame(5, $snapshot->version);
    }

    public function test_resume_input_changes_only_the_barrier_state_and_version(): void
    {
        $run = $this->running();
        $run->awaitExternalInput(StepId::fromString('step-1'), 'key', 'Prompt');
        $run->releaseEvents();
        $run->resumeInput(StepId::fromString('step-1'));

        self::assertSame(RunStatus::Running, $run->snapshot()->status);
        self::assertSame(3, $run->version());
        self::assertSame([], $run->releaseEvents());
    }

    public function test_manual_candidate_selection_accepts_candidate_and_versions_artifact(): void
    {
        $run = $this->running();
        $candidate = $this->candidate('candidate-1', ['output_key' => 'draft.main']);
        $run->continueStep(StepId::fromString('step-1'), StepOutcome::continuation([], [$candidate]));
        $run->awaitInput(StepId::fromString('step-1'));
        $run->releaseEvents();

        $run->selectCandidate(CandidateId::fromString('candidate-1'));
        $snapshot = $run->snapshot();

        self::assertSame(RunStatus::Completed, $snapshot->status);
        self::assertSame([$candidate->toArray()], array_map(static fn (Candidate $item): array => $item->toArray(), $snapshot->acceptedCandidates));
        self::assertSame('candidate-1', $snapshot->decisions[0]->selectedCandidateId->toString());
        self::assertSame('manual', $snapshot->decisions[0]->policy);
        self::assertSame('Selected through external review.', $snapshot->decisions[0]->reason);
        self::assertSame('Candidate candidate-1', $snapshot->artifact('draft.main')->content);
        self::assertTrue($snapshot->hasArtifact('draft.main'));
        self::assertFalse($snapshot->hasArtifact('missing'));
    }

    public function test_continuation_applies_state_artifacts_and_memory_commit_once(): void
    {
        $run = $this->running();
        $candidate = $this->candidate('candidate-1');
        $run->continueStep(StepId::fromString('step-1'), StepOutcome::continuation([], [$candidate]));
        $run->releaseEvents();
        $decision = new CandidateDecision(
            StepId::fromString('step-1'),
            CandidateId::fromString('candidate-1'),
            91.5,
            'Best candidate',
        );
        $state = ['memory' => ['unit_cards' => [
            ['unit_id' => 'unit-first'],
            ['unit_id' => 'unit-last'],
        ]]];

        $run->continueStep(StepId::fromString('step-1'), StepOutcome::continuation(
            $state,
            decision: $decision,
            rawOutput: 'raw candidate',
            metadata: ['memory_version' => 7, 'memory_hash' => 'hash-7'],
            artifacts: [new Artifact('report', ArtifactType::fromString('text'), 'v1')],
        ));
        $events = $run->releaseEvents();

        self::assertSame(3, $run->version());
        self::assertSame('raw candidate', $run->snapshot()->rawOutput);
        self::assertSame($state, $run->snapshot()->stepState('step-1'));
        self::assertSame(1, $run->snapshot()->artifact('report')->version);
        self::assertCount(2, $events);
        self::assertInstanceOf(MemoryCommitted::class, $events[0]);
        self::assertSame('unit-last', $events[0]->unitId);
        self::assertSame(7, $events[0]->memoryVersion);
        self::assertSame('hash-7', $events[0]->memoryHash);
        self::assertInstanceOf(StepContinued::class, $events[1]);

        $run->completeStep(StepId::fromString('step-1'), StepOutcome::completion(
            [],
            artifacts: [new Artifact('report', ArtifactType::fromString('text'), 'v2')],
        ));
        self::assertSame(2, $run->snapshot()->artifact('report')->version);
    }

    public function test_context_and_edited_outputs_are_normalized_in_snapshot(): void
    {
        $run = $this->createRun(['step-1', 'step-2']);
        $context = new ContextDocument('source', 'Context', 10, 7, true);
        $run->releaseEvents();
        $run->beginStep(StepId::fromString('step-1'));
        $run->completeStep(StepId::fromString('step-1'), StepOutcome::contextsAdded([$context]));
        $run->beginStep(StepId::fromString('step-2'));
        $run->completeStep(StepId::fromString('step-2'), StepOutcome::edited('raw output', 'ai output'));

        $snapshot = $run->snapshot();
        self::assertSame('ai output', $snapshot->output());
        self::assertSame([$context->toArray()], array_map(static fn (ContextDocument $item): array => $item->toArray(), $snapshot->contexts));
        self::assertSame('raw output', $snapshot->rawOutput);
        self::assertSame('ai output', $snapshot->aiOutput);
        $completed = array_values(array_filter(
            $run->releaseEvents(),
            static fn (object $event): bool => $event instanceof WorkflowCompleted,
        ));
        self::assertCount(1, $completed);
        self::assertSame('ai output', $completed[0]->output);
    }

    public function test_degraded_step_sorts_unique_non_empty_failure_codes(): void
    {
        $run = $this->running();
        $run->releaseEvents();
        $run->recordDegradedStep(StepId::fromString('step-1'), 4, 2, ['zeta', '', 'alpha', 'zeta']);
        $event = $run->releaseEvents()[0];

        self::assertInstanceOf(StepDegraded::class, $event);
        self::assertSame(4, $event->expected);
        self::assertSame(2, $event->succeeded);
        self::assertSame(['alpha', 'zeta'], $event->failureCodes);
        self::assertSame(2, $run->version());
    }

    #[DataProvider('invalidDegradedCounts')]
    public function test_degraded_step_rejects_every_invalid_count(int $expected, int $succeeded): void
    {
        $run = $this->running();

        $this->expectException(InvalidArgumentException::class);
        $run->recordDegradedStep(StepId::fromString('step-1'), $expected, $succeeded, []);
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidDegradedCounts(): iterable
    {
        yield 'zero expected' => [0, 1];
        yield 'zero succeeded' => [2, 0];
        yield 'all succeeded' => [2, 2];
        yield 'too many succeeded' => [2, 3];
    }

    public function test_call_reservations_emit_ordered_events_and_increment_once_per_operation(): void
    {
        $run = $this->createRun(callLimit: 3);
        $run->releaseEvents();

        self::assertSame(1, $run->reserveCall('resolve'));
        $run->reserveCalls(['generate', 'judge']);
        $events = $run->releaseEvents();

        self::assertSame(2, $run->version());
        self::assertSame(3, $run->snapshot()->callsUsed);
        $calls = [];
        $purposes = [];
        $limits = [];
        foreach ($events as $event) {
            self::assertInstanceOf(LlmCallReserved::class, $event);
            $calls[] = $event->call;
            $purposes[] = $event->purpose;
            $limits[] = $event->limit;
        }
        self::assertSame([1, 2, 3], $calls);
        self::assertSame(['resolve', 'generate', 'judge'], $purposes);
        self::assertSame([3, 3, 3], $limits);
    }

    public function test_empty_call_reservation_batch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createRun()->reserveCalls([]);
    }

    public function test_failure_records_terminal_state_event_and_version(): void
    {
        $run = $this->running();
        $run->releaseEvents();
        $run->failStep(StepId::fromString('step-1'), 'provider_failed', 'Provider failed');
        $event = $run->releaseEvents()[0];

        self::assertSame(RunStatus::Failed, $run->snapshot()->status);
        self::assertNull($run->runningStepId());
        self::assertSame(2, $run->version());
        self::assertInstanceOf(StepFailed::class, $event);
        self::assertSame('provider_failed', $event->errorCode);
        self::assertSame('Provider failed', $event->message);
    }

    public function test_recovery_copies_parent_state_and_uses_explicit_start_time(): void
    {
        $parent = $this->running();
        $parent->continueStep(StepId::fromString('step-1'), StepOutcome::continuation(
            ['phase' => 'failed'],
            rawOutput: 'partial',
            artifacts: [new Artifact('partial', ArtifactType::fromString('text'), 'Partial')],
        ));
        $parent->failStep(StepId::fromString('step-1'), 'failed', 'Failed');
        $startedAt = new DateTimeImmutable('2026-08-08T09:00:00+00:00');

        $child = WorkflowRun::recover(
            RunId::fromString('run-child'),
            $parent,
            RunRecoveryAction::RetryFailed,
            6,
            $startedAt,
        );
        $event = array_values(array_filter(
            $child->releaseEvents(),
            static fn (object $item): bool => $item instanceof WorkflowRecoveryStarted,
        ))[0];

        self::assertSame('run-child', $child->id()->toString());
        self::assertSame($startedAt, $child->snapshot()->startedAt);
        self::assertSame('partial', $child->snapshot()->rawOutput);
        self::assertSame('Partial', $child->snapshot()->artifact('partial')->content);
        $recovery = $child->snapshot()->recovery;
        self::assertNotNull($recovery);
        self::assertSame('run-1', $recovery->parentRunId->toString());
        self::assertSame(RunRecoveryAction::RetryFailed, $recovery->action);
        self::assertSame('step-1', $recovery->stepId->toString());
        self::assertSame('run-child', $event->runId->toString());
        self::assertSame('run-1', $event->parentRunId->toString());
    }

    public function test_non_failed_run_cannot_create_recovery(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        WorkflowRun::recover(
            RunId::fromString('child'),
            $this->createRun(),
            RunRecoveryAction::RetryFailed,
            3,
        );
    }

    public function test_failed_run_without_remaining_step_cannot_create_recovery(): void
    {
        $parent = $this->restored(RunStatus::Failed, position: 1);

        $this->expectException(InvalidStateTransitionException::class);
        WorkflowRun::recover(
            RunId::fromString('child'),
            $parent,
            RunRecoveryAction::RetryFailed,
            3,
        );
    }

    public function test_restore_and_clone_preserve_state_without_releasing_old_events(): void
    {
        $run = $this->running();
        $run->reserveCall('resolve');
        $state = $run->state();
        $restored = WorkflowRun::restore($state);
        $clone = clone $run;

        self::assertSame($state->version, $restored->version());
        self::assertSame($state->callsUsed, $restored->snapshot()->callsUsed);
        self::assertSame($state->startedAt, $restored->snapshot()->startedAt);
        self::assertSame([], $restored->releaseEvents());
        self::assertSame([], $clone->releaseEvents());
        self::assertSame($run->snapshot()->toArray(), $clone->snapshot()->toArray());
        $clone->reserveCall('clone-only');
        self::assertSame(1, $run->snapshot()->callsUsed);
        self::assertSame(2, $clone->snapshot()->callsUsed);
    }

    public function test_snapshot_serializes_nested_values_and_output_fallbacks_exactly(): void
    {
        $context = new ContextDocument('source', 'Text', 4, 4, false);
        $candidate = $this->candidate('candidate-1');
        $decision = new CandidateDecision(StepId::fromString('step-1'), CandidateId::fromString('candidate-1'), 80, 'Good');
        $artifact = new Artifact('draft', ArtifactType::fromString('text'), 'Draft');
        $snapshot = new WorkflowRunSnapshot(
            id: RunId::fromString('run-snapshot'),
            status: RunStatus::Running,
            version: 9,
            input: new RunInput(['topic' => 'testing']),
            task: 'Task',
            dod: DefinitionOfDone::fromString('Done'),
            contexts: [$context],
            currentCandidates: [$candidate],
            acceptedCandidates: [$candidate],
            decisions: [$decision],
            stepStates: ['step-1' => ['phase' => 'judge']],
            rawOutput: 'raw',
            aiOutput: null,
            callsUsed: 2,
            callLimit: 5,
            artifacts: ['draft' => $artifact],
            startedAt: new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
        );

        self::assertSame('raw', $snapshot->output());
        self::assertSame([], $snapshot->stepState('missing'));
        self::assertSame($artifact, $snapshot->artifact('draft'));
        self::assertSame([
            'schema_version' => 1,
            'id' => 'run-snapshot',
            'status' => 'running',
            'version' => 9,
            'input' => ['topic' => 'testing'],
            'task' => 'Task',
            'definition_of_done' => ['automatic' => false, 'value' => 'Done'],
            'contexts' => [$context->toArray()],
            'current_candidates' => [$candidate->toArray()],
            'accepted_candidates' => [$candidate->toArray()],
            'decisions' => [$decision->toArray()],
            'step_states' => ['step-1' => ['phase' => 'judge']],
            'raw_output' => 'raw',
            'ai_output' => null,
            'output' => 'raw',
            'calls_used' => 2,
            'call_limit' => 5,
            'artifacts' => ['draft' => $artifact->toArray()],
            'resource_budget' => null,
            'started_at' => '2026-08-08T10:00:00+00:00',
        ], $snapshot->toArray());
        self::assertSame($snapshot->toArray(), $snapshot->jsonSerialize());

        $empty = new WorkflowRunSnapshot(
            RunId::fromString('empty'),
            RunStatus::Created,
            0,
            new RunInput([]),
            '',
            DefinitionOfDone::automatic(),
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            0,
            1,
        );
        self::assertSame('', $empty->output());
    }

    public function test_snapshot_missing_artifact_is_rejected(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->createRun()->snapshot()->artifact('missing');
    }

    #[DataProvider('invalidRunningOperations')]
    public function test_operations_reject_missing_or_wrong_running_step(callable $operation): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        $operation($this);
    }

    /** @return iterable<string, array{callable(self): void}> */
    public static function invalidRunningOperations(): iterable
    {
        $wrong = StepId::fromString('wrong-step');
        yield 'complete missing step' => [static fn (self $test) => $test->createRun()->completeStep($wrong, StepOutcome::completion([]))];
        yield 'await review missing step' => [static fn (self $test) => $test->createRun()->awaitInput($wrong)];
        yield 'await external missing step' => [static fn (self $test) => $test->createRun()->awaitExternalInput($wrong, 'key', 'prompt')];
        yield 'resume missing step' => [static fn (self $test) => $test->createRun()->resumeInput($wrong)];
        yield 'continue missing step' => [static fn (self $test) => $test->createRun()->continueStep($wrong, StepOutcome::continuation([]))];
        yield 'degrade missing step' => [static fn (self $test) => $test->createRun()->recordDegradedStep($wrong, 2, 1, [])];
        yield 'submit without barrier' => [static fn (self $test) => $test->createRun()->submitInput('key', 'value')];
        yield 'select without barrier' => [static fn (self $test) => $test->createRun()->selectCandidate(CandidateId::fromString('candidate'))];
        yield 'complete with continuation' => [static fn (self $test) => $test->running()->completeStep(StepId::fromString('step-1'), StepOutcome::continuation([]))];
        yield 'continue with terminal outcome' => [static fn (self $test) => $test->running()->continueStep(StepId::fromString('step-1'), StepOutcome::completion([]))];
        yield 'resume while running' => [static fn (self $test) => $test->running()->resumeInput(StepId::fromString('step-1'))];
        yield 'unknown candidate' => [static function (self $test): void {
            $run = $test->running();
            $run->continueStep(StepId::fromString('step-1'), StepOutcome::continuation([], [$test->candidate('known')]));
            $run->candidate(CandidateId::fromString('unknown'));
        }];
    }

    private function running(): WorkflowRun
    {
        $run = $this->createRun();
        $run->releaseEvents();
        $run->beginStep(StepId::fromString('step-1'));

        return $run;
    }

    /** @param list<string> $stepIds */
    private function createRun(
        array $stepIds = ['step-1'],
        int $callLimit = 10,
        ?DateTimeImmutable $startedAt = null,
    ): WorkflowRun {
        return WorkflowRun::start(
            RunId::fromString('run-1'),
            $this->workflow($stepIds),
            new RunInput(['topic' => 'testing']),
            $callLimit,
            $startedAt ?? new DateTimeImmutable('2026-08-08T07:00:00+00:00'),
        );
    }

    private function restored(RunStatus $status, int $position): WorkflowRun
    {
        $state = $this->createRun()->state();

        return WorkflowRun::restore(new WorkflowRunState(
            id: $state->id,
            workflow: $state->workflow,
            input: $state->input,
            status: $status,
            position: $position,
            version: $state->version,
            runningStep: null,
            task: $state->task,
            dod: $state->dod,
            contexts: $state->contexts,
            currentCandidates: $state->currentCandidates,
            acceptedCandidates: $state->acceptedCandidates,
            decisions: $state->decisions,
            stepStates: $state->stepStates,
            rawOutput: $state->rawOutput,
            aiOutput: $state->aiOutput,
            callsUsed: $state->callsUsed,
            callLimit: $state->callLimit,
            artifacts: $state->artifacts,
            startedAt: $state->startedAt,
            recovery: $state->recovery,
        ));
    }

    /** @param list<string> $ids */
    private function workflow(array $ids): CompiledWorkflow
    {
        return new CompiledWorkflow('workflow', '1.0.0', array_map(
            static fn (string $id): ResolveStep => new ResolveStep(
                StepId::fromString($id),
                "Task {$id}",
                DefinitionOfDone::automatic(),
            ),
            $ids,
        ));
    }

    /** @param array<string, mixed> $metadata */
    private function candidate(string $id, array $metadata = []): Candidate
    {
        return new Candidate(
            CandidateId::fromString($id),
            StepId::fromString('step-1'),
            ArtifactType::fromString('draft'),
            "Title {$id}",
            "Summary {$id}",
            ['id' => $id],
            "Candidate {$id}",
            'seed',
            'interpretation',
            $metadata,
        );
    }
}
