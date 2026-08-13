<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;

final readonly class InvocationOutcome
{
    public function __construct(
        public InvocationId $invocationId,
        public int $originalIndex,
        public int $attempts,
        public InvocationStatus $status,
        public ?CompletionResponse $response,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}

    public static function fromInvocation(LlmInvocation $invocation): self
    {
        return new self(
            $invocation->id(),
            $invocation->index(),
            $invocation->attempts(),
            $invocation->status(),
            $invocation->response(),
            $invocation->errorCode(),
            $invocation->errorMessage(),
        );
    }
}
