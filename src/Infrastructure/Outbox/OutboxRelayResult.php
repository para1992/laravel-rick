<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Outbox;

final readonly class OutboxRelayResult
{
    public function __construct(
        public int $claimed = 0,
        public int $delivered = 0,
        public int $deferred = 0,
        public int $failed = 0,
    ) {}
}
