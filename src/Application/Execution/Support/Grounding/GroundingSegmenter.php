<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Grounding;

use InvalidArgumentException;
use Rick\Laravel\Domain\Run\Artifact;

final readonly class GroundingSegmenter
{
    public function __construct(private int $maximumUnitCharacters = 1200)
    {
        if ($maximumUnitCharacters < 64) {
            throw new InvalidArgumentException('Grounding units must allow at least 64 characters.');
        }
    }

    /** @return non-empty-list<GroundingUnit> */
    public function units(Artifact $target): array
    {
        $segments = [];
        $lines = preg_split('/\R+/u', $target->content);
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $sentences = preg_split('/(?<=[.!?…])\s+(?=\S)/u', $line);
            foreach ($sentences === false ? [$line] : $sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }
                for (
                    $offset = 0, $length = mb_strlen($sentence);
                    $offset < $length;
                    $offset += $this->maximumUnitCharacters
                ) {
                    $segments[] = mb_substr($sentence, $offset, $this->maximumUnitCharacters);
                }
            }
        }
        if ($segments === []) {
            $segments[] = trim($target->content);
        }

        return array_map(
            static fn (string $content, int $index): GroundingUnit => new GroundingUnit(
                sprintf('unit-%05d', $index + 1),
                $content,
            ),
            $segments,
            array_keys($segments),
        );
    }
}
