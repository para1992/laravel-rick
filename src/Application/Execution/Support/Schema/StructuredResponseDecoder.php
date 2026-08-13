<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Schema;

use JsonException;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseInspection;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseStage;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use stdClass;

final readonly class StructuredResponseDecoder
{
    public function __construct(private ResponseSchemaResolver $schemas) {}

    public function decode(
        CompletionRequest $request,
        string $text,
        ?string $finishReason,
        bool $usagePresent,
        bool $usageComplete,
    ): StructuredResponseInspection {
        $trimmed = trim($text);
        $present = $trimmed !== '';
        $fingerprint = $present ? hash('sha256', $text) : null;

        if (! $present) {
            return $this->inspection(
                $request,
                null,
                StructuredDecodeStatus::Empty,
                null,
                false,
                strlen($text),
                null,
                $finishReason,
                $usagePresent,
                $usageComplete,
            );
        }

        $payload = self::stripFence($trimmed);
        try {
            $decoded = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->inspection(
                $request,
                null,
                StructuredDecodeStatus::InvalidJson,
                null,
                true,
                strlen($text),
                $fingerprint,
                $finishReason,
                $usagePresent,
                $usageComplete,
            );
        }

        if (! $decoded instanceof stdClass) {
            $status = is_array($decoded)
                ? StructuredDecodeStatus::Array
                : StructuredDecodeStatus::Scalar;

            return $this->inspection(
                $request,
                null,
                $status,
                self::rootType($decoded),
                true,
                strlen($text),
                $fingerprint,
                $finishReason,
                $usagePresent,
                $usageComplete,
            );
        }

        try {
            $mapped = json_decode(
                json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $mapped = null;
        }

        return $this->inspection(
            $request,
            is_array($mapped) ? self::map($mapped) : null,
            StructuredDecodeStatus::Object,
            'object',
            true,
            strlen($text),
            $fingerprint,
            $finishReason,
            $usagePresent,
            $usageComplete,
        );
    }

    /** @param array<string, mixed>|null $value */
    private function inspection(
        CompletionRequest $request,
        ?array $value,
        StructuredDecodeStatus $status,
        ?string $rootType,
        bool $present,
        int $bytes,
        ?string $fingerprint,
        ?string $finishReason,
        bool $usagePresent,
        bool $usageComplete,
    ): StructuredResponseInspection {
        return new StructuredResponseInspection(
            $value,
            new StructuredResponseDiagnostic(
                StructuredResponseStage::Decode,
                $request->responseContract,
                $this->schemas->fingerprint($request),
                $present,
                $bytes,
                $fingerprint,
                $status,
                $rootType,
                null,
                null,
                $finishReason,
                $usagePresent,
                $usagePresent && $usageComplete,
            ),
        );
    }

    private static function stripFence(string $text): string
    {
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return $text;
    }

    private static function rootType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            is_array($value) => 'array',
            default => 'unknown',
        };
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $mapped = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $mapped[$key] = $item;
            }
        }

        return $mapped;
    }
}
