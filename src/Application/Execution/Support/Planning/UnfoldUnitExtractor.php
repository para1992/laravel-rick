<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Planning;

use InvalidArgumentException;
use Rick\Laravel\Domain\Execution\UnfoldUnit;

final readonly class UnfoldUnitExtractor
{
    /**
     * @param  list<mixed>  $values
     * @return non-empty-list<UnfoldUnit>
     */
    public function fromValues(array $values, int $maximum): array
    {
        $units = [];
        foreach ($values as $index => $value) {
            $units[] = $this->unit($value, $index + 1);
        }
        usort(
            $units,
            static fn (UnfoldUnit $left, UnfoldUnit $right): int => $left->sourceOrder
                <=> $right->sourceOrder,
        );

        if ($units === []) {
            throw new InvalidArgumentException('UNFOLD requires at least one valid unit.');
        }
        if (count($units) > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'UNFOLD produced %d units; configured maximum is %d.',
                count($units),
                $maximum,
            ));
        }

        $positions = [];
        foreach ($units as $position => $unit) {
            if (isset($positions[$unit->id])) {
                throw new InvalidArgumentException(
                    "UNFOLD unit id [{$unit->id}] is duplicated.",
                );
            }
            $positions[$unit->id] = $position;
        }
        foreach ($units as $position => $unit) {
            foreach ($unit->dependencies as $dependency) {
                if (! isset($positions[$dependency]) || $positions[$dependency] >= $position) {
                    throw new InvalidArgumentException(sprintf(
                        'UNFOLD unit [%s] has unresolved or forward dependency [%s].',
                        $unit->id,
                        $dependency,
                    ));
                }
            }
        }

        return $units;
    }

    private function unit(mixed $value, int $fallbackOrder): UnfoldUnit
    {
        if (is_string($value) && trim($value) !== '') {
            $content = trim($value);

            return new UnfoldUnit(
                "unit_{$fallbackOrder}",
                $content,
                $fallbackOrder,
                $content,
                mustPreserve: ["Preserve unit intent: {$content}"],
            );
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('UNFOLD unit must be a string or object.');
        }

        $title = trim(self::firstString([
            $value['title']
            ?? $value['name']
            ?? $value['path']
            ?? "Unit {$fallbackOrder}",
        ], 'title'));
        if ($title === '') {
            throw new InvalidArgumentException('UNFOLD unit title must not be empty.');
        }
        $content = trim(self::firstString([
            $value['content'] ?? $value['purpose'] ?? $title,
        ], 'content'));
        $mustPreserve = self::strings($value['must_preserve'] ?? []);
        if ($mustPreserve === []) {
            $mustPreserve = ["Preserve unit intent: {$title}"];
        }

        return new UnfoldUnit(
            self::firstString([
                $value['unit_id'] ?? $value['id'] ?? "unit_{$fallbackOrder}",
            ], 'unit_id'),
            $title,
            max(1, self::integer($value['source_order'] ?? $fallbackOrder, 'source_order')),
            $content,
            self::strings($value['constraints'] ?? []),
            $mustPreserve,
            self::nullableInteger($value['target_words'] ?? null, 'target_words'),
            self::nullableInteger($value['minimum_words'] ?? null, 'minimum_words'),
            self::nullableInteger($value['maximum_words'] ?? null, 'maximum_words'),
            self::strings($value['dependencies'] ?? []),
            self::strings($value['must_cover'] ?? []),
            self::strings($value['must_not_repeat'] ?? []),
            self::strings($value['evidence_queries'] ?? []),
            self::strings($value['memory_reads'] ?? []),
            self::strings($value['memory_writes'] ?? []),
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('UNFOLD string collection contains a non-string value.');
            }
            $item = trim($item);
            if ($item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /** @param non-empty-list<mixed> $values */
    private static function firstString(array $values, string $field): string
    {
        $value = $values[0];
        if (! is_string($value)) {
            throw new InvalidArgumentException("UNFOLD field [{$field}] must be a string.");
        }

        return $value;
    }

    private static function integer(mixed $value, string $field): int
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException("UNFOLD field [{$field}] must be an integer.");
        }

        return $value;
    }

    private static function nullableInteger(mixed $value, string $field): ?int
    {
        if ($value !== null && ! is_int($value)) {
            throw new InvalidArgumentException("UNFOLD field [{$field}] must be an integer or null.");
        }

        return $value;
    }
}
