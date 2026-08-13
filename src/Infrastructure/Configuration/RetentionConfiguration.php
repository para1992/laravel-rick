<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

final readonly class RetentionConfiguration
{
    public function __construct(
        public int $batchSize,
        public bool $scheduleEnabled,
        public ?int $cutoffDays,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        ConfigurationInput::keys($input, ['batch_size', 'schedule_enabled', 'cutoff_days'], 'retention');
        $cutoff = $input['cutoff_days'] ?? null;

        return new self(
            ConfigurationInput::integer($input['batch_size'] ?? null, 'retention.batch_size', 1, 10000),
            ConfigurationInput::boolean($input['schedule_enabled'] ?? null, 'retention.schedule_enabled'),
            $cutoff === null
                ? null
                : ConfigurationInput::integer($cutoff, 'retention.cutoff_days', 1, 36500),
        );
    }
}
