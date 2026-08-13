<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;

final readonly class RunPage implements JsonSerializable
{
    /** @param list<RunSummary> $runs */
    public function __construct(
        public array $runs,
        public ?string $nextCursor,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'runs' => array_map(
                static fn (RunSummary $run): array => $run->toArray(),
                $this->runs,
            ),
            'next_cursor' => $this->nextCursor,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
