<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;

final readonly class RunProgress implements JsonSerializable
{
    public function __construct(
        public RunStatus $status,
        public ?string $stepId,
        public ?string $label,
        public int $current,
        public int $total,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'step_id' => $this->stepId,
            'label' => $this->label,
            'current' => $this->current,
            'total' => $this->total,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
