<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Llm;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

final class TextAgent extends AnonymousAgent implements HasProviderOptions
{
    private readonly GenerationOptions $options;

    /**
     * @param  iterable<mixed>  $messages
     * @param  iterable<mixed>  $tools
     */
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools = [],
        ?GenerationOptions $options = null,
    ) {
        parent::__construct($instructions, $messages, $tools);
        $this->options = $options ?? GenerationOptions::from([], 60);
    }

    public function maxTokens(): ?int
    {
        return $this->options->maxTokens;
    }

    public function temperature(): ?float
    {
        return $this->options->temperature;
    }

    public function topP(): ?float
    {
        return $this->options->topP;
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->options->provider;
    }
}
