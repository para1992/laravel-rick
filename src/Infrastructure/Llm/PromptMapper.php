<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Llm;

use InvalidArgumentException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;

final readonly class PromptMapper
{
    /** @return array{instructions: string, history: list<object>, prompt: string} */
    public function map(CompletionRequest $request): array
    {
        $instructions = [];
        $history = [];
        $prompt = null;

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                $instructions[] = $message->content;

                continue;
            }
            if ($message->role === 'assistant') {
                $history[] = new AssistantMessage($message->content);

                continue;
            }
            if ($prompt !== null) {
                $history[] = new UserMessage($prompt);
            }
            $prompt = $message->content;
        }

        if ($prompt === null || trim($prompt) === '') {
            throw new InvalidArgumentException('Laravel AI requires a non-empty user prompt.');
        }

        $isRawPrompt = $request->purpose === 'raw_prompt'
            && ($request->metadata['raw_prompt'] ?? null) === true;
        if ($instructions === [] && ! $isRawPrompt) {
            throw new InvalidArgumentException(
                'Non-raw completion requests require an explicit system prompt.',
            );
        }

        return [
            'instructions' => implode("\n\n", $instructions),
            'history' => $history,
            'prompt' => $prompt,
        ];
    }
}
