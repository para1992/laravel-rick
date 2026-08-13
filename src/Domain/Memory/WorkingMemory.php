<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Memory;

use InvalidArgumentException;

final readonly class WorkingMemory
{
    /**
     * @param  list<string>  $facts
     * @param  list<string>  $decisions
     * @param  array<string, array{value: string, version: int}>  $entities
     * @param  list<string>  $openLoops
     * @param  list<string>  $resolvedLoops
     * @param  list<string>  $requirementsCovered
     * @param  list<UnitCard>  $unitCards
     */
    public function __construct(
        public int $version = 0,
        public array $facts = [],
        public array $decisions = [],
        public array $entities = [],
        public array $openLoops = [],
        public array $resolvedLoops = [],
        public array $requirementsCovered = [],
        public array $unitCards = [],
    ) {
        if ($version < 0) {
            throw new InvalidArgumentException('Working memory version must not be negative.');
        }

    }

    public static function empty(): self
    {
        return new self;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $cards = $data['unit_cards'] ?? [];
        $entities = $data['entities'] ?? [];

        if (! is_array($cards) || ! is_array($entities)) {
            throw new InvalidArgumentException(
                'Persisted working memory contains invalid collections.',
            );
        }

        $normalizedEntities = [];

        foreach ($entities as $id => $state) {
            if (! is_array($state)
                || ! is_string($state['value'] ?? null)
                || ! is_int($state['version'] ?? null)
            ) {
                throw new InvalidArgumentException('Persisted memory entity is invalid.');
            }

            $entityId = is_string($id) ? $id : (string) $id;
            $normalizedEntities[$entityId] = [
                'value' => $state['value'],
                'version' => $state['version'],
            ];
        }

        $version = $data['version'] ?? null;
        if (! is_int($version)) {
            throw new InvalidArgumentException('Persisted working memory version must be an integer.');
        }

        return new self(
            $version,
            self::strings($data['facts'] ?? []),
            self::strings($data['decisions'] ?? []),
            $normalizedEntities,
            self::strings($data['open_loops'] ?? []),
            self::strings($data['resolved_loops'] ?? []),
            self::strings($data['requirements_covered'] ?? []),
            array_values(array_map(
                static fn (mixed $card): UnitCard => is_array($card)
                    ? UnitCard::fromArray(self::map($card))
                    : throw new InvalidArgumentException(
                        'Persisted memory unit card must be an object.',
                    ),
                $cards,
            )),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'facts' => $this->facts,
            'decisions' => $this->decisions,
            'entities' => $this->entities,
            'open_loops' => $this->openLoops,
            'resolved_loops' => $this->resolvedLoops,
            'requirements_covered' => $this->requirementsCovered,
            'unit_cards' => array_map(
                static fn (UnitCard $card): array => $card->toArray(),
                $this->unitCards,
            ),
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @return array<string, mixed> */
    public function promptProjection(int $entryLimit = 20, int $unitCardLimit = 3): array
    {
        return $this->semanticLedgerProjection($entryLimit) + [
            'recent_unit_cards' => $this->recentUnitCardsProjection($unitCardLimit),
        ];
    }

    /** @return array<string, mixed> */
    public function semanticLedgerProjection(int $entryLimit = 12): array
    {
        return [
            'version' => $this->version,
            'facts' => array_slice($this->facts, -$entryLimit),
            'decisions' => array_slice($this->decisions, -$entryLimit),
            'entities' => array_slice($this->entities, -$entryLimit, null, true),
            'open_loops' => array_slice($this->openLoops, -$entryLimit),
            'requirements_covered' => array_slice($this->requirementsCovered, -$entryLimit),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentUnitCardsProjection(int $limit = 2): array
    {
        return array_map(
            static fn (UnitCard $card): array => $card->toArray(),
            array_slice($this->unitCards, -$limit),
        );
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

    /** @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Persisted memory unit card must be an object.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
