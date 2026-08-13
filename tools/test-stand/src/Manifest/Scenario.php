<?php

declare(strict_types=1);

namespace Rick\Stand\Manifest;

final readonly class Scenario
{
    /** @param list<string> $covers @param list<string> $fixtures */
    public function __construct(
        public string $id,
        public string $lane,
        public array $covers,
        public array $fixtures,
        public string $test,
    ) {}

    /** @return array{id: string, lane: string, covers: list<string>, fixtures: list<string>, test: string} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
