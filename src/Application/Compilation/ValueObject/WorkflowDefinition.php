<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\ValueObject;

use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition as DomainWorkflowDefinition;

final readonly class WorkflowDefinition implements DefinitionBase
{
    public function __construct(
        public DomainWorkflowDefinition $workflow,
    ) {}
}
