<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Outbox;

use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class DatabaseOutboxExecutionBackend implements ExecutionBackendBase
{
    public function __construct(private OutboxWriter $outbox) {}

    public function continue(
        RunId $runId,
        int $transitionVersion,
        ?InvocationId $sourceInvocationId = null,
    ): void {
        self::assertVersion($transitionVersion);
        $this->outbox->record(
            'continue_run',
            $runId->toString(),
            $sourceInvocationId?->toString(),
            null,
            null,
            self::deduplicationKey(
                'continue_run',
                $runId->toString(),
                $sourceInvocationId?->toString(),
                $transitionVersion,
            ),
        );
    }

    public function invoke(InvocationId $invocationId, RunId $runId, int $transitionVersion): void
    {
        self::assertVersion($transitionVersion);
        $this->outbox->record(
            'execute_invocation',
            $runId->toString(),
            $invocationId->toString(),
            null,
            null,
            self::deduplicationKey(
                'execute_invocation',
                $runId->toString(),
                $invocationId->toString(),
                $transitionVersion,
            ),
        );
    }

    private static function assertVersion(int $transitionVersion): void
    {
        if ($transitionVersion < 0) {
            throw new InvalidArgumentException('Outbox transition version must not be negative.');
        }
    }

    private static function deduplicationKey(
        string $kind,
        string $runId,
        ?string $invocationId,
        int $transitionVersion,
    ): string {
        return hash('sha256', implode("\0", [
            $kind,
            $runId,
            $invocationId ?? '',
            (string) $transitionVersion,
        ]));
    }
}
