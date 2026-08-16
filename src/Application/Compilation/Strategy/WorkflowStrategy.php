<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Strategy;

use InvalidArgumentException;
use Rick\Laravel\Application\Compilation\Exception\WorkflowValidationException;
use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;
use Rick\Laravel\Application\Compilation\Interface\PlanBase;
use Rick\Laravel\Application\Compilation\Interface\StrategyBase;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowDefinition;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowPlan;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Interface\TerminalStepBase;
use Rick\Laravel\Domain\Workflow\Step\AgentStep;
use Rick\Laravel\Domain\Workflow\Step\ApplicationStep;
use Rick\Laravel\Domain\Workflow\Step\DefineDodStep;
use Rick\Laravel\Domain\Workflow\Step\GenerateStep;
use Rick\Laravel\Domain\Workflow\Step\JudgeStep;
use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\Step\WaitForInputStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition as DomainWorkflowDefinition;

final readonly class WorkflowStrategy implements StrategyBase
{
    public function __construct(private JsonSchemaValidatorBase $schemas) {}

    public function supports(DefinitionBase $definition): bool
    {
        return $definition instanceof WorkflowDefinition;
    }

    public function compile(DefinitionBase $definition): PlanBase
    {
        if (! $definition instanceof WorkflowDefinition) {
            throw new WorkflowValidationException(sprintf(
                'Unsupported workflow definition [%s].',
                $definition::class,
            ));
        }

        $workflow = $definition->workflow;

        $this->validateDefinition($workflow);

        $steps = $this->prepareSteps($workflow->steps);
        $steps = $this->normalizeSteps($steps);

        $this->validateArtifactGraph($steps);

        return new WorkflowPlan(new CompiledWorkflow(
            $workflow->name,
            $workflow->version,
            $steps,
            $workflow->resourceBudget,
        ));
    }

    private function validateDefinition(DomainWorkflowDefinition $definition): void
    {
        if ($definition->name === '') {
            throw new WorkflowValidationException('Workflow name must not be empty.');
        }

        $rawPrompts = array_filter(
            $definition->steps,
            static fn (StepBase $step): bool => $step instanceof RawPromptStep,
        );

        if ($rawPrompts !== []) {
            if (count($definition->steps) === 1 && $definition->steps[0] instanceof RawPromptStep) {
                return;
            }

            throw new WorkflowValidationException('RAW_PROMPT must be the only workflow step.');
        }

        if ($definition->steps === []) {
            throw new WorkflowValidationException('Workflow must contain at least one step.');
        }

        foreach ($definition->steps as $step) {
            if ($step instanceof WaitForInputStep && $step->schema !== null) {
                try {
                    $this->schemas->assertSchema($step->schema);
                } catch (InvalidArgumentException $error) {
                    throw new WorkflowValidationException(
                        "Wait-for-input step [{$step->id()->toString()}] has an invalid JSON Schema.",
                        previous: $error,
                    );
                }
            }
        }
    }

    /**
     * @param  list<StepBase>  $steps
     * @return list<StepBase>
     */
    private function prepareSteps(array $steps): array
    {
        $result = [];

        foreach ($steps as $index => $step) {
            if ($index > 0 && $step instanceof ResolveStep) {
                throw new WorkflowValidationException('RESOLVE may only appear as the first step.');
            }

            $result[] = $step;

            if ($step instanceof ResolveStep && $step->dod->isAutomatic()) {
                $result[] = new DefineDodStep(
                    StepId::fromString($step->id()->toString().'_define_dod'),
                );
            }
        }

        return $result;
    }

    /**
     * @param  list<StepBase>  $steps
     * @return list<StepBase>
     */
    private function normalizeSteps(array $steps): array
    {
        $last = end($steps);
        if ($last === false) {
            throw new WorkflowValidationException('Workflow must contain at least one step.');
        }

        if (! $last instanceof TerminalStepBase) {
            $steps[] = new OutputGlueStep(StepId::fromString('999_output_glue'));
        }

        $ids = [];

        foreach ($steps as $step) {
            $id = $step->id()->toString();

            if (isset($ids[$id])) {
                throw new WorkflowValidationException("Duplicate step id [{$id}].");
            }

            $ids[$id] = true;
        }

        return $steps;
    }

    /** @param list<StepBase> $steps */
    private function validateArtifactGraph(array $steps): void
    {
        foreach ($steps as $step) {
            if ($step instanceof ApplicationStep || $step instanceof AgentStep) {
                // Application-first workflows own a runtime-dynamic artifact
                // graph (a step or agent can read/write any key through
                // WorkflowState). Static read-before-write validation applies
                // only to workflows built from compile-time-declared LLM
                // primitives.
                return;
            }
        }

        /** @var array<string, true> $available */
        $available = [];
        $pendingCandidateOutput = null;

        foreach ($steps as $step) {
            if ($step instanceof ArtifactStepBase) {
                foreach ($step->artifactReads() as $key) {
                    if (! isset($available[$key])) {
                        throw new WorkflowValidationException(sprintf(
                            'Step [%s] reads artifact [%s] before it is written.',
                            $step->id()->toString(),
                            $key,
                        ));
                    }
                }
            }

            if ($step instanceof GenerateStep) {
                $pendingCandidateOutput = $step->outputKey();

                continue;
            }

            if ($step instanceof JudgeStep) {
                if ($pendingCandidateOutput === null) {
                    throw new WorkflowValidationException(sprintf(
                        'JUDGE step [%s] has no preceding generated candidate set.',
                        $step->id()->toString(),
                    ));
                }

                $available[$pendingCandidateOutput] = true;
                $pendingCandidateOutput = null;

                continue;
            }

            if ($step instanceof ArtifactStepBase) {
                foreach ($step->artifactWrites() as $key) {
                    $available[$key] = true;
                }
            }
        }
    }
}
