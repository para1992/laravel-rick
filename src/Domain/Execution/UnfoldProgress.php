<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Memory\WorkingMemory;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;

final readonly class UnfoldProgress
{
    /**
     * @param  list<UnfoldUnit>  $units
     * @param  list<string>  $selectedCandidateIds
     */
    private function __construct(
        public UnfoldPhase $phase,
        public array $units,
        public int $unitIndex,
        public array $selectedCandidateIds,
        public WorkingMemory $memory,
    ) {}

    public static function needsExplosion(): self
    {
        return new self(UnfoldPhase::Explode, [], 0, [], WorkingMemory::empty());
    }

    /** @param list<UnfoldUnit> $units */
    public static function forUnits(array $units): self
    {
        if ($units === []) {
            throw new InvalidStateTransitionException('UNFOLD requires at least one unit.');
        }

        return new self(UnfoldPhase::Generate, $units, 0, [], WorkingMemory::empty());
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        $units = $state['units'] ?? null;
        $selected = $state['selected_candidate_ids'] ?? null;
        $phase = $state['phase'] ?? null;
        $unitIndex = $state['unit_index'] ?? null;
        if (
            ! is_array($units)
            || ! is_array($selected)
            || ! is_string($phase)
            || ! is_int($unitIndex)
        ) {
            throw new InvalidStateTransitionException('Invalid persisted UNFOLD progress.');
        }

        return new self(
            UnfoldPhase::from($phase),
            array_values(array_map(
                static fn (mixed $unit): UnfoldUnit => is_array($unit)
                    ? UnfoldUnit::fromArray(self::map($unit))
                    : throw new InvalidStateTransitionException('Invalid persisted UNFOLD unit.'),
                $units,
            )),
            $unitIndex,
            self::strings($selected),
            is_array($state['memory'] ?? null)
                ? WorkingMemory::fromArray(self::map($state['memory']))
                : WorkingMemory::empty(),
        );
    }

    /** @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidStateTransitionException('Invalid persisted UNFOLD object.');
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /** @param array<mixed> $value
     * @return list<string>
     */
    private static function strings(array $value): array
    {
        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidStateTransitionException(
                    'Invalid persisted UNFOLD candidate identifier.',
                );
            }
            $strings[] = $item;
        }

        return $strings;
    }

    public function currentUnit(): UnfoldUnit
    {
        return $this->units[$this->unitIndex]
            ?? throw new InvalidStateTransitionException(
                'UNFOLD cursor does not point to a unit.',
            );
    }

    public function awaitingJudge(): self
    {
        if ($this->phase !== UnfoldPhase::Generate) {
            throw new InvalidStateTransitionException(
                'Only generated UNFOLD candidates may await judging.',
            );
        }

        return new self(
            UnfoldPhase::Judge,
            $this->units,
            $this->unitIndex,
            $this->selectedCandidateIds,
            $this->memory,
        );
    }

    public function accept(CandidateId $candidateId, WorkingMemory $memory): self
    {
        if (! in_array($this->phase, [UnfoldPhase::Generate, UnfoldPhase::Judge], true)) {
            throw new InvalidStateTransitionException(
                'UNFOLD can only accept a candidate from generate or judge phase.',
            );
        }

        $selected = [...$this->selectedCandidateIds, $candidateId->toString()];
        $nextIndex = $this->unitIndex + 1;

        return $nextIndex >= count($this->units)
            ? new self(UnfoldPhase::Complete, $this->units, $this->unitIndex, $selected, $memory)
            : new self(UnfoldPhase::Generate, $this->units, $nextIndex, $selected, $memory);
    }

    public function isComplete(): bool
    {
        return $this->phase === UnfoldPhase::Complete;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase->value,
            'units' => array_map(
                static fn (UnfoldUnit $unit): array => $unit->toArray(),
                $this->units,
            ),
            'unit_index' => $this->unitIndex,
            'selected_candidate_ids' => $this->selectedCandidateIds,
            'memory' => $this->memory->toArray(),
        ];
    }
}
