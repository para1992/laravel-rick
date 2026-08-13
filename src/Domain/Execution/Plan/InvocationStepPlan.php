<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\Plan;

use InvalidArgumentException;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;

final readonly class InvocationStepPlan implements StepPlanBase
{
    /** @var non-empty-list<CompletionRequest> */
    public array $requests;

    public InvocationCompletionPolicy $completionPolicy;

    /** @param list<CompletionRequest> $requests */
    public function __construct(
        array $requests,
        ?InvocationCompletionPolicy $completionPolicy = null,
    ) {
        if ($requests === []) {
            throw new InvalidArgumentException('An invocation plan requires at least one request.');
        }

        $this->requests = $requests;
        $this->completionPolicy = $completionPolicy ?? InvocationCompletionPolicy::allRequired();
        $this->completionPolicy->required(count($requests));
    }
}
