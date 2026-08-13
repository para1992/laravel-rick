<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class PendingInput implements JsonSerializable
{
    /** @param array<string, mixed>|null $schema */
    public function __construct(
        public ?StepId $stepId,
        public ?string $key,
        public ?string $prompt,
        public ?array $schema = null,
    ) {}

    public function exists(): bool
    {
        return $this->stepId !== null && $this->key !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'exists' => $this->exists(),
            'step_id' => $this->stepId?->toString(),
            'key' => $this->key,
            'prompt' => $this->prompt,
            'schema' => $this->schema,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
