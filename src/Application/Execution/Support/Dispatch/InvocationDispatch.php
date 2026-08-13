<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Dispatch;

use InvalidArgumentException;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;

final readonly class InvocationDispatch
{
    public function __construct(private int $maxInFlightInvocations = 20)
    {
        if ($maxInFlightInvocations < 1) {
            throw new InvalidArgumentException('Maximum in-flight invocations must be positive.');
        }
    }

    /** @param list<LlmInvocation> $invocations */
    public function failed(StepExecution $execution, array $invocations): ?LlmInvocation
    {
        foreach ($this->dispatched($execution, $invocations) as $invocation) {
            if ($invocation->status() === InvocationStatus::Failed) {
                return $invocation;
            }
        }

        return null;
    }

    /**
     * @param  list<LlmInvocation>  $invocations
     * @return list<LlmInvocation>
     */
    public function next(StepExecution $execution, array $invocations): array
    {
        $remaining = $execution->expectedInvocations() - $execution->dispatchedInvocations();
        $available = max(
            0,
            $this->maxInFlightInvocations - count($this->active($execution, $invocations)),
        );

        if ($remaining < 1 || $available < 1) {
            return [];
        }

        $next = array_slice(
            $invocations,
            $execution->dispatchedInvocations(),
            min($remaining, $available),
        );
        $execution->markDispatched(count($next));

        return $next;
    }

    /** @param list<LlmInvocation> $invocations */
    public function hasActive(StepExecution $execution, array $invocations): bool
    {
        return $this->active($execution, $invocations) !== [];
    }

    /** @param list<LlmInvocation> $invocations */
    public function activeCount(StepExecution $execution, array $invocations): int
    {
        return count($this->active($execution, $invocations));
    }

    /** @param list<LlmInvocation> $invocations */
    public function successfulCount(StepExecution $execution, array $invocations): int
    {
        return count(array_filter(
            $this->dispatched($execution, $invocations),
            static fn (LlmInvocation $invocation): bool => $invocation->status() === InvocationStatus::Succeeded,
        ));
    }

    public function undispatchedCount(StepExecution $execution): int
    {
        return $execution->expectedInvocations() - $execution->dispatchedInvocations();
    }

    /**
     * @param  list<LlmInvocation>  $invocations
     * @return list<string>
     */
    public function failureCodes(StepExecution $execution, array $invocations): array
    {
        $codes = [];
        foreach ($this->dispatched($execution, $invocations) as $invocation) {
            if ($invocation->status() !== InvocationStatus::Failed) {
                continue;
            }
            $codes[] = $invocation->errorCode() ?? 'llm_invocation_failed';
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /** @param list<LlmInvocation> $invocations */
    public function allSucceeded(StepExecution $execution, array $invocations): bool
    {
        if ($execution->dispatchedInvocations() !== $execution->expectedInvocations()) {
            return false;
        }

        foreach ($invocations as $invocation) {
            if ($invocation->status() !== InvocationStatus::Succeeded) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<LlmInvocation>  $invocations
     * @return list<LlmInvocation>
     */
    private function dispatched(StepExecution $execution, array $invocations): array
    {
        return array_slice($invocations, 0, $execution->dispatchedInvocations());
    }

    /**
     * @param  list<LlmInvocation>  $invocations
     * @return list<LlmInvocation>
     */
    private function active(StepExecution $execution, array $invocations): array
    {
        return array_values(array_filter(
            $this->dispatched($execution, $invocations),
            static fn (LlmInvocation $invocation): bool => in_array(
                $invocation->status(),
                [InvocationStatus::Pending, InvocationStatus::Running, InvocationStatus::Indeterminate],
                true,
            ),
        ));
    }
}
