<?php

declare(strict_types=1);

namespace Rick\Laravel;

use Rick\Laravel\Application\Execution\Request\GetRunProgressRequest;
use Rick\Laravel\Application\Execution\Result\GetRunProgressResult;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Metrics\ValueObject\RunMetrics;
use Rick\Laravel\Domain\Run\DeliverySnapshot;
use Rick\Laravel\Domain\Run\PendingInteraction;
use Rick\Laravel\Domain\Run\RunProgress;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunRecoveryReceipt;
use Rick\Laravel\Domain\Run\RunTimeline;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\ValueObject\Parcel;

/**
 * A read/action handle over a persisted Rick run. It delegates to the same
 * application requests the lower-level Rick service uses, so there is no
 * second runtime: recovery stays the immutable recovery-child model, and
 * progress stays a safe projection of identifiers only.
 */
final readonly class Run
{
    public function __construct(
        private Rick $rick,
        private Handler $handler,
        private RunId $id,
    ) {}

    public static function of(Rick $rick, WorkflowRunSnapshot $snapshot): self
    {
        return new self($rick, app(Handler::class), $snapshot->id);
    }

    public function id(): string
    {
        return $this->id->toString();
    }

    public function snapshot(): WorkflowRunSnapshot
    {
        return $this->rick->snapshot($this->id);
    }

    public function metrics(): RunMetrics
    {
        return $this->rick->metrics($this->id);
    }

    public function timeline(): RunTimeline
    {
        return $this->rick->timeline($this->id);
    }

    public function delivery(): DeliverySnapshot
    {
        return $this->rick->delivery($this->id);
    }

    public function progress(): RunProgress
    {
        $result = $this->handler->handle(
            Parcel::fromArray([new GetRunProgressRequest($this->id)]),
        )->get(GetRunProgressResult::class);

        return $result->progress;
    }

    public function pendingInteraction(): PendingInteraction
    {
        return $this->rick->pendingInteraction($this->id);
    }

    public function resume(): WorkflowRunSnapshot
    {
        return $this->rick->resume($this->id);
    }

    /**
     * Retry a failed run through the immutable recovery-child model: the
     * parent stays untouched, a child run is created pointing back to it, and
     * successful reusable provider work is reused where recovery rules allow.
     */
    public function retry(): RunRecoveryReceipt
    {
        return $this->rick->recover($this->id, RunRecoveryAction::RetryFailed);
    }
}
