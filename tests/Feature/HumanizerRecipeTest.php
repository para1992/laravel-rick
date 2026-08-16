<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\HumanizerPrompt;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\TasteAuditPrompt;
use Rick\Laravel\Domain\Workflow\Step\GroundedVerifyStep;
use Rick\Laravel\Domain\Workflow\Step\LlmOperationStep;
use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\Step\QualityGateStep;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class HumanizerRecipeTest extends TestCase
{
    public function test_humanizer_workflow_documentation_describes_the_taste_audit(): void
    {
        $documentation = file_get_contents(dirname(__DIR__, 2).'/docs/workflows.md');

        self::assertNotFalse($documentation);
        self::assertStringContainsString('prose-taste audit', $documentation);
        self::assertStringContainsString('Leonxlnx/taste-skill', $documentation);
    }

    public function test_builtin_humanizer_recipe_compiles_through_the_public_entry_point(): void
    {
        $definition = $this->application()->make(RecipeRegistry::class)->build('rick.humanizer');
        $compiled = $this->application()->make(Rick::class)->compile($definition);

        self::assertSame('rick-humanizer', $compiled->name);
        self::assertSame('1.0.0', $compiled->version);
        self::assertCount(9, $compiled->steps);

        $operations = array_values(array_filter(
            $compiled->steps,
            static fn (object $step): bool => $step instanceof LlmOperationStep,
        ));
        self::assertSame([
            'rick.humanizer.draft',
            'rick.humanizer.audit',
            'rick.humanizer.taste_audit',
            'rick.humanizer.revise',
        ], array_map(
            static fn (LlmOperationStep $step): string => $step->operationId,
            $operations,
        ));

        self::assertInstanceOf(GroundedVerifyStep::class, $compiled->steps[6]);
        self::assertSame('rick.humanizer.grounding_repair', $compiled->steps[6]->repairOperationId);
        self::assertSame(1, $compiled->steps[6]->maxRepairs);
        self::assertInstanceOf(QualityGateStep::class, $compiled->steps[7]);
        self::assertSame('non_empty', $compiled->steps[7]->ruleSetId);
        self::assertInstanceOf(OutputGlueStep::class, $compiled->steps[8]);
        self::assertSame('humanizer.output', $compiled->steps[8]->artifactKey);
    }

    public function test_humanizer_operations_use_the_versioned_upstream_prompt(): void
    {
        $operations = $this->application()->make(LlmOperationRegistry::class);

        foreach ([
            'rick.humanizer.draft',
            'rick.humanizer.audit',
            'rick.humanizer.revise',
            'rick.humanizer.grounding_repair',
        ] as $operationId) {
            $definition = $operations->get($operationId)->definition();

            self::assertSame(HumanizerPrompt::VERSION, $definition->version);
            self::assertStringContainsString(
                '# Humanizer: Remove AI Writing Patterns',
                $definition->prompt->system,
            );
        }

        $audit = $operations->get('rick.humanizer.audit')->definition();
        self::assertNotNull($audit->prompt->outputSchema);
        self::assertSame(
            ['passed', 'pattern_issues', 'fidelity_issues', 'summary'],
            $audit->prompt->outputSchema['required'],
        );

        $tasteAudit = $operations->get('rick.humanizer.taste_audit')->definition();
        self::assertSame(TasteAuditPrompt::VERSION, $tasteAudit->version);
        self::assertStringContainsString(
            '# Taste Audit: Generic and Slop Writing Patterns',
            $tasteAudit->prompt->system,
        );
        self::assertNotNull($tasteAudit->prompt->outputSchema);
        self::assertSame(
            ['passed', 'taste_score', 'issues', 'human_signals', 'summary'],
            $tasteAudit->prompt->outputSchema['required'],
        );
    }

    public function test_prompt_corpus_keeps_all_upstream_patterns_and_language_neutral_contract(): void
    {
        preg_match_all('/^### ([0-9]+)\./m', HumanizerPrompt::rules(), $matches);

        self::assertSame(range(1, 33), array_map('intval', $matches[1]));
        self::assertStringContainsString('Preserve that language', HumanizerPrompt::editorSystem());
        self::assertStringContainsString('do not translate', HumanizerPrompt::editorSystem());
        self::assertSame(
            'https://github.com/blader/humanizer/blob/v2.9.1/SKILL.md',
            HumanizerPrompt::SOURCE,
        );

        preg_match_all('/^### ([0-9]+)\./m', TasteAuditPrompt::rules(), $tasteMatches);
        self::assertSame(range(1, 10), array_map('intval', $tasteMatches[1]));
        self::assertStringContainsString(
            'Judge tone, voice, texture, and craftsmanship only.',
            TasteAuditPrompt::tasteAuditSystem(),
        );
        self::assertSame(
            'https://github.com/Leonxlnx/taste-skill',
            TasteAuditPrompt::SOURCE,
        );
    }
}
