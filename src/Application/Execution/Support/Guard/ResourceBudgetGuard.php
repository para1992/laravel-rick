<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Guard;

use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\PricingBase;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Domain\Exception\ResourceBudgetExceededException;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

final readonly class ResourceBudgetGuard
{
    public function __construct(
        private ExecutionRepositoryBase $executions,
        private ClockBase $clock,
        private PricingBase $pricing,
    ) {}

    public function assertCurrent(WorkflowRunSnapshot $run): void
    {
        $budget = $run->resourceBudget;
        if ($budget === null || $budget->isUnbounded()) {
            return;
        }

        if ($budget->maxDurationMilliseconds !== null && $run->startedAt !== null) {
            $elapsed = max(0, (int) round(
                (((float) $this->clock->now()->format('U.u'))
                    - ((float) $run->startedAt->format('U.u'))) * 1000,
            ));
            self::assertLimit('duration_milliseconds', $elapsed, $budget->maxDurationMilliseconds);
        }

        $usage = $this->usage($run);
        if ($budget->requireCompleteMetrics && $usage['incomplete'] > 0) {
            throw new ResourceBudgetExceededException('complete_metrics', $usage['incomplete'], 0);
        }
        self::assertLimit('input_tokens', $usage['tokens']->inputTokens, $budget->maxInputTokens);
        self::assertLimit('output_tokens', $usage['tokens']->outputTokens, $budget->maxOutputTokens);
        self::assertLimit('total_tokens', $usage['tokens']->totalTokens, $budget->maxTotalTokens);
        self::assertLimit('latency_milliseconds', $usage['latency'], $budget->maxLatencyMilliseconds);

        if (
            $budget->maxCost !== null
            && $usage['cost']->usdNanodollars > $budget->maxCost->usdNanodollars
        ) {
            throw new ResourceBudgetExceededException(
                'cost_usd',
                $usage['cost']->toUsdDecimal(),
                $budget->maxCost->toUsdDecimal(),
            );
        }
    }

    /** @param non-empty-list<CompletionRequest> $requests */
    public function assertCanDispatch(
        WorkflowRunSnapshot $run,
        array $requests,
        ?AttemptMetrics $pendingAttempt = null,
        ?ProviderRequestOutcome $pendingOutcome = null,
    ): void {
        $this->assertCurrent($run);
        $budget = $run->resourceBudget;
        if ($budget === null || $budget->isUnbounded()) {
            return;
        }

        $actual = $this->usage($run, $pendingAttempt, $pendingOutcome);
        if ($budget->requireCompleteMetrics && $actual['incomplete'] > 0) {
            throw new ResourceBudgetExceededException('complete_metrics', $actual['incomplete'], 0);
        }
        self::assertLimit(
            'latency_milliseconds',
            $actual['latency'],
            $budget->maxLatencyMilliseconds,
        );
        $usage = $actual['tokens'];
        $reservedInput = 0;
        $reservedOutput = 0;
        $reservedCost = InvocationCost::zero();
        foreach ($requests as $request) {
            $requestInput = 0;
            foreach ($request->messages as $message) {
                $requestInput += max(1, (int) ceil(strlen($message->content) / 4));
            }
            $configured = $request->options['max_output_tokens']
                ?? $request->options['max_tokens']
                ?? null;
            $requestOutput = is_numeric($configured)
                ? max(1, (int) $configured)
                : $budget->defaultOutputReservationTokens;
            $reservedInput += $requestInput;
            $reservedOutput += $requestOutput;
            $estimate = $this->pricing->estimate($request->modelTier, $requestInput, $requestOutput);
            if ($estimate === null && $budget->maxCost !== null && $budget->requireKnownPricing) {
                throw new ResourceBudgetExceededException('known_pricing', 'unknown', 'required');
            }
            $reservedCost = $reservedCost->plus($estimate ?? InvocationCost::zero());
        }

        self::assertLimit(
            'input_tokens',
            $usage->inputTokens + $reservedInput,
            $budget->maxInputTokens,
        );
        self::assertLimit(
            'output_tokens',
            $usage->outputTokens + $reservedOutput,
            $budget->maxOutputTokens,
        );
        self::assertLimit(
            'total_tokens',
            $usage->totalTokens + $reservedInput + $reservedOutput,
            $budget->maxTotalTokens,
        );
        if ($budget->maxCost !== null) {
            $cost = $actual['cost']->plus($reservedCost);
            if ($cost->usdNanodollars > $budget->maxCost->usdNanodollars) {
                throw new ResourceBudgetExceededException(
                    'cost_usd',
                    $cost->toUsdDecimal(),
                    $budget->maxCost->toUsdDecimal(),
                );
            }
        }
    }

    /**
     * @return array{tokens: TokenUsage, cost: InvocationCost, latency: int, incomplete: int}
     */
    private function usage(
        WorkflowRunSnapshot $run,
        ?AttemptMetrics $pendingAttempt = null,
        ?ProviderRequestOutcome $pendingOutcome = null,
    ): array {
        $tokens = TokenUsage::zero();
        $cost = InvocationCost::zero();
        $latency = 0;
        $incomplete = 0;

        $attempts = $this->executions->attemptsForRun($run->id);
        $attemptsByInvocation = [];
        foreach ($attempts as $attempt) {
            if ($attempt->metrics() === null) {
                continue;
            }
            $attemptsByInvocation[$attempt->invocationId()->toString()][] = $attempt;
        }

        foreach ($this->executions->invocationsForRun($run->id) as $invocation) {
            if ($invocation->isReused()) {
                continue;
            }
            $measuredAttempts = $attemptsByInvocation[$invocation->id()->toString()] ?? [];
            if ($measuredAttempts !== []) {
                foreach ($measuredAttempts as $attempt) {
                    /** @var AttemptMetrics $metrics */
                    $metrics = $attempt->metrics();
                    $tokens = $tokens->plus($metrics->tokens);
                    $cost = $cost->plus($metrics->cost ?? InvocationCost::zero());
                    $latency += $metrics->latencyMilliseconds ?? 0;
                    $incomplete += self::isIncomplete($metrics, $attempt->outcome()) ? 1 : 0;
                    $incomplete += self::isUnpriced(
                        $metrics,
                        $attempt->outcome(),
                        $run->resourceBudget?->requireKnownPricing === true,
                    ) ? 1 : 0;
                }

                continue;
            }

            $metrics = $invocation->metrics();
            if ($metrics === null) {
                $incomplete += $invocation->response() === null ? 0 : 1;

                continue;
            }
            $tokens = $tokens->plus($metrics->tokens);
            $cost = $cost->plus($metrics->cost ?? InvocationCost::zero());
            $latency += $metrics->latencyMilliseconds ?? 0;
            $incomplete += $metrics->usagePresent && $metrics->usageComplete ? 0 : 1;
            $incomplete += $run->resourceBudget?->requireKnownPricing === true && $metrics->cost === null ? 1 : 0;
        }

        if ($pendingAttempt !== null) {
            $tokens = $tokens->plus($pendingAttempt->tokens);
            $cost = $cost->plus($pendingAttempt->cost ?? InvocationCost::zero());
            $latency += $pendingAttempt->latencyMilliseconds ?? 0;
            $incomplete += self::isIncomplete($pendingAttempt, $pendingOutcome) ? 1 : 0;
            $incomplete += self::isUnpriced(
                $pendingAttempt,
                $pendingOutcome,
                $run->resourceBudget?->requireKnownPricing === true,
            ) ? 1 : 0;
        }

        return compact('tokens', 'cost', 'latency', 'incomplete');
    }

    private static function isIncomplete(
        AttemptMetrics $metrics,
        ?ProviderRequestOutcome $outcome,
    ): bool {
        return $outcome !== ProviderRequestOutcome::NotAccepted
            && (! $metrics->usagePresent || ! $metrics->usageComplete);
    }

    private static function isUnpriced(
        AttemptMetrics $metrics,
        ?ProviderRequestOutcome $outcome,
        bool $requireKnownPricing,
    ): bool {
        return $requireKnownPricing
            && $outcome !== ProviderRequestOutcome::NotAccepted
            && $metrics->cost === null;
    }

    private static function assertLimit(string $resource, int $actual, ?int $limit): void
    {
        if ($limit !== null && $actual > $limit) {
            throw new ResourceBudgetExceededException($resource, $actual, $limit);
        }
    }
}
