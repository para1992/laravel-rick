<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;
use Rick\Laravel\Facade\Rick as RickFacade;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class RickTest extends TestCase
{
    public function test_it_compiles_a_domain_workflow_through_dependency_injection(): void
    {
        $compiled = $this->application()->make(Rick::class)->compile($this->workflow());

        self::assertSame('public-api', $compiled->name);
        self::assertSame('1.0.0', $compiled->version);
        self::assertSame(2, $compiled->count());
        self::assertInstanceOf(OutputGlueStep::class, $compiled->steps[1]);
    }

    public function test_it_compiles_a_domain_workflow_through_the_laravel_facade(): void
    {
        $compiled = RickFacade::compile($this->workflow());

        self::assertSame('public-api', $compiled->name);
        self::assertInstanceOf(OutputGlueStep::class, $compiled->steps[1]);
    }

    private function workflow(): WorkflowDefinition
    {
        return new WorkflowDefinition('public-api', '1.0.0', [
            new ResolveStep(
                StepId::fromString('001_resolve'),
                'Write',
                DefinitionOfDone::fromString('Return the completed result'),
            ),
        ]);
    }
}
