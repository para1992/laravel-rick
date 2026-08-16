<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Recipe;

use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Application\Compilation\Support\Recipe\Interface\WorkflowRecipeBase;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

final readonly class HumanizerRecipe implements WorkflowRecipeBase
{
    public function id(): string
    {
        return 'rick.humanizer';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Language-neutral text humanization with pattern, taste, and fidelity audits plus factual grounding.';
    }

    public function build(array $configuration): WorkflowDefinition
    {
        $config = new RecipeConfiguration($configuration);
        $source = $config->string('source_key', 'source');
        $inputs = [$source];
        $voiceSample = null;
        $builder = WorkflowBuilder::named($config->string('name', 'rick-humanizer'))
            ->version($this->version())
            ->resolve(
                $config->string(
                    'task',
                    'Humanize the supplied text without translating it or changing its factual content.',
                ),
                $config->string(
                    'dod',
                    'The final text preserves the source language and facts, matches any supplied voice sample, and contains no obvious cluster of AI-writing patterns.',
                ),
            )
            ->context($source);

        if ($config->boolean('use_voice_sample')) {
            $voiceSample = $config->string('voice_sample_key', 'voice_sample');
            $builder->context($voiceSample);
            $inputs[] = $voiceSample;
        }

        return $builder
            ->operation(
                $config->string('draft_operation', 'rick.humanizer.draft'),
                'humanizer.draft',
                $inputs,
                [
                    'source_key' => $source,
                    'voice_sample_key' => $voiceSample,
                ],
            )
            ->operation(
                $config->string('audit_operation', 'rick.humanizer.audit'),
                'humanizer.audit',
                [$source, 'humanizer.draft'],
                [
                    'source_key' => $source,
                    'candidate_key' => 'humanizer.draft',
                ],
            )
            ->operation(
                $config->string('taste_audit_operation', 'rick.humanizer.taste_audit'),
                'humanizer.taste_audit',
                ['humanizer.draft'],
                [
                    'candidate_key' => 'humanizer.draft',
                ],
            )
            ->operation(
                $config->string('revision_operation', 'rick.humanizer.revise'),
                'humanizer.final',
                [$source, 'humanizer.draft', 'humanizer.audit', 'humanizer.taste_audit'],
                [
                    'source_key' => $source,
                    'candidate_key' => 'humanizer.draft',
                    'audit_key' => 'humanizer.audit',
                    'taste_audit_key' => 'humanizer.taste_audit',
                ],
            )
            ->groundedVerify(
                'humanizer.final',
                [$source],
                $config->string('grounding_repair_operation', 'rick.humanizer.grounding_repair'),
                maxRepairs: $config->integer('max_grounding_repairs', 1),
                output: 'humanizer.verified',
                verificationOperation: $config->string(
                    'verification_operation',
                    'rick.verify.grounded',
                ),
            )
            ->qualityGate(
                'humanizer.verified',
                $config->string('quality_rule_set', 'non_empty'),
                output: 'humanizer.output',
            )
            ->outputGlue('humanizer.output')
            ->build();
    }
}
