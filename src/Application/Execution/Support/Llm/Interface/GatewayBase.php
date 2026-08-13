<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Interface;

use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;

interface GatewayBase
{
    public function complete(CompletionRequest $request): CompletionResponse;
}
