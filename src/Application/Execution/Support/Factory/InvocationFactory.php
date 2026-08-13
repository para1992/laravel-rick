<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Factory;

use Rick\Laravel\Application\Execution\Support\Llm\PromptBounds;
use Rick\Laravel\Application\Execution\ValueObject\InvocationBatch;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class InvocationFactory
{
    public function __construct(private IdGeneratorBase $ids, private PromptBounds $prompts) {}

    /** @param non-empty-list<CompletionRequest> $requests */
    public function create(
        RunId $runId,
        StepId $stepId,
        array $requests,
        ?InvocationCompletionPolicy $completionPolicy = null,
    ): InvocationBatch {
        $execution = StepExecution::waiting(
            StepExecutionId::fromString($this->ids->generate()),
            $runId,
            $stepId,
            count($requests),
            $completionPolicy,
        );
        $invocations = [];

        foreach ($requests as $index => $request) {
            $request = $this->prompts->apply($request);
            $invocations[] = LlmInvocation::pending(
                InvocationId::fromString($this->ids->generate()),
                $execution->id(),
                $runId,
                $stepId,
                $index,
                $request,
            );
        }

        return new InvocationBatch($execution, $invocations);
    }
}
