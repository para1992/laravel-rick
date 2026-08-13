<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Memory;

use InvalidArgumentException;

final readonly class UnitCard
{
    /**
     * @param  list<string>  $requirementsCovered
     * @param  list<string>  $factsAdded
     * @param  list<string>  $decisionsAdded
     * @param  list<string>  $hooks
     */
    public function __construct(
        public string $unitId,
        public int $sourceOrder,
        public string $summary,
        public array $requirementsCovered,
        public array $factsAdded,
        public array $decisionsAdded,
        public array $hooks,
        public string $transition,
        public string $contentHash,
    ) {
        if (trim($unitId) === '' || $sourceOrder < 1 || trim($contentHash) === '') {
            throw new InvalidArgumentException(
                'Memory unit card requires unit id, source order and content hash.',
            );
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $unitId = $data['unit_id'] ?? null;
        $sourceOrder = $data['source_order'] ?? null;
        $summary = $data['summary'] ?? null;
        $transition = $data['transition'] ?? null;
        $contentHash = $data['content_hash'] ?? null;
        if (
            ! is_string($unitId)
            || ! is_int($sourceOrder)
            || ! is_string($summary)
            || ! is_string($transition)
            || ! is_string($contentHash)
        ) {
            throw new InvalidArgumentException('Persisted memory unit card is invalid.');
        }

        return new self(
            $unitId,
            $sourceOrder,
            $summary,
            self::strings($data['requirements_covered'] ?? []),
            self::strings($data['facts_added'] ?? []),
            self::strings($data['decisions_added'] ?? []),
            self::strings($data['hooks'] ?? []),
            $transition,
            $contentHash,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unit_id' => $this->unitId,
            'source_order' => $this->sourceOrder,
            'summary' => $this->summary,
            'requirements_covered' => $this->requirementsCovered,
            'facts_added' => $this->factsAdded,
            'decisions_added' => $this->decisionsAdded,
            'hooks' => $this->hooks,
            'transition' => $this->transition,
            'content_hash' => $this->contentHash,
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Persisted memory string collection must be an array.');
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(
                    'Persisted memory string collection contains a non-string value.',
                );
            }
            $strings[] = $item;
        }

        return $strings;
    }
}
