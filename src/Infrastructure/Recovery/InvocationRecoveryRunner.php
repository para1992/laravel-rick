<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Recovery;

use Rick\Laravel\Application\Execution\Exception\ConcurrentExecutionModificationException;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Support\Recovery\InvocationRecovery;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Event\InvocationRecoveryRequired;
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class InvocationRecoveryRunner
{
    public function __construct(
        private InvocationRecovery $recovery,
        private ExecutionRepositoryBase $executions,
        private ExecutionBackendBase $backend,
        private TransactionBase $transactions,
        private ClockBase $clock,
        private EventOutboxBase $events,
        private int $batchSize,
    ) {}

    public function markExpired(): int
    {
        $expired = $this->executions->staleRunning($this->clock->now(), $this->batchSize);
        $marked = 0;
        foreach ($expired as $invocation) {
            try {
                $changed = $this->transactions->run(function () use ($invocation): bool {
                    $now = $this->clock->now();
                    $stored = $this->executions->getInvocation($invocation->id());
                    $attempt = $this->executions->latestAttemptFor($stored->id());
                    $version = $stored->version();
                    if (! $this->recovery->markExpired($stored, $attempt, $now)) {
                        return false;
                    }

                    $this->executions->saveInvocation($stored, $version);
                    if ($attempt !== null && $attempt->status() === InvocationAttemptStatus::Indeterminate) {
                        $this->executions->saveAttempt($attempt);
                    }
                    $this->events->record(new InvocationRecoveryRequired(
                        $stored->runId(),
                        $stored->id(),
                        'invocation_lease_expired',
                        $now,
                    ));

                    return true;
                });
                $marked += $changed ? 1 : 0;
            } catch (ConcurrentExecutionModificationException) {
            }
        }

        return $marked;
    }

    public function resolve(InvocationId $id, string $outcome, string $message): RunId
    {
        return $this->transactions->run(function () use ($id, $outcome, $message): RunId {
            $invocation = $this->executions->getInvocation($id);
            $version = $invocation->version();
            $this->recovery->resolve($invocation, $outcome, $message);
            $this->executions->saveInvocation($invocation, $version);

            if ($outcome === 'retry') {
                $this->backend->invoke($invocation->id(), $invocation->runId(), $invocation->version());
            } else {
                $this->backend->continue(
                    $invocation->runId(),
                    $invocation->version(),
                    $invocation->id(),
                );
            }

            return $invocation->runId();
        });
    }
}
