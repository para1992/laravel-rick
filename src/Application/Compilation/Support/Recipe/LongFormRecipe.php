<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Recipe;

use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Application\Compilation\Support\Recipe\Interface\WorkflowRecipeBase;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

final readonly class LongFormRecipe implements WorkflowRecipeBase
{
    public function id(): string
    {
        return 'rick.long_form';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Long-form output from an approved plan and sequential units.';
    }

    public function build(array $configuration): WorkflowDefinition
    {
        $config = new RecipeConfiguration($configuration);
        $context = $config->string('context_key', 'source');

        return WorkflowBuilder::named($config->string('name', 'rick-long-form'))
            ->version($this->version())
            ->resolve($config->string('task'), $config->string('dod'))
            ->context($context)
            ->generate('plan', 1, 'plan', [$context], $config->string('planning_model_policy', 'cheap'))
            ->manualJudge()
            ->unfold(
                'plan',
                $config->string('unit_artifact', 'section'),
                $config->integer('candidates_per_unit', 1),
                $config->integer('max_units', 20),
                $config->string('writing_model_policy', 'default'),
            )
            ->outputGlue()
            ->build();
    }
}
