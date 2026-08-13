<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;

final readonly class ContextDocument implements JsonSerializable
{
    public function __construct(
        public string $key,
        public string $content,
        public int $originalCharacters,
        public int $includedCharacters,
        public bool $truncated,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'key' => $this->key,
            'content' => $this->content,
            'original_characters' => $this->originalCharacters,
            'included_characters' => $this->includedCharacters,
            'truncated' => $this->truncated,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
