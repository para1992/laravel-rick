<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final class InvocationAttemptEdgeTest extends TestCase
{
    public function test_attempt_number_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->attempt(0);
    }

    public function test_restore_infers_sdk_source_when_provider_ids_exist(): void
    {
        $attempt = InvocationAttempt::restore(
            InvocationAttemptId::fromString('attempt-1'),
            InvocationId::fromString('invocation-1'),
            RunId::fromString('run-1'),
            1,
            'fingerprint',
            InvocationAttemptStatus::Failed,
            new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
            new DateTimeImmutable('2026-08-08T10:01:00+00:00'),
            'provider-request',
            'failed',
            'Failure',
        );

        self::assertSame(ProviderIdSource::Sdk, $attempt->providerIdentifiers()?->source);
    }

    public function test_finished_attempt_cannot_finish_twice(): void
    {
        $attempt = $this->attempt(1);
        $attempt->fail('failed', 'Failure', new DateTimeImmutable);

        $this->expectException(InvalidStateTransitionException::class);
        $attempt->markIndeterminate('unknown', 'Unknown', new DateTimeImmutable);
    }

    private function attempt(int $number): InvocationAttempt
    {
        return InvocationAttempt::start(
            InvocationAttemptId::fromString('attempt-1'),
            InvocationId::fromString('invocation-1'),
            RunId::fromString('run-1'),
            $number,
            'fingerprint',
            new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
        );
    }
}
