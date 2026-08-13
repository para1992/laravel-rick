<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use JsonException;
use UnexpectedValueException;

final readonly class InteractionStateCodec
{
    private const int VERSION = 1;

    /** @param array<string, mixed> $state */
    public function encode(array $state): string
    {
        return json_encode(
            ['schema_version' => self::VERSION, 'interaction' => $state],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string, mixed> */
    public function decode(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted interaction state is not valid JSON.', previous: $error);
        }
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Persisted interaction state must be an object.');
        }
        $envelope = JsonInput::map($decoded, 'interaction state envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported interaction state schema version.');
        }

        return JsonInput::map($envelope['interaction'] ?? null, 'interaction state');
    }
}
