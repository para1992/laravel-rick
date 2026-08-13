<?php

declare(strict_types=1);

namespace Rick\Laravel;

use JsonSerializable;

final readonly class OutboxRelayReceipt implements JsonSerializable
{
    public function __construct(
        public int $claimed = 0,
        public int $delivered = 0,
        public int $deferred = 0,
        public int $failed = 0,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'claimed' => $this->claimed,
            'delivered' => $this->delivered,
            'deferred' => $this->deferred,
            'failed' => $this->failed,
        ];
    }

    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
