<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Llm\Prompt;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\StepPromptDefinition;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\StepPromptRegistry;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;

final class CompletionRequestFactoryTest extends TestCase
{
    public function test_it_builds_an_auditable_system_and_user_request(): void
    {
        $profile = new StepPromptDefinition(
            'rick.step.generate',
            '1.2.0',
            'Generate one bounded candidate.',
        );
        $factory = new CompletionRequestFactory(new StepPromptRegistry([$profile]));

        $request = $factory->create(
            'rick.step.generate',
            'Candidate number: 2',
            ResponseContract::Candidate,
            'generate_candidate_2',
            'quality',
            [
                'candidate_index' => 1,
                'prompt_profile_id' => 'caller-cannot-override-profile',
            ],
        );

        self::assertSame('system', $request->messages[0]->role);
        self::assertSame('Generate one bounded candidate.', $request->messages[0]->content);
        self::assertSame('user', $request->messages[1]->role);
        self::assertSame('Candidate number: 2', $request->messages[1]->content);
        self::assertSame('generate_candidate_2', $request->purpose);
        self::assertSame('quality', $request->modelTier);
        self::assertSame('rick.step.generate', $request->metadata['prompt_profile_id']);
        self::assertSame('1.2.0', $request->metadata['prompt_profile_version']);
        self::assertSame($profile->hash(), $request->metadata['prompt_profile_hash']);
        self::assertSame(1, $request->metadata['candidate_index']);
    }

    public function test_registry_resolves_the_latest_profile_version(): void
    {
        $registry = new StepPromptRegistry([
            new StepPromptDefinition('rick.step.generate', '1.2.0', 'Older.'),
            new StepPromptDefinition('rick.step.generate', '1.10.0', 'Latest.'),
        ]);

        self::assertSame('1.10.0', $registry->get('rick.step.generate')->version);
        self::assertSame('Older.', $registry->get('rick.step.generate', '1.2.0')->system);
    }
}
