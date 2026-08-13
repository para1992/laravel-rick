<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

final readonly class PruneResult
{
    /** @param list<string> $runIds */
    public function __construct(
        public int $matched,
        public int $deleted,
        public array $runIds,
    ) {}
}
