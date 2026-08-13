<?php

declare(strict_types=1);

namespace Rick\Stand\Fixture;

use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;

final readonly class Cassette
{
    /** @param array<string, mixed> $matcher @param array<string, mixed> $outcome @param array<string, mixed> $metrics */
    public function __construct(
        public string $id,
        public string $kind,
        public array $matcher,
        public array $outcome,
        public array $metrics,
    ) {}

    public function matches(CompletionRequest $request): bool
    {
        if (isset($this->matcher['purpose']) && $this->matcher['purpose'] !== $request->purpose) {
            return false;
        }
        if (isset($this->matcher['response_contract'])
            && $this->matcher['response_contract'] !== $request->responseContract->value) {
            return false;
        }
        if (isset($this->matcher['prompt_contains'])) {
            $prompt = implode("\n", array_map(static fn ($message): string => $message->content, $request->messages));
            if (! str_contains($prompt, (string) $this->matcher['prompt_contains'])) {
                return false;
            }
        }
        foreach (($this->matcher['metadata'] ?? []) as $key => $value) {
            if (($request->metadata[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
