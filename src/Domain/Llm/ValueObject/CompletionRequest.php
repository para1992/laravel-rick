<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Llm\ValueObject;

use InvalidArgumentException;

final readonly class CompletionRequest
{
    /**
     * @param  list<Message>  $messages
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function __construct(
        public array $messages,
        public ResponseContract $responseContract,
        public string $purpose,
        public string $modelTier = 'medium',
        public array $options = [],
        public ?TextResponsePolicy $textResponsePolicy = null,
        public array $metadata = [],
        public ?array $responseSchema = null,
        public ?int $structuredResponseAttempts = null,
    ) {
        if ($responseContract === ResponseContract::Json && ($responseSchema === null || $responseSchema === [])) {
            throw new InvalidArgumentException('The generic JSON response contract requires a response schema.');
        }
        if ($structuredResponseAttempts !== null && $structuredResponseAttempts < 1) {
            throw new InvalidArgumentException('Structured response attempts must be positive.');
        }
    }

    /** @param array<string, mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->messages,
            $this->responseContract,
            $this->purpose,
            $this->modelTier,
            $this->options,
            $this->textResponsePolicy,
            $metadata + $this->metadata,
            $this->responseSchema,
            $this->structuredResponseAttempts,
        );
    }

    /** @param array<string, mixed> $options */
    public function routed(string $modelTier, array $options = []): self
    {
        return new self(
            $this->messages,
            $this->responseContract,
            $this->purpose,
            $modelTier,
            $options + $this->options,
            $this->textResponsePolicy,
            $this->metadata,
            $this->responseSchema,
            $this->structuredResponseAttempts,
        );
    }
}
