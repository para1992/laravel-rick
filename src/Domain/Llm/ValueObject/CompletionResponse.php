<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Llm\ValueObject;

use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;

final readonly class CompletionResponse
{
    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $text = '',
        public ?array $structured = null,
        public string $provider = 'unknown',
        public string $model = 'unknown',
        public array $metadata = [],
        public ?CompletionMetrics $metrics = null,
        public ?StructuredResponseDiagnostic $diagnostic = null,
    ) {}

    public function withDiagnostic(StructuredResponseDiagnostic $diagnostic): self
    {
        if ($this->diagnostic === $diagnostic) {
            return $this;
        }

        return new self(
            $this->text,
            $this->structured,
            $this->provider,
            $this->model,
            $this->metadata,
            $this->metrics,
            $diagnostic,
        );
    }
}
