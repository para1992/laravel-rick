<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Queue\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Request\ExecuteInvocationRequest;
use Rick\Laravel\Application\Execution\Request\FailInvocationRequest;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\ValueObject\Identifier;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Rick\Laravel\Infrastructure\Queue\QueueLock;
use RuntimeException;
use Throwable;

final class ExecuteInvocationJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    private array $retryBackoff = [5, 30, 120, 300];

    public readonly string $tenantId;

    public readonly string $invocationId;

    public readonly string $runId;

    public function __construct(
        string $tenantId,
        string $invocationId,
        string $runId,
    ) {
        $this->tenantId = Identifier::normalize($tenantId, 'Tenant ID');
        $this->invocationId = InvocationId::fromString($invocationId)->toString();
        $this->runId = RunId::fromString($runId)->toString();
    }

    /** @param list<int> $backoff */
    public function configure(int $tries, int $timeout, array $backoff): void
    {
        if ($tries < 1 || $timeout < 1 || $backoff === []) {
            throw new InvalidArgumentException('Invocation job retry configuration is invalid.');
        }
        foreach ($backoff as $delay) {
            if ($delay < 0) {
                throw new InvalidArgumentException('Invocation job backoff cannot be negative.');
            }
        }

        $this->tries = $tries;
        $this->timeout = $timeout;
        $this->retryBackoff = $backoff;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return $this->retryBackoff;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping(QueueLock::key(
            'invocation',
            $this->tenantId,
            $this->invocationId,
        )))
            ->releaseAfter(1)
            ->expireAfter($this->timeout + 120)];
    }

    public function handle(
        Handler $handler,
        TenantContextBase $tenant,
    ): void {
        $tenant->run($this->tenantId, function () use ($handler): void {
            $handler->handle(Parcel::fromArray([
                new ExecuteInvocationRequest(InvocationId::fromString($this->invocationId)),
            ]));
        });
    }

    public function failed(?Throwable $error): void
    {
        $error ??= new RuntimeException('Invocation job failed without an exception.');
        try {
            report($error);
        } catch (Throwable) {
            // Reporting must not block the canonical failure transition.
        }
        $handler = app(Handler::class);
        $tenant = app(TenantContextBase::class);
        $tenant->run($this->tenantId, function () use ($handler): void {
            $handler->handle(Parcel::fromArray([
                new FailInvocationRequest(
                    InvocationId::fromString($this->invocationId),
                    'invocation_job_failed',
                    'Invocation job failed; inspect runtime logs.',
                ),
            ]));
        });
    }
}
