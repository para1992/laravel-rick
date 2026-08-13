<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Metrics;

use Closure;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationAttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\MetricTotals;
use Rick\Laravel\Domain\Metrics\ValueObject\RunMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

final readonly class RunMetricsProjection
{
    public function __construct(private ExecutionRepositoryBase $executions) {}

    public function for(WorkflowRunSnapshot $run): RunMetrics
    {
        $invocations = $this->executions->invocationsForRun($run->id);
        $attempts = $this->executions->attemptsForRun($run->id);

        return new RunMetrics(
            $run->id,
            $run->status,
            $run->version,
            $run->callsUsed,
            $run->callLimit,
            self::totals($invocations, $attempts),
            self::groups(
                $invocations,
                $attempts,
                static fn (LlmInvocation $item): string => $item->request()->purpose,
            ),
            self::groups(
                $invocations,
                $attempts,
                static fn (LlmInvocation $item): string => $item->request()->modelTier,
            ),
            self::groups(
                $invocations,
                $attempts,
                static function (LlmInvocation $item, array $itemAttempts): string {
                    $metrics = self::latestMetrics($itemAttempts);
                    if ($metrics !== null) {
                        return $metrics->provider.':'.$metrics->model;
                    }
                    $response = $item->response();

                    return $response === null ? 'unknown:unknown' : $response->provider.':'.$response->model;
                },
            ),
            self::groups(
                $invocations,
                $attempts,
                static fn (LlmInvocation $item): string => $item->stepId()->toString(),
            ),
            array_map(
                static fn (LlmInvocation $invocation): InvocationMetrics => self::invocation(
                    $invocation,
                    self::attemptsFor($invocation, $attempts),
                ),
                $invocations,
            ),
        );
    }

    /**
     * @param  list<LlmInvocation>  $invocations
     * @param  list<InvocationAttempt>  $attempts
     */
    private static function totals(array $invocations, array $attempts): MetricTotals
    {
        $statuses = [
            'succeeded' => 0,
            'failed' => 0,
            'pending' => 0,
            'running' => 0,
            'indeterminate' => 0,
        ];
        $attemptCount = 0;
        $providerRequests = 0;
        $measured = 0;
        $unmeasured = 0;
        $incomplete = 0;
        $unpriced = 0;
        $measuredAttempts = 0;
        $incompleteAttempts = 0;
        $unpricedAttempts = 0;
        $promptCharacters = 0;
        $responseCharacters = 0;
        $latency = 0;
        $tokens = TokenUsage::zero();
        $cost = InvocationCost::zero();

        foreach ($invocations as $invocation) {
            $status = $invocation->status()->value;
            $statuses[$status]++;
            $itemAttempts = self::attemptsFor($invocation, $attempts);
            $attemptCount += $itemAttempts === [] ? $invocation->attempts() : count($itemAttempts);
            $itemMetrics = array_values(array_filter(array_map(
                static fn (InvocationAttempt $attempt): ?AttemptMetrics => $attempt->metrics(),
                $itemAttempts,
            )));

            if ($itemMetrics !== []) {
                $itemIncomplete = false;
                $itemUnpriced = false;
                foreach ($itemMetrics as $metrics) {
                    $measuredAttempts++;
                    $providerRequests += $metrics->providerRequests;
                    $tokens = $tokens->plus($metrics->tokens);
                    $cost = $cost->plus($metrics->cost ?? InvocationCost::zero());
                    $latency += $metrics->latencyMilliseconds ?? 0;
                    $promptCharacters += $metrics->promptCharacters;
                    $responseCharacters += $metrics->responseCharacters;
                    $missingUsage = ! $metrics->usagePresent || ! $metrics->usageComplete;
                    $incompleteAttempts += $missingUsage ? 1 : 0;
                    $unpricedAttempts += $metrics->cost === null ? 1 : 0;
                    $itemIncomplete = $itemIncomplete || $missingUsage;
                    $itemUnpriced = $itemUnpriced || $metrics->cost === null;
                }
                if ($status === 'succeeded') {
                    $measured++;
                    $incomplete += $itemIncomplete ? 1 : 0;
                    $unpriced += $itemUnpriced ? 1 : 0;
                }

                continue;
            }

            if ($invocation->isReused()) {
                continue;
            }

            $promptLength = array_sum(array_map(
                static fn ($message): int => strlen($message->content),
                $invocation->request()->messages,
            ));
            $promptCharacters += $promptLength * $invocation->attempts();
            $response = $invocation->response();
            $responseCharacters += $response === null ? 0 : strlen($response->text);
            $metrics = $invocation->metrics();
            if ($metrics !== null) {
                $providerRequests += max(0, $invocation->attempts() - 1) + $metrics->providerRequests;
                $tokens = $tokens->plus($metrics->tokens);
                $cost = $cost->plus($metrics->cost ?? InvocationCost::zero());
                $latency += $metrics->latencyMilliseconds ?? 0;
                if ($status === 'succeeded') {
                    $measured++;
                    $missingUsage = ! $metrics->usagePresent || ! $metrics->usageComplete;
                    $incomplete += $missingUsage || $invocation->attempts() !== 1 ? 1 : 0;
                    $unpriced += $metrics->cost === null ? 1 : 0;
                }
            } else {
                $providerRequests += $invocation->attempts();
                $unmeasured += $status === 'succeeded' ? 1 : 0;
                $unpriced += $status === 'succeeded' ? 1 : 0;
            }
        }

        return new MetricTotals(
            count($invocations),
            $statuses['succeeded'],
            $statuses['failed'],
            $statuses['pending'],
            $statuses['running'],
            $statuses['indeterminate'],
            $attemptCount,
            $providerRequests,
            $measured,
            $unmeasured,
            $incomplete,
            $unpriced,
            $promptCharacters,
            $responseCharacters,
            $latency,
            $tokens,
            $cost,
            $measuredAttempts,
            $incompleteAttempts,
            $unpricedAttempts,
        );
    }

    /**
     * @param  list<LlmInvocation>  $invocations
     * @param  list<InvocationAttempt>  $attempts
     * @param  Closure(LlmInvocation, list<InvocationAttempt>): string  $key
     * @return array<string, MetricTotals>
     */
    private static function groups(array $invocations, array $attempts, Closure $key): array
    {
        $groups = [];
        foreach ($invocations as $invocation) {
            $groups[$key($invocation, self::attemptsFor($invocation, $attempts))][] = $invocation;
        }
        ksort($groups);

        return array_map(
            static function (array $items) use ($attempts): MetricTotals {
                $ids = array_fill_keys(array_map(
                    static fn (LlmInvocation $item): string => $item->id()->toString(),
                    $items,
                ), true);

                return self::totals($items, array_values(array_filter(
                    $attempts,
                    static fn (InvocationAttempt $attempt): bool => isset(
                        $ids[$attempt->invocationId()->toString()],
                    ),
                )));
            },
            $groups,
        );
    }

    /** @param list<InvocationAttempt> $attempts */
    private static function invocation(LlmInvocation $invocation, array $attempts): InvocationMetrics
    {
        $response = $invocation->response();
        $attemptMetrics = array_values(array_filter(array_map(
            static fn (InvocationAttempt $attempt): ?AttemptMetrics => $attempt->metrics(),
            $attempts,
        )));
        $latest = self::latestMetrics($attempts);
        $tokens = TokenUsage::zero();
        $cost = InvocationCost::zero();
        $hasCost = false;
        $latency = 0;
        $hasLatency = false;
        $providerRequests = 0;
        $usagePresent = false;
        $usageComplete = $attemptMetrics !== [];
        foreach ($attemptMetrics as $metrics) {
            $tokens = $tokens->plus($metrics->tokens);
            if ($metrics->cost !== null) {
                $cost = $cost->plus($metrics->cost);
                $hasCost = true;
            }
            if ($metrics->latencyMilliseconds !== null) {
                $latency += $metrics->latencyMilliseconds;
                $hasLatency = true;
            }
            $providerRequests += $metrics->providerRequests;
            $usagePresent = $usagePresent || $metrics->usagePresent;
            $usageComplete = $usageComplete && $metrics->usagePresent && $metrics->usageComplete;
        }
        $legacy = $attemptMetrics === [] ? $invocation->metrics() : null;

        return new InvocationMetrics(
            $invocation->id(),
            $invocation->stepId(),
            $invocation->index(),
            $invocation->status(),
            $invocation->attempts(),
            $invocation->request()->purpose,
            $invocation->request()->modelTier,
            $latest === null ? $response?->provider : $latest->provider,
            $latest === null ? $response?->model : $latest->model,
            $attemptMetrics === [] ? $legacy?->tokens : $tokens,
            $attemptMetrics === [] ? $legacy?->cost : ($hasCost ? $cost : null),
            $attemptMetrics === [] ? $legacy?->latencyMilliseconds : ($hasLatency ? $latency : null),
            $attemptMetrics === []
                ? ($legacy !== null && $legacy->usageComplete)
                : $usageComplete,
            $attemptMetrics === []
                ? ($legacy !== null && $legacy->usagePresent)
                : $usagePresent,
            $attemptMetrics === []
                ? ($legacy === null ? $invocation->attempts() : $legacy->providerRequests)
                : $providerRequests,
            array_map(
                static fn (InvocationAttempt $attempt): InvocationAttemptMetrics => new InvocationAttemptMetrics(
                    $attempt->id(),
                    $attempt->number(),
                    $attempt->status(),
                    $attempt->startedAt(),
                    $attempt->finishedAt(),
                    $attempt->providerIdentifiers(),
                    $attempt->metrics(),
                    $attempt->diagnostic(),
                    $attempt->outcome(),
                    $attempt->errorCode(),
                    $attempt->httpStatusClass(),
                ),
                $attempts,
            ),
            $invocation->sourceRunId(),
            $invocation->sourceInvocationId(),
        );
    }

    /**
     * @param  list<InvocationAttempt>  $attempts
     * @return list<InvocationAttempt>
     */
    private static function attemptsFor(LlmInvocation $invocation, array $attempts): array
    {
        return array_values(array_filter(
            $attempts,
            static fn (InvocationAttempt $attempt): bool => $attempt->invocationId()->toString()
                === $invocation->id()->toString(),
        ));
    }

    /** @param list<InvocationAttempt> $attempts */
    private static function latestMetrics(array $attempts): ?AttemptMetrics
    {
        for ($index = count($attempts) - 1; $index >= 0; $index--) {
            $metrics = $attempts[$index]->metrics();
            if ($metrics !== null) {
                return $metrics;
            }
        }

        return null;
    }
}
