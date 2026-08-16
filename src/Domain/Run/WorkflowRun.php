<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use DateTimeImmutable;
use InvalidArgumentException;
use Rick\Laravel\Domain\Event\CandidateReviewRequested;
use Rick\Laravel\Domain\Event\ExternalInputRequested;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Event\LlmCallReserved;
use Rick\Laravel\Domain\Event\MemoryCommitted;
use Rick\Laravel\Domain\Event\StepCompleted;
use Rick\Laravel\Domain\Event\StepContinued;
use Rick\Laravel\Domain\Event\StepDegraded;
use Rick\Laravel\Domain\Event\StepFailed;
use Rick\Laravel\Domain\Event\StepStarted;
use Rick\Laravel\Domain\Event\WorkflowCompleted;
use Rick\Laravel\Domain\Event\WorkflowCreated;
use Rick\Laravel\Domain\Event\WorkflowRecoveryStarted;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\Interface\LabeledStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class WorkflowRun
{
    private RunStatus $status = RunStatus::Created;

    private int $position = 0;

    private int $version = 0;

    private ?StepId $runningStep = null;

    private string $task = '';

    private DefinitionOfDone $dod;

    /** @var list<ContextDocument> */
    private array $contexts = [];

    /** @var list<Candidate> */
    private array $currentCandidates = [];

    /** @var list<Candidate> */
    private array $acceptedCandidates = [];

    /** @var list<CandidateDecision> */
    private array $decisions = [];

    /** @var array<string, array<string, mixed>> */
    private array $stepStates = [];

    /** @var array<string, Artifact> */
    private array $artifacts = [];

    /** @var list<EventBase> */
    private array $events = [];

    private ?string $rawOutput = null;

    private ?string $aiOutput = null;

    private DateTimeImmutable $startedAt;

    private ?RunRecovery $recovery = null;

    private function __construct(
        private readonly RunId $id,
        private readonly CompiledWorkflow $workflow,
        private readonly RunInput $input,
        private CallBudget $budget,
    ) {
        $this->startedAt = new DateTimeImmutable;
        $this->dod = DefinitionOfDone::automatic();
        $this->recordEvent(new WorkflowCreated(
            $this->id,
            $workflow->name,
            $workflow->version,
            new DateTimeImmutable,
        ));
    }

    public static function start(
        RunId $id,
        CompiledWorkflow $workflow,
        RunInput $input,
        int $callLimit,
        ?DateTimeImmutable $startedAt = null,
    ): self {
        $run = new self($id, $workflow, $input, new CallBudget($callLimit));
        $run->startedAt = $startedAt ?? new DateTimeImmutable;

        return $run;
    }

    public static function restore(WorkflowRunState $state): self
    {
        $run = new self(
            $state->id,
            $state->workflow,
            $state->input,
            CallBudget::restore($state->callLimit, $state->callsUsed),
        );
        $run->events = [];
        $run->status = $state->status;
        $run->position = $state->position;
        $run->version = $state->version;
        $run->runningStep = $state->runningStep;
        $run->task = $state->task;
        $run->dod = $state->dod;
        $run->contexts = $state->contexts;
        $run->currentCandidates = $state->currentCandidates;
        $run->acceptedCandidates = $state->acceptedCandidates;
        $run->decisions = $state->decisions;
        $run->stepStates = $state->stepStates;
        $run->artifacts = $state->artifacts;
        $run->rawOutput = $state->rawOutput;
        $run->aiOutput = $state->aiOutput;
        $run->startedAt = $state->startedAt ?? new DateTimeImmutable;
        $run->recovery = $state->recovery;

        return $run;
    }

    public static function recover(
        RunId $id,
        self $parent,
        RunRecoveryAction $action,
        int $callLimit,
        ?DateTimeImmutable $startedAt = null,
    ): self {
        $parentState = $parent->state();
        if ($parentState->status !== RunStatus::Failed) {
            throw new InvalidStateTransitionException('Only a failed workflow may create a recovery child run.');
        }
        $step = $parent->nextStep()
            ?? throw new InvalidStateTransitionException('Failed workflow has no step to recover.');
        $run = new self($id, $parentState->workflow, $parentState->input, new CallBudget($callLimit));
        $run->position = $parentState->position;
        $run->task = $parentState->task;
        $run->dod = $parentState->dod;
        $run->contexts = $parentState->contexts;
        $run->currentCandidates = $parentState->currentCandidates;
        $run->acceptedCandidates = $parentState->acceptedCandidates;
        $run->decisions = $parentState->decisions;
        $run->stepStates = $parentState->stepStates;
        $failedStepState = $parentState->stepStates[$step->id()->toString()] ?? null;
        if (
            $action === RunRecoveryAction::ForkFailedStep
            || (is_array($failedStepState) && ($failedStepState['phase'] ?? null) === 'failed')
        ) {
            // A strategy-level failure (for example a grounded verification
            // exhausting its repairs) persists phase=failed. Every recovery
            // of that step must plan from a clean state; otherwise the strategy
            // throws the same terminal exception before consuming fresh work.
            unset($run->stepStates[$step->id()->toString()]);
        }
        $run->artifacts = $parentState->artifacts;
        $run->rawOutput = $parentState->rawOutput;
        $run->aiOutput = $parentState->aiOutput;
        $run->startedAt = $startedAt ?? new DateTimeImmutable;
        $run->recovery = new RunRecovery($parent->id(), $action, $step->id());
        $run->recordEvent(new WorkflowRecoveryStarted(
            $run->id,
            $parent->id(),
            $action,
            $step->id(),
            new DateTimeImmutable,
        ));

        return $run;
    }

    public function beginStep(StepId $stepId): void
    {
        if (in_array($this->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled], true)) {
            throw new InvalidStateTransitionException(
                "Cannot begin a step on a {$this->status->value} run.",
            );
        }

        $expected = $this->nextStep();

        if ($expected === null || $expected->id()->toString() !== $stepId->toString()) {
            throw new InvalidStateTransitionException(
                "Step [{$stepId->toString()}] is not the next pending step.",
            );
        }

        $this->status = RunStatus::Running;
        $this->runningStep = $stepId;
        $this->incrementVersion();
        $this->recordEvent(new StepStarted(
            $this->id,
            $stepId,
            $expected->type(),
            new DateTimeImmutable,
        ));
    }

    public function completeStep(StepId $stepId, StepOutcome $outcome): void
    {
        if ($this->runningStep?->toString() !== $stepId->toString()) {
            throw new InvalidStateTransitionException(
                "Step [{$stepId->toString()}] is not running.",
            );
        }

        if ($outcome->continuesStep) {
            throw new InvalidStateTransitionException(
                'A continuation outcome cannot complete a workflow step.',
            );
        }

        $this->applyOutcome($stepId, $outcome);
        $this->position++;
        $this->runningStep = null;
        $this->incrementVersion();
        $this->recordEvent(new StepCompleted(
            $this->id,
            $stepId,
            $outcome->metadata,
            new DateTimeImmutable,
        ));

        if ($this->position >= $this->workflow->count()) {
            $this->status = RunStatus::Completed;
            $this->incrementVersion();
            $this->recordEvent(new WorkflowCompleted(
                $this->id,
                $this->finalOutput(),
                new DateTimeImmutable,
            ));
        }
    }

    /** @param array<string, mixed> $metadata */
    public function awaitInput(StepId $stepId, array $metadata = []): void
    {
        if ($this->runningStep?->toString() !== $stepId->toString()) {
            throw new InvalidStateTransitionException(
                "Step [{$stepId->toString()}] is not running.",
            );
        }

        if ($this->status === RunStatus::AwaitingInput) {
            // A duplicated continuation delivery may re-enter a step that is
            // already parked at the same review barrier. Treat that as a no-op
            // instead of re-arming the barrier.
            return;
        }

        if ($this->status !== RunStatus::Running) {
            throw new InvalidStateTransitionException(
                'Only a running workflow may wait for external input.',
            );
        }

        $this->status = RunStatus::AwaitingInput;
        $this->incrementVersion();
        $this->recordEvent(new CandidateReviewRequested(
            $this->id,
            $stepId,
            is_string($metadata['scope'] ?? null) ? $metadata['scope'] : 'candidate',
            array_map(
                static fn (Candidate $candidate): CandidateId => $candidate->id,
                $this->currentCandidates,
            ),
            array_intersect_key($metadata, array_flip([
                'scope',
                'unit_index',
                'unit_number',
                'total_units',
            ])),
            new DateTimeImmutable,
        ));
    }

    /** @param array<string, mixed>|null $schema */
    public function awaitExternalInput(
        StepId $stepId,
        string $key,
        string $prompt,
        ?array $schema = null,
    ): void {
        if ($this->runningStep?->toString() !== $stepId->toString()) {
            throw new InvalidStateTransitionException(
                "Step [{$stepId->toString()}] is not running.",
            );
        }

        if ($this->status !== RunStatus::Running) {
            throw new InvalidStateTransitionException(
                'Only a running workflow may wait for external input.',
            );
        }

        $this->status = RunStatus::AwaitingInput;
        $this->incrementVersion();
        $this->recordEvent(new ExternalInputRequested(
            $this->id,
            $stepId,
            $key,
            $prompt,
            $schema,
            new DateTimeImmutable,
        ));
    }

    public function resumeInput(StepId $stepId): void
    {
        if ($this->runningStep?->toString() !== $stepId->toString()) {
            throw new InvalidStateTransitionException(
                "Step [{$stepId->toString()}] is not waiting for input.",
            );
        }

        if ($this->status !== RunStatus::AwaitingInput) {
            throw new InvalidStateTransitionException(
                'Workflow is not waiting for external input.',
            );
        }

        $this->status = RunStatus::Running;
        $this->incrementVersion();
    }

    public function submitInput(string $key, mixed $value): void
    {
        $stepId = $this->runningStep
            ?? throw new InvalidStateTransitionException('Workflow has no step waiting for external input.');
        $this->resumeInput($stepId);
        $state = $this->stepStates[$stepId->toString()] ?? [];
        $state['external_input'] = ['key' => $key, 'value' => $value];
        $this->completeStep($stepId, StepOutcome::completion($state));
    }

    public function selectCandidate(CandidateId $candidateId): void
    {
        $stepId = $this->runningStep
            ?? throw new InvalidStateTransitionException('Workflow has no step waiting for candidate selection.');
        $this->candidate($candidateId);
        $this->resumeInput($stepId);
        $this->completeStep($stepId, StepOutcome::judged(new CandidateDecision(
            $stepId,
            $candidateId,
            null,
            'Selected through external review.',
            'manual',
        )));
    }

    public function continueStep(StepId $stepId, StepOutcome $outcome): void
    {
        if ($this->runningStep?->toString() !== $stepId->toString()) {
            throw new InvalidStateTransitionException(
                "Step [{$stepId->toString()}] is not running.",
            );
        }

        if (! $outcome->continuesStep) {
            throw new InvalidStateTransitionException(
                'A terminal outcome cannot continue a workflow step.',
            );
        }

        $this->applyOutcome($stepId, $outcome);
        $this->incrementVersion();
        $this->recordEvent(new StepContinued(
            $this->id,
            $stepId,
            $outcome->metadata,
            new DateTimeImmutable,
        ));
    }

    private function applyOutcome(StepId $stepId, StepOutcome $outcome): void
    {
        if ($outcome->task !== null) {
            $this->task = $outcome->task;
        }

        if ($outcome->dod !== null) {
            $this->dod = $outcome->dod;
        }

        if ($outcome->contexts !== []) {
            array_push($this->contexts, ...$outcome->contexts);
        }

        if ($outcome->candidates !== []) {
            $this->currentCandidates = $outcome->candidates;
            $this->rawOutput = null;
            $this->aiOutput = null;
        }

        if ($outcome->decision !== null) {
            $selected = $this->candidate($outcome->decision->selectedCandidateId);
            $this->acceptedCandidates[] = $selected;
            $this->decisions[] = $outcome->decision;
            $artifact = Artifact::fromCandidate($selected);
            $this->artifacts[$artifact->key] = $this->nextArtifactVersion($artifact);
        }

        foreach ($outcome->artifacts as $artifact) {
            $this->artifacts[$artifact->key] = $this->nextArtifactVersion($artifact);
        }

        if ($outcome->rawOutput !== null) {
            $this->rawOutput = $outcome->rawOutput;
        }

        if ($outcome->aiOutput !== null) {
            $this->aiOutput = $outcome->aiOutput;
        }

        if ($outcome->stepState !== null) {
            $this->stepStates[$stepId->toString()] = $outcome->stepState;
        }

        $memory = is_array($outcome->stepState['memory'] ?? null)
            ? $outcome->stepState['memory']
            : null;
        $cards = is_array($memory['unit_cards'] ?? null)
            ? $memory['unit_cards']
            : [];

        if (
            $outcome->decision !== null
            && is_int($outcome->metadata['memory_version'] ?? null)
            && is_string($outcome->metadata['memory_hash'] ?? null)
            && $cards !== []
        ) {
            $lastCard = $cards[array_key_last($cards)];

            if (is_array($lastCard) && is_string($lastCard['unit_id'] ?? null)) {
                $this->recordEvent(new MemoryCommitted(
                    $this->id,
                    $stepId,
                    $outcome->decision->selectedCandidateId,
                    $lastCard['unit_id'],
                    $outcome->metadata['memory_version'],
                    $outcome->metadata['memory_hash'],
                    new DateTimeImmutable,
                ));
            }
        }
    }

    public function failStep(StepId $stepId, string $errorCode, string $message): void
    {
        $this->runningStep = null;
        $this->status = RunStatus::Failed;
        $this->incrementVersion();
        $this->recordEvent(new StepFailed(
            $this->id,
            $stepId,
            $errorCode,
            $message,
            new DateTimeImmutable,
        ));
    }

    /** @param list<string> $failureCodes */
    public function recordDegradedStep(
        StepId $stepId,
        int $expected,
        int $succeeded,
        array $failureCodes,
    ): void {
        if ($this->runningStep?->toString() !== $stepId->toString()) {
            throw new InvalidStateTransitionException(
                "Step [{$stepId->toString()}] is not running.",
            );
        }
        if ($expected < 1 || $succeeded < 1 || $succeeded >= $expected) {
            throw new InvalidArgumentException('Degraded step counts are invalid.');
        }
        $failureCodes = array_values(array_unique(array_filter(
            $failureCodes,
            static fn (string $code): bool => $code !== '',
        )));
        sort($failureCodes);
        $this->incrementVersion();
        $this->recordEvent(new StepDegraded(
            $this->id,
            $stepId,
            $expected,
            $succeeded,
            $failureCodes,
            new DateTimeImmutable,
        ));
    }

    public function reserveCall(string $purpose): int
    {
        $call = $this->budget->reserve($purpose);
        $this->incrementVersion();
        $this->recordEvent(new LlmCallReserved(
            $this->id,
            $call,
            $this->budget->limit(),
            $purpose,
            new DateTimeImmutable,
        ));

        return $call;
    }

    /** @param list<string> $purposes */
    public function reserveCalls(array $purposes): void
    {
        if ($purposes === []) {
            throw new InvalidArgumentException('Call purposes must not be empty.');
        }

        $calls = $this->budget->reserveMany(count($purposes), implode(', ', $purposes));

        foreach ($calls as $index => $call) {
            $this->recordEvent(new LlmCallReserved(
                $this->id,
                $call,
                $this->budget->limit(),
                $purposes[$index],
                new DateTimeImmutable,
            ));
        }

        $this->incrementVersion();
    }

    public function nextStep(): ?StepBase
    {
        return $this->workflow->stepAt($this->position);
    }

    public function progress(): RunProgress
    {
        $total = $this->workflow->count();
        $current = $total === 0 ? 0 : min($this->position + 1, $total);
        $step = $this->nextStep();
        $label = $step instanceof LabeledStepBase ? $step->label() : null;

        return new RunProgress(
            $this->status,
            $step?->id()->toString(),
            $label,
            $current,
            $total,
        );
    }

    public function candidate(CandidateId $id): Candidate
    {
        foreach ($this->currentCandidates as $candidate) {
            if ($candidate->id->toString() === $id->toString()) {
                return $candidate;
            }
        }

        throw new InvalidStateTransitionException(
            "Candidate [{$id->toString()}] does not belong to the current step.",
        );
    }

    public function id(): RunId
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function runningStepId(): ?StepId
    {
        return $this->runningStep;
    }

    public function snapshot(): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            id: $this->id,
            status: $this->status,
            version: $this->version,
            input: $this->input,
            task: $this->task,
            dod: $this->dod,
            contexts: $this->contexts,
            currentCandidates: $this->currentCandidates,
            acceptedCandidates: $this->acceptedCandidates,
            decisions: $this->decisions,
            stepStates: $this->stepStates,
            rawOutput: $this->rawOutput,
            aiOutput: $this->aiOutput,
            callsUsed: $this->budget->used(),
            callLimit: $this->budget->limit(),
            artifacts: $this->artifacts,
            resourceBudget: $this->workflow->resourceBudget,
            startedAt: $this->startedAt,
            recovery: $this->recovery,
        );
    }

    public function state(): WorkflowRunState
    {
        return new WorkflowRunState(
            id: $this->id,
            workflow: $this->workflow,
            input: $this->input,
            status: $this->status,
            position: $this->position,
            version: $this->version,
            runningStep: $this->runningStep,
            task: $this->task,
            dod: $this->dod,
            contexts: $this->contexts,
            currentCandidates: $this->currentCandidates,
            acceptedCandidates: $this->acceptedCandidates,
            decisions: $this->decisions,
            stepStates: $this->stepStates,
            rawOutput: $this->rawOutput,
            aiOutput: $this->aiOutput,
            callsUsed: $this->budget->used(),
            callLimit: $this->budget->limit(),
            artifacts: $this->artifacts,
            startedAt: $this->startedAt,
            recovery: $this->recovery,
        );
    }

    /** @return list<EventBase> */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function __clone(): void
    {
        $this->budget = clone $this->budget;
        $this->events = [];
    }

    private function incrementVersion(): void
    {
        $this->version++;
    }

    private function finalOutput(): string
    {
        return $this->aiOutput ?? $this->rawOutput ?? '';
    }

    private function nextArtifactVersion(Artifact $artifact): Artifact
    {
        $previous = $this->artifacts[$artifact->key] ?? null;
        $version = $previous === null
            ? $artifact->version
            : max($artifact->version, $previous->version + 1);

        return new Artifact(
            $artifact->key,
            $artifact->type,
            $artifact->content,
            $artifact->payload,
            $artifact->metadata,
            $version,
        );
    }

    private function recordEvent(EventBase $event): void
    {
        $this->events[] = $event;
    }
}
