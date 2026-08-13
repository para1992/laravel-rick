<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Recipe\Interface;

use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

interface WorkflowRecipeBase
{
    public function id(): string;

    public function version(): string;

    public function description(): string;

    /** @param array<string, mixed> $configuration */
    public function build(array $configuration): WorkflowDefinition;
}
