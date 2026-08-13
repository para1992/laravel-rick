<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Exception;

final class CallBudgetExceededException extends ExceptionBase
{
    public function __construct(int $next, int $limit, string $purpose)
    {
        parent::__construct(
            "LLM call budget exceeded: {$next}/{$limit} for {$purpose}.",
            'call_budget_exceeded',
        );
    }
}
