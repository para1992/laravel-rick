<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

use InvalidArgumentException;

final readonly class QueueConfiguration
{
    public function __construct(
        public ?string $connection,
        public string $control,
        public string $llm,
        public QueueJobConfiguration $continue,
        public QueueJobConfiguration $invocation,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        ConfigurationInput::keys(
            $input,
            ['connection', 'control', 'llm', 'continue', 'invocation'],
            'queue',
        );
        $connection = ConfigurationInput::nullableString($input['connection'] ?? null, 'queue.connection');
        if ($connection !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $connection) !== 1) {
            throw new InvalidArgumentException('Rick configuration [queue.connection] is invalid.');
        }

        return new self(
            $connection,
            ConfigurationInput::queueName($input['control'] ?? null, 'queue.control'),
            ConfigurationInput::queueName($input['llm'] ?? null, 'queue.llm'),
            QueueJobConfiguration::from(
                ConfigurationInput::map($input['continue'] ?? null, 'queue.continue'),
                'queue.continue',
            ),
            QueueJobConfiguration::from(
                ConfigurationInput::map($input['invocation'] ?? null, 'queue.invocation'),
                'queue.invocation',
            ),
        );
    }
}
