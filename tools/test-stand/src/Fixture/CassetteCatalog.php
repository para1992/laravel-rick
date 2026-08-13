<?php

declare(strict_types=1);

namespace Rick\Stand\Fixture;

use RuntimeException;

final class CassetteCatalog
{
    /** @var array<string, Cassette>|null */
    private ?array $cassettes = null;

    public function __construct(
        private readonly string $directory,
        private readonly CassetteLoader $loader = new CassetteLoader,
    ) {}

    /** @return array<string, Cassette> */
    public function all(): array
    {
        if ($this->cassettes !== null) {
            return $this->cassettes;
        }
        $loaded = [];
        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            $cassette = $this->loader->load($path);
            if (isset($loaded[$cassette->id])) {
                throw new RuntimeException("Duplicate cassette ID [{$cassette->id}].");
            }
            $loaded[$cassette->id] = $cassette;
        }
        ksort($loaded);

        return $this->cassettes = $loaded;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): Cassette
    {
        return $this->all()[$id] ?? throw new RuntimeException("Unknown cassette [{$id}].");
    }
}
