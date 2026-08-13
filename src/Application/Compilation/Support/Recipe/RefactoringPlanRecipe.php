<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Recipe;

use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Application\Compilation\Support\Recipe\Interface\WorkflowRecipeBase;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

final readonly class RefactoringPlanRecipe implements WorkflowRecipeBase
{
    public function id(): string
    {
        return 'rick.refactoring_plan';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Evidence-first parallel code review and refactoring plan.';
    }

    public function build(array $configuration): WorkflowDefinition
    {
        $config = new RecipeConfiguration($configuration);
        $context = $config->string('context_key', 'code');
        $operation = $config->string('operation', 'rick.text');
        $builder = WorkflowBuilder::named($config->string('name', 'rick-refactoring-plan'))
            ->version($this->version())
            ->resolve($config->string('task'), $config->string('dod'))
            ->context($context)
            ->parallel([
                new OperationCall('structure', $operation, null, [$context], 'review.structure'),
                new OperationCall('risk', $operation, null, [$context], 'review.risks'),
                new OperationCall('tests', $operation, null, [$context], 'review.tests'),
            ])
            ->join(['review.structure', 'review.risks', 'review.tests'], 'review.evidence')
            ->operation($operation, 'refactoring.plan', [$context, 'review.evidence'], [
                'deliverable' => 'ordered reversible plan with evidence and verification',
            ]);

        if ($config->boolean('require_approval')) {
            $builder->waitForInput(
                'refactoring.approval',
                'Approve the refactoring plan or provide requested changes.',
                [
                    'type' => 'object',
                    'required' => ['approved'],
                    'properties' => [
                        'approved' => ['type' => 'boolean'],
                        'comment' => ['type' => 'string'],
                    ],
                ],
                'approval',
            );
        }

        return $builder->outputGlue('refactoring.plan')->build();
    }
}
