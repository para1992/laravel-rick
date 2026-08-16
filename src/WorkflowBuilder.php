<?php

declare(strict_types=1);

namespace Rick\Laravel;

use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder as InternalWorkflowBuilder;

/**
 * The public, application-facing workflow builder. It extends the internal
 * compilation builder so consumers never import the deep
 * Application\Compilation namespace to declare a workflow.
 */
class WorkflowBuilder extends InternalWorkflowBuilder {}
