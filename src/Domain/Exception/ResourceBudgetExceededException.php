<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Exception;

final class ResourceBudgetExceededException extends ExceptionBase
{
    public function __construct(
        public readonly string $resource,
        public readonly int|string $actual,
        public readonly int|string $limit,
    ) {
        parent::__construct(
            sprintf('Resource budget [%s] exceeded: %s > %s.', $resource, $actual, $limit),
            'resource_budget_exceeded',
        );
    }
}
