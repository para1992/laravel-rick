<?php

declare(strict_types=1);

namespace Rick\Laravel;

use InvalidArgumentException;
use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowDefinition as ApplicationWorkflowDefinition;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowPlan;
use Rick\Laravel\Application\Execution\Request\GetDeliverySnapshotRequest;
use Rick\Laravel\Application\Execution\Request\GetPendingInputRequest;
use Rick\Laravel\Application\Execution\Request\GetPendingInteractionRequest;
use Rick\Laravel\Application\Execution\Request\GetPendingReviewRequest;
use Rick\Laravel\Application\Execution\Request\GetRunMetricsRequest;
use Rick\Laravel\Application\Execution\Request\GetRunSnapshotRequest;
use Rick\Laravel\Application\Execution\Request\GetRunTimelineRequest;
use Rick\Laravel\Application\Execution\Request\ListRunsRequest;
use Rick\Laravel\Application\Execution\Request\RecoverRunRequest;
use Rick\Laravel\Application\Execution\Request\ResumeRunRequest;
use Rick\Laravel\Application\Execution\Request\RunWorkflowRequest;
use Rick\Laravel\Application\Execution\Request\ScheduleRunRequest;
use Rick\Laravel\Application\Execution\Request\SelectCandidateRequest;
use Rick\Laravel\Application\Execution\Request\SubmitInputRequest;
use Rick\Laravel\Application\Execution\Result\GetDeliverySnapshotResult;
use Rick\Laravel\Application\Execution\Result\GetPendingInputResult;
use Rick\Laravel\Application\Execution\Result\GetPendingInteractionResult;
use Rick\Laravel\Application\Execution\Result\GetPendingReviewResult;
use Rick\Laravel\Application\Execution\Result\GetRunMetricsResult;
use Rick\Laravel\Application\Execution\Result\GetRunSnapshotResult;
use Rick\Laravel\Application\Execution\Result\GetRunTimelineResult;
use Rick\Laravel\Application\Execution\Result\ListRunsResult;
use Rick\Laravel\Application\Execution\Result\RecoverRunResult;
use Rick\Laravel\Application\Execution\Result\ResumeRunResult;
use Rick\Laravel\Application\Execution\Result\RunWorkflowResult;
use Rick\Laravel\Application\Execution\Result\ScheduleRunResult;
use Rick\Laravel\Application\Execution\Result\SelectCandidateResult;
use Rick\Laravel\Application\Execution\Result\SubmitInputResult;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\RequestBase;
use Rick\Laravel\Application\Interface\ResultBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Metrics\ValueObject\RunMetrics;
use Rick\Laravel\Domain\Run\CandidateSelection;
use Rick\Laravel\Domain\Run\DeliverySnapshot;
use Rick\Laravel\Domain\Run\PendingInput;
use Rick\Laravel\Domain\Run\PendingInteraction;
use Rick\Laravel\Domain\Run\PendingReview;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunPage;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunRecoveryReceipt;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\RunTimeline;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;
use Rick\Laravel\Infrastructure\Outbox\OutboxRelay;
use Throwable;

