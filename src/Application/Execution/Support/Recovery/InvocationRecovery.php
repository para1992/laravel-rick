<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Recovery;

use DateTimeImmutable;
use InvalidArgumentException;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;

final readonly class InvocationRecovery
{
    public function markExpired(
        LlmInvocation $invocation,
        ?InvocationAttempt $attempt,
        DateTimeImmutable $now,
    ): bool {
        if (
            $invocation->status() !== InvocationStatus::Running
            || $invocation->leaseExpiresAt() === null
            || $invocation->leaseExpiresAt() > $now
        ) {
            return false;
        }

        $message = 'The provider outcome is unknown because the invocation lease expired.';
        $invocation->markIndeterminate('invocation_lease_expired', $message);
        if ($attempt !== null && $attempt->status() === InvocationAttemptStatus::Running) {
            $attempt->markIndeterminate('invocation_lease_expired', $message, $now);
        }

        return true;
    }

    public function resolve(LlmInvocation $invocation, string $outcome, string $message): void
    {
        match ($outcome) {
            'retry' => $invocation->retryIndeterminate(),
            'fail' => $invocation->failIndeterminate($message),
            default => throw new InvalidArgumentException('Recovery outcome must be retry or fail.'),
        };
    }
}
