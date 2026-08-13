<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseStage;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationAttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\MetricTotals;
use Rick\Laravel\Domain\Metrics\ValueObject\RunMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class MetricsValueObjectTest extends TestCase
{
    public function test_invocation_cost_preserves_nanodollar_rounding_and_arithmetic(): void
    {
        self::assertSame(0, InvocationCost::zero()->usdNanodollars);
        self::assertSame(1, InvocationCost::fromUsd('0.0000000005')->usdNanodollars);
        self::assertSame(1_000_000_000, InvocationCost::fromUsd('0.9999999999')->usdNanodollars);
        self::assertSame(1_250_000_000, InvocationCost::fromUsd('1.25')->usdNanodollars);
        self::assertSame(1_000, InvocationCost::fromUsd('1e-6')->usdNanodollars);
        self::assertSame('1.25', InvocationCost::fromUsd('1.25')->toUsdDecimal());
        self::assertSame('2.5', InvocationCost::fromUsd('1.25')->plus(InvocationCost::fromUsd('1.25'))->toUsdDecimal());
        self::assertSame('0.003', InvocationCost::fromUsd('2')->multiplyTokens(1_500)->toUsdDecimal());
        self::assertSame('3.75', InvocationCost::fromUsd('1.25')->times(3)->toUsdDecimal());
        self::assertSame('0', InvocationCost::fromUsd('2')->multiplyTokens(0)->toUsdDecimal());
        self::assertSame('0', InvocationCost::fromUsd('2')->times(0)->toUsdDecimal());
        self::assertSame('0', InvocationCost::zero()->times(100)->toUsdDecimal());
        self::assertSame(2, (new InvocationCost(1))->times(2)->usdNanodollars);
        self::assertSame(5, (new InvocationCost(5))->times(1)->usdNanodollars);
        self::assertSame(
            intdiv(PHP_INT_MAX, 2) * 2,
            (new InvocationCost(intdiv(PHP_INT_MAX, 2)))->times(2)->usdNanodollars,
        );
        self::assertSame(1, (new InvocationCost(1))->multiplyTokens(1_000_000)->usdNanodollars);
        self::assertSame(5, (new InvocationCost(2))->plus(new InvocationCost(3))->usdNanodollars);
    }

    #[DataProvider('invalidCosts')]
    public function test_invocation_cost_rejects_invalid_and_overflowing_values(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidCosts(): iterable
    {
        yield 'negative constructor' => [static fn () => new InvocationCost(-1)];
        yield 'empty USD' => [static fn () => InvocationCost::fromUsd(' ')];
        yield 'non numeric USD' => [static fn () => InvocationCost::fromUsd('nope')];
        yield 'negative USD' => [static fn () => InvocationCost::fromUsd('-1')];
        yield 'unsupported decimal' => [static fn () => InvocationCost::fromUsd('1,2')];
        yield 'USD overflow' => [static fn () => InvocationCost::fromUsd((string) PHP_INT_MAX)];
        yield 'addition overflow' => [static fn () => (new InvocationCost(PHP_INT_MAX))->plus(new InvocationCost(1))];
        yield 'negative tokens' => [static fn () => InvocationCost::zero()->multiplyTokens(-1)];
        yield 'token multiplication overflow' => [static fn () => (new InvocationCost(PHP_INT_MAX))->multiplyTokens(2)];
        yield 'negative multiplier' => [static fn () => InvocationCost::zero()->times(-1)];
        yield 'integer multiplication overflow' => [static fn () => (new InvocationCost(PHP_INT_MAX))->times(2)];
    }

    public function test_attempt_metrics_validate_every_boundary_and_serialize_exactly(): void
    {
        $metrics = $this->attemptMetrics();

        self::assertSame([
            'schema_version' => 1,
            'provider' => 'openrouter',
            'model' => 'model-a',
            'resolved_route' => 'openrouter:model-a',
            'model_tier' => 'quality',
            'tokens' => [
                'input_tokens' => 10,
                'output_tokens' => 20,
                'total_tokens' => 30,
                'cached_input_tokens' => 2,
                'cache_write_input_tokens' => 3,
                'reasoning_tokens' => 4,
            ],
            'cost_usd' => '0.25',
            'latency_milliseconds' => 125,
            'provider_requests' => 2,
            'usage_present' => true,
            'usage_complete' => true,
            'prompt_characters' => 40,
            'response_characters' => 50,
        ], $metrics->toArray());
        self::assertSame($metrics->toArray(), $metrics->jsonSerialize());
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidAttemptMetrics')]
    public function test_attempt_metrics_reject_invalid_boundaries(array $overrides): void
    {
        $arguments = [
            'provider' => 'provider',
            'model' => 'model',
            'resolvedRoute' => 'provider:model',
            'modelTier' => 'quality',
            'tokens' => TokenUsage::zero(),
            'cost' => null,
            'latencyMilliseconds' => 0,
            'providerRequests' => 1,
            'usagePresent' => true,
            'usageComplete' => true,
            'promptCharacters' => 0,
            'responseCharacters' => 0,
        ];

        $this->expectException(InvalidArgumentException::class);
        (new ReflectionClass(AttemptMetrics::class))->newInstanceArgs(array_values(
            array_replace($arguments, $overrides),
        ));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidAttemptMetrics(): iterable
    {
        foreach (['provider', 'model', 'resolvedRoute', 'modelTier'] as $name) {
            yield "empty {$name}" => [[$name => ' ']];
        }
        yield 'negative latency' => [['latencyMilliseconds' => -1]];
        yield 'zero requests' => [['providerRequests' => 0]];
        yield 'negative prompt characters' => [['promptCharacters' => -1]];
        yield 'negative response characters' => [['responseCharacters' => -1]];
        yield 'complete absent usage' => [['usagePresent' => false, 'usageComplete' => true]];
    }

    public function test_attempt_invocation_totals_and_run_metrics_serialize_present_and_absent_data(): void
    {
        $attempt = new InvocationAttemptMetrics(
            InvocationAttemptId::fromString('attempt-1'),
            2,
            InvocationAttemptStatus::Succeeded,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            new DateTimeImmutable('2026-08-01T10:00:01+00:00'),
            new ProviderIdentifiers('gateway-1', 'request-1', 'generation-1', ProviderIdSource::Sdk),
            $this->attemptMetrics(),
            $this->diagnostic(),
            ProviderRequestOutcome::ResponseReceived,
            null,
            '2xx',
        );
        $attemptArray = $attempt->toArray();
        self::assertSame([
            'schema_version' => 1,
            'id' => 'attempt-1',
            'number' => 2,
            'status' => 'succeeded',
            'started_at' => '2026-08-01T10:00:00+00:00',
            'finished_at' => '2026-08-01T10:00:01+00:00',
            'gateway_invocation_id' => 'gateway-1',
            'provider_request_id' => 'request-1',
            'provider_generation_id' => 'generation-1',
            'provider_id_source' => 'sdk',
            'provider_request_outcome' => 'response_received',
            'provider' => 'openrouter',
            'model' => 'model-a',
            'resolved_route' => 'openrouter:model-a',
            'model_tier' => 'quality',
            'tokens' => $this->attemptMetrics()->tokens->toArray(),
            'cost_usd' => '0.25',
            'latency_milliseconds' => 125,
            'provider_requests' => 2,
            'usage_present' => true,
            'usage_complete' => true,
            'prompt_characters' => 40,
            'response_characters' => 50,
            'error_code' => null,
            'http_status_class' => '2xx',
            'diagnostic' => $this->diagnostic()->toArray(),
        ], $attemptArray);
        self::assertSame($attemptArray, $attempt->jsonSerialize());

        $absent = new InvocationAttemptMetrics(
            InvocationAttemptId::fromString('attempt-2'),
            1,
            InvocationAttemptStatus::Running,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            null,
            null,
            null,
            null,
            null,
            'transport',
            null,
        );
        self::assertSame([
            'schema_version' => 1,
            'id' => 'attempt-2',
            'number' => 1,
            'status' => 'running',
            'started_at' => '2026-08-01T10:00:00+00:00',
            'finished_at' => null,
            'gateway_invocation_id' => null,
            'provider_request_id' => null,
            'provider_generation_id' => null,
            'provider_id_source' => null,
            'provider_request_outcome' => null,
            'provider' => null,
            'model' => null,
            'resolved_route' => null,
            'model_tier' => null,
            'tokens' => null,
            'cost_usd' => null,
            'latency_milliseconds' => null,
            'provider_requests' => 0,
            'usage_present' => false,
            'usage_complete' => false,
            'prompt_characters' => 0,
            'response_characters' => 0,
            'error_code' => 'transport',
            'http_status_class' => null,
            'diagnostic' => null,
        ], $absent->toArray());

        $totals = $this->totals();
        $totalsArray = [
            'schema_version' => 2,
            'calls' => 11,
            'succeeded_calls' => 12,
            'failed_calls' => 13,
            'pending_calls' => 14,
            'running_calls' => 15,
            'indeterminate_calls' => 16,
            'attempts' => 17,
            'provider_requests' => 18,
            'measured_succeeded_calls' => 19,
            'unmeasured_succeeded_calls' => 20,
            'incomplete_usage_calls' => 21,
            'unpriced_succeeded_calls' => 22,
            'prompt_characters' => 23,
            'response_characters' => 24,
            'latency_milliseconds' => 25,
            'tokens' => (new TokenUsage(10, 20))->toArray(),
            'cost_usd' => '0.25',
            'measured_attempts' => 19,
            'incomplete_usage_attempts' => 20,
            'unpriced_attempts' => 21,
        ];
        self::assertSame($totalsArray, $totals->toArray());
        self::assertSame($totals->toArray(), $totals->jsonSerialize());

        $invocation = new InvocationMetrics(
            InvocationId::fromString('invocation-1'),
            StepId::fromString('step-1'),
            3,
            InvocationStatus::Succeeded,
            0,
            'draft',
            'quality',
            'openrouter',
            'model-a',
            new TokenUsage(10, 20),
            InvocationCost::fromUsd('0.25'),
            125,
            true,
            true,
            2,
            [$attempt],
            RunId::fromString('source-run'),
            InvocationId::fromString('source-invocation'),
        );
        $invocationArray = [
            'schema_version' => 2,
            'id' => 'invocation-1',
            'step_id' => 'step-1',
            'index' => 3,
            'status' => 'succeeded',
            'attempts' => 0,
            'purpose' => 'draft',
            'model_tier' => 'quality',
            'provider' => 'openrouter',
            'model' => 'model-a',
            'tokens' => (new TokenUsage(10, 20))->toArray(),
            'cost_usd' => '0.25',
            'latency_milliseconds' => 125,
            'provider_requests' => 2,
            'usage_present' => true,
            'usage_complete' => true,
            'attempt_details' => [$attemptArray],
            'reused' => true,
            'source_run_id' => 'source-run',
            'source_invocation_id' => 'source-invocation',
        ];
        self::assertSame($invocationArray, $invocation->toArray());
        self::assertSame($invocationArray, $invocation->jsonSerialize());

        $ordinary = new InvocationMetrics(
            InvocationId::fromString('invocation-2'),
            StepId::fromString('step-2'),
            0,
            InvocationStatus::Failed,
            1,
            'judge',
            'fast',
            null,
            null,
            null,
            null,
            null,
            false,
            false,
            1,
            [],
        );
        self::assertSame([
            'schema_version' => 2,
            'id' => 'invocation-2',
            'step_id' => 'step-2',
            'index' => 0,
            'status' => 'failed',
            'attempts' => 1,
            'purpose' => 'judge',
            'model_tier' => 'fast',
            'provider' => null,
            'model' => null,
            'tokens' => null,
            'cost_usd' => null,
            'latency_milliseconds' => null,
            'provider_requests' => 1,
            'usage_present' => false,
            'usage_complete' => false,
            'attempt_details' => [],
        ], $ordinary->toArray());

        $run = new RunMetrics(
            RunId::fromString('run-1'),
            RunStatus::Completed,
            7,
            2,
            10,
            $totals,
            ['draft' => $totals],
            ['quality' => $totals],
            ['model-a' => $totals],
            ['step-1' => $totals],
            [$invocation],
        );
        $runArray = [
            'schema_version' => 2,
            'run_id' => 'run-1',
            'status' => 'completed',
            'run_version' => 7,
            'calls_used' => 2,
            'call_limit' => 10,
            'totals' => $totalsArray,
            'by_purpose' => ['draft' => $totalsArray],
            'by_model_tier' => ['quality' => $totalsArray],
            'by_model' => ['model-a' => $totalsArray],
            'by_step' => ['step-1' => $totalsArray],
            'invocations' => [$invocationArray],
        ];
        self::assertSame($runArray, $run->toArray());
        self::assertSame($runArray, $run->jsonSerialize());
    }

    private function attemptMetrics(): AttemptMetrics
    {
        return new AttemptMetrics(
            'openrouter',
            'model-a',
            'openrouter:model-a',
            'quality',
            new TokenUsage(10, 20, cachedInputTokens: 2, cacheWriteInputTokens: 3, reasoningTokens: 4),
            InvocationCost::fromUsd('0.25'),
            125,
            2,
            true,
            true,
            40,
            50,
        );
    }

    private function totals(): MetricTotals
    {
        return new MetricTotals(
            calls: 11,
            succeededCalls: 12,
            failedCalls: 13,
            pendingCalls: 14,
            runningCalls: 15,
            indeterminateCalls: 16,
            attempts: 17,
            providerRequests: 18,
            measuredSucceededCalls: 19,
            unmeasuredSucceededCalls: 20,
            incompleteUsageCalls: 21,
            unpricedSucceededCalls: 22,
            promptCharacters: 23,
            responseCharacters: 24,
            latencyMilliseconds: 25,
            tokens: new TokenUsage(10, 20),
            cost: InvocationCost::fromUsd('0.25'),
            measuredAttempts: 19,
            incompleteUsageAttempts: 20,
            unpricedAttempts: 21,
        );
    }

    private function diagnostic(): StructuredResponseDiagnostic
    {
        return new StructuredResponseDiagnostic(
            StructuredResponseStage::SchemaValidation,
            ResponseContract::Candidate,
            str_repeat('a', 64),
            true,
            100,
            str_repeat('b', 64),
            StructuredDecodeStatus::Object,
            'object',
            '$.content',
            'required',
            'stop',
            true,
            true,
            'accepted',
        );
    }
}
