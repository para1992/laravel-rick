<?php

declare(strict_types=1);

namespace Rick\Laravel\Facade;

use Illuminate\Support\Facades\Facade;
use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
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
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

/**
 * @method static WorkflowBuilder workflow(string $name)
 * @method static CompiledWorkflow compile(WorkflowDefinition $workflow)
 * @method static WorkflowRunSnapshot run(WorkflowDefinition|CompiledWorkflow $workflow, RunInput|array<string, mixed> $input = [], int $callLimit = 60)
 * @method static WorkflowRunSnapshot schedule(WorkflowDefinition|CompiledWorkflow $workflow, RunInput|array<string, mixed> $input = [], int $callLimit = 60)
 * @method static WorkflowRunSnapshot resume(RunId|string $runId)
 * @method static RunRecoveryReceipt recover(RunId|string $runId, RunRecoveryAction|string $action = RunRecoveryAction::RetryFailed, ?int $callLimit = null, int $attempts = 1)
 * @method static WorkflowRunSnapshot snapshot(RunId|string $runId)
 * @method static RunMetrics metrics(RunId|string $runId)
 * @method static RunPage runs(?string $cursor = null, RunStatus|string|null $status = null, int $limit = 25)
 * @method static RunTimeline timeline(RunId|string $runId, int $afterVersion = 0)
 * @method static DeliverySnapshot delivery(RunId|string $runId)
 * @method static PendingReview pendingReview(RunId|string $runId)
 * @method static PendingInteraction pendingInteraction(RunId|string $runId)
 * @method static PendingInput pendingInput(RunId|string $runId)
 * @method static WorkflowRunSnapshot submitInput(RunId|string $runId, string $key, mixed $value)
 * @method static CandidateSelection selectCandidate(RunId|string $runId, CandidateId|string $candidateId)
 * @method static \Rick\Laravel\OutboxRelayReceipt relayOutbox(?int $limit = null)
 *
 * @see \Rick\Laravel\Rick
 */
final class Rick extends Facade
{
    /** @var bool */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return \Rick\Laravel\Rick::class;
    }
}
