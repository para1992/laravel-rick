<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Recipe;

use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Application\Compilation\Support\Recipe\Interface\WorkflowRecipeBase;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

final readonly class MultiPerspectiveAnalysisRecipe implements WorkflowRecipeBase
{
    public function id(): string
    {
        return 'rick.multi_perspective';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Parallel product, risk, and delivery analysis with deterministic join.';
    }

    public function build(array $configuration): WorkflowDefinition
    {
        $config = new RecipeConfiguration($configuration);
        $context = $config->string('context_key', 'source');
        $operation = $config->string('operation', 'rick.text');

        return WorkflowBuilder::named($config->string('name', 'rick-multi-perspective'))
            ->version($this->version())
            ->resolve($config->string('task'), $config->string('dod'))
            ->context($context)
            ->parallel([
                new OperationCall('product', $operation, null, [$context], 'analysis.product', [
                    'lens' => 'product value, users, requirements, and trade-offs',
                ]),
                new OperationCall('risk', $operation, null, [$context], 'analysis.risks', [
                    'lens' => 'failure modes, security, reliability, and operational risk',
                ]),
                new OperationCall('delivery', $operation, null, [$context], 'analysis.delivery', [
                    'lens' => 'architecture, implementation sequence, and verification',
                ]),
            ])
            ->join(
                ['analysis.product', 'analysis.risks', 'analysis.delivery'],
                'analysis.complete',
                separator: "\n\n---\n\n",
            )
            ->outputGlue('analysis.complete')
            ->build();
    }
}
