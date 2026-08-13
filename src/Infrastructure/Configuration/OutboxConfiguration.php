<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

use InvalidArgumentException;

final readonly class OutboxConfiguration
{
    public function __construct(
        public int $batchSize,
        public int $leaseSeconds,
        public int $maxAttempts,
        public int $retryBaseSeconds,
        public int $retryMaxSeconds,
        public bool $scheduleEnabled,
    ) {
        if ($retryMaxSeconds < $retryBaseSeconds) {
            throw new InvalidArgumentException('Outbox retry maximum must not be below its base delay.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        ConfigurationInput::keys($input, [
            'batch_size', 'lease_seconds', 'max_attempts', 'retry_base_seconds',
            'retry_max_seconds', 'schedule_enabled',
        ], 'outbox');

        return new self(
            ConfigurationInput::integer($input['batch_size'] ?? null, 'outbox.batch_size', 1, 10000),
            ConfigurationInput::integer($input['lease_seconds'] ?? null, 'outbox.lease_seconds', 1),
            ConfigurationInput::integer($input['max_attempts'] ?? null, 'outbox.max_attempts', 1),
            ConfigurationInput::integer($input['retry_base_seconds'] ?? null, 'outbox.retry_base_seconds', 1),
            ConfigurationInput::integer($input['retry_max_seconds'] ?? null, 'outbox.retry_max_seconds', 1),
            ConfigurationInput::boolean($input['schedule_enabled'] ?? null, 'outbox.schedule_enabled'),
        );
    }
}
