<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Llm\ValueObject;

use InvalidArgumentException;

final readonly class TextResponsePolicy
{
    /** @param list<string> $rejectedPrefixes */
    public function __construct(
        public int $minimumCharacters = 1,
        public array $rejectedPrefixes = [],
        public bool $allowTruncated = false,
    ) {
        if ($minimumCharacters < 1) {
            throw new InvalidArgumentException('Text response minimum must be at least one character.');
        }

        foreach ($rejectedPrefixes as $prefix) {
            if (trim($prefix) === '') {
                throw new InvalidArgumentException('Rejected text response prefixes must be non-empty strings.');
            }
        }
    }

    public static function finalArtifact(int $minimumCharacters): self
    {
        return new self($minimumCharacters, [
            'user safety:',
            'safety classification:',
            'content safety:',
        ]);
    }

    /** @param list<string> $rejectedPrefixes */
    public static function partial(int $minimumCharacters = 1, array $rejectedPrefixes = []): self
    {
        return new self($minimumCharacters, $rejectedPrefixes, true);
    }
}
