<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

use InvalidArgumentException;

final readonly class UnfoldUnit
{
    /**
     * @param  list<string>  $constraints
     * @param  list<string>  $mustPreserve
     * @param  list<string>  $dependencies
     * @param  list<string>  $mustCover
     * @param  list<string>  $mustNotRepeat
     * @param  list<string>  $evidenceQueries
     * @param  list<string>  $memoryReads
     * @param  list<string>  $memoryWrites
     */
    public function __construct(
        public string $id,
        public string $title,
        public int $sourceOrder,
        public string $content,
        public array $constraints = [],
        public array $mustPreserve = [],
        public ?int $targetWords = null,
        public ?int $minimumWords = null,
        public ?int $maximumWords = null,
        public array $dependencies = [],
        public array $mustCover = [],
        public array $mustNotRepeat = [],
        public array $evidenceQueries = [],
        public array $memoryReads = [],
        public array $memoryWrites = [],
    ) {
        if (trim($id) === '' || trim($title) === '' || $sourceOrder < 1) {
            throw new InvalidArgumentException(
                'UNFOLD unit requires a non-empty id and title plus a positive source order.',
            );
        }
        if (
            $targetWords !== null
            && $minimumWords !== null
            && $maximumWords !== null
            && ! ($minimumWords >= 1
                && $minimumWords <= $targetWords
                && $targetWords <= $maximumWords)
        ) {
            throw new InvalidArgumentException(
                'UNFOLD unit word bounds must satisfy 1 <= minimum <= target <= maximum.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unit_id' => $this->id,
            'title' => $this->title,
            'source_order' => $this->sourceOrder,
            'content' => $this->content,
            'constraints' => $this->constraints,
            'must_preserve' => $this->mustPreserve,
            'target_words' => $this->targetWords,
            'minimum_words' => $this->minimumWords,
            'maximum_words' => $this->maximumWords,
            'dependencies' => $this->dependencies,
            'must_cover' => $this->mustCover,
            'must_not_repeat' => $this->mustNotRepeat,
            'evidence_queries' => $this->evidenceQueries,
            'memory_reads' => $this->memoryReads,
            'memory_writes' => $this->memoryWrites,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::string($data, 'unit_id'),
            self::string($data, 'title'),
            self::integer($data, 'source_order'),
            self::string($data, 'content'),
            self::strings($data['constraints'] ?? []),
            self::strings($data['must_preserve'] ?? []),
            self::nullableInteger($data, 'target_words'),
            self::nullableInteger($data, 'minimum_words'),
            self::nullableInteger($data, 'maximum_words'),
            self::strings($data['dependencies'] ?? []),
            self::strings($data['must_cover'] ?? []),
            self::strings($data['must_not_repeat'] ?? []),
            self::strings($data['evidence_queries'] ?? []),
            self::strings($data['memory_reads'] ?? []),
            self::strings($data['memory_writes'] ?? []),
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('UNFOLD string collection must be an array.');
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('UNFOLD string collection contains a non-string value.');
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("UNFOLD field [{$key}] must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException("UNFOLD field [{$key}] must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableInteger(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && ! is_int($value)) {
            throw new InvalidArgumentException("UNFOLD field [{$key}] must be an integer or null.");
        }

        return $value;
    }
}
