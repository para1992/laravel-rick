<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Interface;

interface ProviderRouteResolverBase
{
    public function identity(string $modelTier): string;
}
