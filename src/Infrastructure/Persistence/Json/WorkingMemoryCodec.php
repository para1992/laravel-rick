<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use JsonException;
use Rick\Laravel\Domain\Memory\WorkingMemory;
use UnexpectedValueException;

final readonly class WorkingMemoryCodec
{
    private const int VERSION = 1;

    public function encode(WorkingMemory $memory): string
    {
        return json_encode(
            ['schema_version' => self::VERSION, 'memory' => $memory->toArray()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function decode(string $payload): WorkingMemory
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted working memory is not valid JSON.', previous: $error);
        }
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Persisted working memory must be an object.');
        }
        $envelope = JsonInput::map($decoded, 'working memory envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported working memory schema version.');
        }

        return WorkingMemory::fromArray(JsonInput::map(
            $envelope['memory'] ?? null,
            'working memory',
        ));
    }
}
