<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Memory;

use InvalidArgumentException;

final readonly class MemoryDelta
{
    /**
     * @param  list<string>  $factsAdded
     * @param  list<string>  $decisionsAdded
     * @param  list<EntityMutation>  $entitiesChanged
     * @param  list<string>  $loopsOpened
     * @param  list<string>  $loopsResolved
     * @param  list<string>  $requirementsCovered
     * @param  list<string>  $requirementsViolated
     */
    public function __construct(
        public array $factsAdded = [],
        public array $decisionsAdded = [],
        public array $entitiesChanged = [],
        public array $loopsOpened = [],
        public array $loopsResolved = [],
        public array $requirementsCovered = [],
        public array $requirementsViolated = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $entities = $data['entities_changed'] ?? [];
        if (! is_array($entities)) {
            throw new InvalidArgumentException('Memory delta entities_changed must be an array.');
        }

        return new self(
            self::strings($data['facts_added'] ?? [], 'facts_added'),
            self::strings($data['decisions_added'] ?? [], 'decisions_added'),
            array_values(array_map(
                static fn (mixed $item): EntityMutation => is_array($item)
                    ? EntityMutation::fromArray(self::map($item))
                    : throw new InvalidArgumentException(
                        'Memory entity mutation must be an object.',
                    ),
                $entities,
            )),
            self::strings($data['loops_opened'] ?? [], 'loops_opened'),
            self::strings($data['loops_resolved'] ?? [], 'loops_resolved'),
            self::strings($data['requirements_covered'] ?? [], 'requirements_covered'),
            self::strings($data['requirements_violated'] ?? [], 'requirements_violated'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'facts_added' => $this->factsAdded,
            'decisions_added' => $this->decisionsAdded,
            'entities_changed' => array_map(
                static fn (EntityMutation $mutation): array => $mutation->toArray(),
                $this->entitiesChanged,
            ),
            'loops_opened' => $this->loopsOpened,
            'loops_resolved' => $this->loopsResolved,
            'requirements_covered' => $this->requirementsCovered,
            'requirements_violated' => $this->requirementsViolated,
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Memory delta {$field} must be an array.");
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    "Memory delta {$field} must contain non-empty strings.",
                );
            }
            $strings[] = trim($item);
        }

        return array_values(array_unique($strings));
    }

    /** @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Memory entity mutation must be an object.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
