<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Schema;

use JsonException;
use Rick\Laravel\Application\Execution\Exception\StructuredResponseException;
use Rick\Laravel\Application\Execution\Support\Quality\ContentDistinctness;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Exception\JsonSchemaViolationException;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;

final readonly class CompletionResponseValidator
{
    public function __construct(
        private JsonSchemaValidatorBase $schemas,
        private ResponseSchemaResolver $responseSchemas,
        private StructuredResponseDecoder $decoder,
        private ContentDistinctness $distinctness,
    ) {}

    public function assert(
        CompletionRequest $request,
        CompletionResponse $response,
    ): ?StructuredResponseDiagnostic {
        if ($request->responseContract === ResponseContract::Text) {
            return null;
        }

        $diagnostic = $response->diagnostic;
        $structured = $response->structured;
        if ($diagnostic === null) {
            $metrics = $response->metrics;
            $inspection = $this->decoder->decode(
                $request,
                self::diagnosticText($response),
                null,
                $metrics !== null && $metrics->usagePresent,
                $metrics !== null && $metrics->usageComplete,
            );
            $diagnostic = $inspection->diagnostic;
            $structured ??= $inspection->value;
        }

        if ($diagnostic->decodeStatus !== StructuredDecodeStatus::Object || $structured === null) {
            throw new StructuredResponseException(
                'Structured completion response could not be decoded as a JSON object.',
                $diagnostic,
            );
        }

        try {
            $this->schemas->assert(
                $this->responseSchemas->for($request),
                $structured === [] ? (object) [] : $structured,
            );
        } catch (JsonSchemaViolationException $error) {
            throw new StructuredResponseException(
                'Completion response violates its schema: '.$error->getMessage(),
                $diagnostic->validationFailure($error->path, $error->keyword),
                previous: $error,
            );
        }

        $policy = self::object($request->metadata['content_distinctness'] ?? null);
        if ($policy !== null) {
            $content = $structured['content'] ?? null;
            if (is_string($content)) {
                $violation = $this->distinctness->violation($content, $policy);
                if ($violation !== null) {
                    throw new StructuredResponseException(
                        'Completion response repeats a previously accepted artifact.',
                        $diagnostic->validationFailure('$.content', $violation),
                    );
                }
            }
        }
        $sourceSignature = self::object($request->metadata['source_unit_signature'] ?? null);
        $content = $structured['content'] ?? null;
        if (
            $sourceSignature !== null
            && is_string($content)
            && $this->distinctness->restates($content, $sourceSignature)
        ) {
            throw new StructuredResponseException(
                'Completion response restates the source unit instead of producing its artifact.',
                $diagnostic->validationFailure('$.content', 'source_restatement'),
            );
        }
        if (is_string($content)) {
            foreach (self::stringList($request->metadata['required_literals'] ?? null) as $literal) {
                if (! str_contains($content, $literal)) {
                    throw new StructuredResponseException(
                        'Completion response omitted a required literal.',
                        $diagnostic->validationFailure('$.content', 'required_literal_missing'),
                    );
                }
            }
        }

        return $diagnostic;
    }

    private static function diagnosticText(CompletionResponse $response): string
    {
        if ($response->structured !== null) {
            try {
                return json_encode(
                    $response->structured,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
            } catch (JsonException) {
                return '';
            }
        }

        return $response->text;
    }

    /** @return array<string, mixed>|null */
    private static function object(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }
        $mapped = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }
            $mapped[$key] = $item;
        }

        return $mapped;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }
}
