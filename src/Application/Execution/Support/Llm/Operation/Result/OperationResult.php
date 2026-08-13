<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Operation\Result;

use Rick\Laravel\Domain\Run\Artifact;

final readonly class OperationResult
{
    /** @param non-empty-list<Artifact> $artifacts */
    public function __construct(public array $artifacts) {}
}
