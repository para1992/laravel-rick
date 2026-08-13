<?php

declare(strict_types=1);

namespace Rick\Stand\Inventory;

final readonly class Element
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $id,
        public string $category,
        public array $metadata = [],
    ) {}

    /** @return array{id: string, category: string, metadata: array<string, scalar|null>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'category' => $this->category, 'metadata' => $this->metadata];
    }
}
