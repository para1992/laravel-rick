<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

final readonly class QueueJobConfiguration
{
    /** @param list<int> $backoff */
    public function __construct(
        public int $tries,
        public int $timeout,
        public array $backoff,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input, string $path): self
    {
        ConfigurationInput::keys($input, ['tries', 'timeout', 'backoff'], $path);

        return new self(
            ConfigurationInput::integer($input['tries'] ?? null, "{$path}.tries", 1),
            ConfigurationInput::integer($input['timeout'] ?? null, "{$path}.timeout", 1),
            ConfigurationInput::integerList($input['backoff'] ?? null, "{$path}.backoff", 0),
        );
    }
}
