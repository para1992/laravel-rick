<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use JsonException;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseStage;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use UnexpectedValueException;

final readonly class StructuredResponseDiagnosticCodec
{
    private const int VERSION = 1;

    public function encode(StructuredResponseDiagnostic $diagnostic): string
    {
        return json_encode([
            'schema_version' => self::VERSION,
            'diagnostic' => $diagnostic->toArray(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $payload): StructuredResponseDiagnostic
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException(
                'Persisted structured response diagnostic is not valid JSON.',
                previous: $error,
            );
        }
        $envelope = JsonInput::map($decoded, 'structured response diagnostic envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported structured response diagnostic schema version.');
        }

        return $this->decodeArray(JsonInput::map(
            $envelope['diagnostic'] ?? null,
            'structured response diagnostic',
        ));
    }

    /** @param array<string, mixed> $data */
    public function decodeArray(array $data): StructuredResponseDiagnostic
    {
        if (($data['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported structured response diagnostic data version.');
        }

        return new StructuredResponseDiagnostic(
            StructuredResponseStage::from(JsonInput::string($data['stage'] ?? null, 'diagnostic.stage')),
            ResponseContract::from(JsonInput::string($data['contract'] ?? null, 'diagnostic.contract')),
            JsonInput::string($data['schema_fingerprint'] ?? null, 'diagnostic.schema_fingerprint'),
            JsonInput::boolean($data['response_present'] ?? null, 'diagnostic.response_present'),
            JsonInput::integer($data['response_bytes'] ?? null, 'diagnostic.response_bytes'),
            JsonInput::nullableString($data['response_fingerprint'] ?? null, 'diagnostic.response_fingerprint'),
            StructuredDecodeStatus::from(JsonInput::string($data['decode_status'] ?? null, 'diagnostic.decode_status')),
            JsonInput::nullableString($data['decoded_root_type'] ?? null, 'diagnostic.decoded_root_type'),
            JsonInput::nullableString($data['validation_path'] ?? null, 'diagnostic.validation_path'),
            JsonInput::nullableString($data['validation_keyword'] ?? null, 'diagnostic.validation_keyword'),
            JsonInput::nullableString($data['finish_reason'] ?? null, 'diagnostic.finish_reason'),
            JsonInput::boolean($data['usage_present'] ?? null, 'diagnostic.usage_present'),
            JsonInput::boolean($data['usage_complete'] ?? null, 'diagnostic.usage_complete'),
            JsonInput::nullableString($data['retry_decision'] ?? null, 'diagnostic.retry_decision'),
        );
    }
}
