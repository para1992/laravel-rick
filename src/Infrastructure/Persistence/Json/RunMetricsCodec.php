<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use JsonException;
use UnexpectedValueException;

final readonly class RunMetricsCodec
{
    private const int VERSION = 1;

    /** @param array<string, mixed> $metrics */
    public function encode(array $metrics): string
    {
        return json_encode(
            ['schema_version' => self::VERSION, 'metrics' => $metrics],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string, mixed> */
    public function decode(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted run metrics are not valid JSON.', previous: $error);
        }
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Persisted run metrics must be an object.');
        }
        $envelope = JsonInput::map($decoded, 'run metrics envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported run metrics schema version.');
        }

        return JsonInput::map($envelope['metrics'] ?? null, 'run metrics');
    }
}
