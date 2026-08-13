<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Prompt;

use OutOfBoundsException;

final class StepPromptRegistry
{
    /** @var list<string> */
    public const PROFILE_IDS = [
        'rick.step.define_dod',
        'rick.step.generate',
        'rick.step.unfold.units',
        'rick.step.unfold.candidate',
        'rick.step.edit',
        'rick.step.parallel',
        'rick.step.map',
    ];

    /** @var array<string, StepPromptDefinition> */
    private array $prompts = [];

    /** @param iterable<StepPromptDefinition> $prompts */
    public function __construct(iterable $prompts = [])
    {
        foreach ($prompts as $prompt) {
            $this->register($prompt);
        }
    }

    public function register(StepPromptDefinition $prompt): void
    {
        $this->prompts[$prompt->id.'@'.$prompt->version] = $prompt;
    }

    public function get(string $id, ?string $version = null): StepPromptDefinition
    {
        if ($version !== null) {
            return $this->prompts[$id.'@'.$version]
                ?? throw new OutOfBoundsException("Step prompt [{$id}@{$version}] is not registered.");
        }

        $matches = array_values(array_filter(
            $this->prompts,
            static fn (StepPromptDefinition $prompt): bool => $prompt->id === $id,
        ));
        if ($matches === []) {
            throw new OutOfBoundsException("Step prompt [{$id}] is not registered.");
        }
        usort(
            $matches,
            static fn (StepPromptDefinition $left, StepPromptDefinition $right): int => version_compare(
                $right->version,
                $left->version,
            ),
        );

        return $matches[0];
    }
}
