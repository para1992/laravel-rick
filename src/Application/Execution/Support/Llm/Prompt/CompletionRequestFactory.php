<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Prompt;

use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;

final readonly class CompletionRequestFactory
{
    public function __construct(private StepPromptRegistry $prompts) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function create(
        string $profileId,
        string $userPrompt,
        ResponseContract $responseContract,
        string $purpose,
        string $modelPolicy = 'medium',
        array $metadata = [],
        ?array $responseSchema = null,
    ): CompletionRequest {
        $profile = $this->prompts->get($profileId);

        return new CompletionRequest(
            [
                new Message('system', $profile->system),
                new Message('user', $userPrompt),
            ],
            $responseContract,
            $purpose,
            $modelPolicy,
            metadata: [
                'prompt_profile_id' => $profile->id,
                'prompt_profile_version' => $profile->version,
                'prompt_profile_hash' => $profile->hash(),
            ] + $metadata,
            responseSchema: $responseSchema,
        );
    }
}
