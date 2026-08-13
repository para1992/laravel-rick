<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\ValueObject;

use InvalidArgumentException;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;

final readonly class InvocationBatch
{
    /** @param list<LlmInvocation> $invocations */
    public function __construct(
        public StepExecution $execution,
        public array $invocations,
    ) {
        if ($invocations === []) {
            throw new InvalidArgumentException('An invocation batch cannot be empty.');
        }
    }
}
