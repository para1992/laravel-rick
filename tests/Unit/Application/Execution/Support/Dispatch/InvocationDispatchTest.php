<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Dispatch;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Dispatch\InvocationDispatch;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class InvocationDispatchTest extends TestCase
{
    public function test_dispatches_only_the_configured_number_of_active_invocations(): void
    {
        $execution = StepExecution::waiting(
            StepExecutionId::fromString('execution-1'),
            RunId::fromString('run-1'),
            StepId::fromString('parallel-1'),
            3,
        );
        $invocations = array_map(
            static fn (int $index): LlmInvocation => LlmInvocation::pending(
                InvocationId::fromString('invocation-'.$index),
                $execution->id(),
                $execution->runId(),
                $execution->stepId(),
                $index,
                new CompletionRequest([], ResponseContract::Text, 'parallel'),
            ),
            [1, 2, 3],
        );
        $dispatch = new InvocationDispatch(2);

        self::assertCount(2, $dispatch->next($execution, $invocations));
        self::assertSame([], $dispatch->next($execution, $invocations));
        self::assertSame(2, $execution->dispatchedInvocations());
    }
}
