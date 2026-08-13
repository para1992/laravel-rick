<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Grounding;

final readonly class GroundingUnit
{
    public function __construct(
        public string $id,
        public string $content,
    ) {}

    /** @return array{id: string, content: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
        ];
    }
}