final readonly class Rick
{
    public function __construct(
        private Handler $handler,
        private IdGeneratorBase $ids,
        private OutboxRelay $outbox,
    ) {}

    public function workflow(string $name): WorkflowBuilder
    {
        return new WorkflowBuilder($name);
    }

    public function compile(WorkflowDefinition $workflow): CompiledWorkflow
    {
        return $this->handler
            ->handle(Parcel::fromArray([new ApplicationWorkflowDefinition($workflow)]))
            ->get(WorkflowPlan::class)
            ->workflow;
    }

    /** @param RunInput|array<string, mixed> $input */
    public function run(
        WorkflowDefinition|CompiledWorkflow $workflow,
        RunInput|array $input = [],
        int $callLimit = 60,
    ): WorkflowRunSnapshot {
        return $this->execution(
            $workflow,
            new RunWorkflowRequest($this->runId(), self::input($input), $callLimit),
            RunWorkflowResult::class,
        )->run;
    }

    /** @param RunInput|array<string, mixed> $input */
    public function schedule(
        WorkflowDefinition|CompiledWorkflow $workflow,
        RunInput|array $input = [],
        int $callLimit = 60,
    ): WorkflowRunSnapshot {
        return $this->execution(
            $workflow,
            new ScheduleRunRequest($this->runId(), self::input($input), $callLimit),
            ScheduleRunResult::class,
        )->run->snapshot();
    }

    public function resume(RunId|string $runId): WorkflowRunSnapshot
    {
        return $this->request(new ResumeRunRequest(self::runIdFrom($runId)), ResumeRunResult::class)->run;
    }

    public function recover(
        RunId|string $runId,
        RunRecoveryAction|string $action = RunRecoveryAction::RetryFailed,
        ?int $callLimit = null,
        int $attempts = 1,
    ): RunRecoveryReceipt {
        if ($attempts < 1 || $attempts > 10) {
            throw new InvalidArgumentException('Recovery attempts must be between 1 and 10.');
        }

        $parentRunId = self::runIdFrom($runId);
        $recoveryAction = self::recoveryActionFrom($action);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $this->request(
                    new RecoverRunRequest(
                        $parentRunId,
                        $this->runId(),
                        $recoveryAction,
                        $callLimit,
                    ),
                    RecoverRunResult::class,
                );

                return new RunRecoveryReceipt(
                    $result->run,
                    $result->reusedInvocations,
                    $result->queuedInvocations,
                    $result->copiedFailures,
                    $result->alreadyExists,
                    $attempt,
                );
            } catch (Throwable $error) {
                $lastError = $error;
            }

            if ($attempt < $attempts) {
                usleep($attempt * 100_000);
            }
        }

        throw $lastError ?? new InvalidArgumentException('Recovery failed without an exception.');
    }

    public function snapshot(RunId|string $runId): WorkflowRunSnapshot
    {
        return $this->request(
            new GetRunSnapshotRequest(self::runIdFrom($runId)),
            GetRunSnapshotResult::class,
        )->run;
    }

    public function metrics(RunId|string $runId): RunMetrics
    {
        return $this->request(
            new GetRunMetricsRequest(self::runIdFrom($runId)),
            GetRunMetricsResult::class,
        )->metrics;
    }

    public function runs(
        ?string $cursor = null,
        RunStatus|string|null $status = null,
        int $limit = 25,
    ): RunPage {
        return $this->request(
            new ListRunsRequest($cursor, self::runStatusFrom($status), $limit),
            ListRunsResult::class,
        )->page;
    }

    public function timeline(RunId|string $runId, int $afterVersion = 0): RunTimeline
    {
        return $this->request(
            new GetRunTimelineRequest(self::runIdFrom($runId), $afterVersion),
            GetRunTimelineResult::class,
        )->timeline;
    }

    public function delivery(RunId|string $runId): DeliverySnapshot
    {
        return $this->request(
            new GetDeliverySnapshotRequest(self::runIdFrom($runId)),
            GetDeliverySnapshotResult::class,
        )->delivery;
    }

    public function pendingReview(RunId|string $runId): PendingReview
    {
        return $this->request(
            new GetPendingReviewRequest(self::runIdFrom($runId)),
            GetPendingReviewResult::class,
        )->pending;
    }

    public function pendingInteraction(RunId|string $runId): PendingInteraction
    {
        return $this->request(
            new GetPendingInteractionRequest(self::runIdFrom($runId)),
            GetPendingInteractionResult::class,
        )->pending;
    }

    public function pendingInput(RunId|string $runId): PendingInput
    {
        return $this->request(
            new GetPendingInputRequest(self::runIdFrom($runId)),
            GetPendingInputResult::class,
        )->pending;
    }

    public function submitInput(RunId|string $runId, string $key, mixed $value): WorkflowRunSnapshot
    {
        return $this->request(
            new SubmitInputRequest(self::runIdFrom($runId), $key, $value),
            SubmitInputResult::class,
        )->run;
    }

    public function selectCandidate(RunId|string $runId, CandidateId|string $candidateId): CandidateSelection
    {
        return $this->request(
            new SelectCandidateRequest(
                self::runIdFrom($runId),
                $candidateId instanceof CandidateId ? $candidateId : CandidateId::fromString($candidateId),
            ),
            SelectCandidateResult::class,
        )->selection;
    }

    public function relayOutbox(?int $limit = null): OutboxRelayReceipt
    {
        $result = $this->outbox->relay($limit);

        return new OutboxRelayReceipt(
            $result->claimed,
            $result->delivered,
            $result->deferred,
            $result->failed,
        );
    }

    /**
     * @template T of ResultBase
     *
     * @param  class-string<T>  $result
     * @return T
     */
    private function execution(
        WorkflowDefinition|CompiledWorkflow $workflow,
        RequestBase $request,
        string $result,
    ): ResultBase {
        return $this->handler->handle(
            Parcel::fromArray([
                $workflow instanceof CompiledWorkflow
                    ? new WorkflowPlan($workflow)
                    : new ApplicationWorkflowDefinition($workflow),
                $request,
            ]),
        )->get($result);
    }

    /**
     * @template T of ResultBase
     *
     * @param  class-string<T>  $result
     * @return T
     */
    private function request(RequestBase $request, string $result): ResultBase
    {
        return $this->handler->handle(Parcel::fromArray([$request]))->get($result);
    }

    private function runId(): RunId
    {
        return RunId::fromString($this->ids->generate());
    }

    private static function runIdFrom(RunId|string $runId): RunId
    {
        return $runId instanceof RunId ? $runId : RunId::fromString($runId);
    }

    private static function runStatusFrom(RunStatus|string|null $status): ?RunStatus
    {
        return is_string($status) ? RunStatus::from($status) : $status;
    }

    private static function recoveryActionFrom(RunRecoveryAction|string $action): RunRecoveryAction
    {
        return is_string($action) ? RunRecoveryAction::from($action) : $action;
    }

    /** @param RunInput|array<string, mixed> $input */
    private static function input(RunInput|array $input): RunInput
    {
        return $input instanceof RunInput ? $input : new RunInput($input);
    }
}
