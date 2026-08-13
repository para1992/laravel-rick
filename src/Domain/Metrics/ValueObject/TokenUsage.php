<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Metrics\ValueObject;

use InvalidArgumentException;

final readonly class TokenUsage
{
    public int $totalTokens;

    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        ?int $totalTokens = null,
        public int $cachedInputTokens = 0,
        public int $cacheWriteInputTokens = 0,
        public int $reasoningTokens = 0,
    ) {
        foreach ([
            'input' => $inputTokens,
            'output' => $outputTokens,
            'cached input' => $cachedInputTokens,
            'cache-write input' => $cacheWriteInputTokens,
            'reasoning' => $reasoningTokens,
        ] as $name => $tokens) {
            if ($tokens < 0) {
                throw new InvalidArgumentException("{$name} token usage cannot be negative.");
            }
        }

        $this->totalTokens = $totalTokens ?? ($inputTokens + $outputTokens);

        if ($this->totalTokens < 0) {
            throw new InvalidArgumentException('Total token usage cannot be negative.');
        }
    }

    public static function zero(): self
    {
        return new self;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->totalTokens + $other->totalTokens,
            $this->cachedInputTokens + $other->cachedInputTokens,
            $this->cacheWriteInputTokens + $other->cacheWriteInputTokens,
            $this->reasoningTokens + $other->reasoningTokens,
        );
    }

    public function uncachedInputTokens(): int
    {
        return max(0, $this->inputTokens - $this->cachedInputTokens);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
