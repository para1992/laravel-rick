<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\MapStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class MapStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function __construct(private CompletionRequestFactory $requests) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'map';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof MapStep) {
            throw new LogicException('Map strategy received an incompatible step.');
        }
        $source = $run->artifact($step->sourceArtifact);
        $items = self::read($source->payload, $step->sourcePath);
        if (! is_array($items)) {
            throw new LogicException('Map source path must resolve to an array.');
        }
        $items = array_slice(array_values($items), 0, $step->maxItems);
        if ($items === []) {
            throw new LogicException('Map source contains no items.');
        }

        return new InvocationStepPlan(array_map(
            fn (mixed $item, int $index): CompletionRequest => $this->requests->create(
                'rick.step.map',
                'Execute '.$step->operationId." for map item.\n"
                    .json_encode(['item' => $item, 'index' => $index, 'parameters' => $step->parameters], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ResponseContract::Text,
                $step->operationId,
                metadata: ['map_index' => $index],
            ),
            $items,
            array_keys($items),
        ));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        if (! $step instanceof MapStep) {
            throw new LogicException('Map strategy received an incompatible step.');
        }
        $responses = InvocationResponses::successful($outcomes);
        $values = array_map(static fn ($response): string => $response->text, $responses);

        return StepOutcome::artifactsProduced([
            new Artifact(
                $step->outputKey,
                ArtifactType::fromString('collection'),
                json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['items' => $values],
            ),
        ]);
    }

    private static function read(mixed $value, string $path): mixed
    {
        foreach (explode('.', trim($path, '.')) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
