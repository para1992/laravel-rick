<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Memory;

use InvalidArgumentException;

final readonly class EntityMutation
{
    public function __construct(
        public string $id,
        public string $value,
        public int $expectedVersion = 0,
    ) {
        if (trim($id) === '' || trim($value) === '' || $expectedVersion < 0) {
            throw new InvalidArgumentException(
                'Memory entity mutation requires non-empty values and a non-negative version.',
            );
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = $data['entity_id'] ?? null;
        $value = $data['value'] ?? null;
        $expectedVersion = $data['expected_version'] ?? null;
        if (! is_string($id) || ! is_string($value) || ! is_int($expectedVersion)) {
            throw new InvalidArgumentException('Memory entity mutation payload is invalid.');
        }

        return new self(
            $id,
            $value,
            $expectedVersion,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entity_id' => $this->id,
            'value' => $this->value,
            'expected_version' => $this->expectedVersion,
        ];
    }
}
