<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Interface;

use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationDefinition;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\OperationContext;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\Result\OperationResult;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;

interface LlmOperationBase
{
    public function definition(): LlmOperationDefinition;

    /** @return non-empty-list<CompletionRequest> */
    public function requests(OperationContext $context): array;

    /** @param non-empty-list<CompletionResponse> $responses */
    public function reduce(OperationContext $context, array $responses): OperationResult;
}
