<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Support\LaravelTenantContext;

final class IdentifierTest extends TestCase
{
    /** @return iterable<string, array{Closure(string): string}> */
    public static function identifiers(): iterable
    {
        yield 'tenant' => [static fn (string $value): string => (new LaravelTenantContext($value))->id()];
        yield 'run' => [static fn (string $value): string => RunId::fromString($value)->toString()];
        yield 'step' => [static fn (string $value): string => StepId::fromString($value)->toString()];
        yield 'candidate' => [static fn (string $value): string => CandidateId::fromString($value)->toString()];
        yield 'step execution' => [static fn (string $value): string => StepExecutionId::fromString($value)->toString()];
        yield 'invocation' => [static fn (string $value): string => InvocationId::fromString($value)->toString()];
        yield 'invocation attempt' => [static fn (string $value): string => InvocationAttemptId::fromString($value)->toString()];
    }

    #[DataProvider('identifiers')]
    public function test_every_identifier_trims_and_accepts_exactly_128_unicode_characters(Closure $normalize): void
    {
        $boundary = str_repeat('ą', 128);

        self::assertSame('trimmed', $normalize('  trimmed  '));
        self::assertSame($boundary, $normalize($boundary));
    }

    #[DataProvider('identifiers')]
    public function test_every_identifier_rejects_129_unicode_characters(Closure $normalize): void
    {
        $this->expectException(InvalidArgumentException::class);

        $normalize(str_repeat('ą', 129));
    }

    #[DataProvider('identifiers')]
    public function test_every_identifier_rejects_empty_values(Closure $normalize): void
    {
        $this->expectException(InvalidArgumentException::class);

        $normalize(" \t\n ");
    }

    #[DataProvider('identifiers')]
    public function test_every_identifier_rejects_invalid_utf8(Closure $normalize): void
    {
        $this->expectException(InvalidArgumentException::class);

        $normalize("\xC3\x28");
    }
}
