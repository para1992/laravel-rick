<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Schema;

use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Exception\JsonSchemaViolationException;
use stdClass;
use Throwable;

final readonly class JsonSchemaValidator implements JsonSchemaValidatorBase
{
    public function __construct(
        private CompliantValidator $validator = new CompliantValidator(null, 20, false),
        private ErrorFormatter $errors = new ErrorFormatter,
    ) {}

    public function assertSchema(array $schema): void
    {
        self::rejectRemoteReferences($schema);

        try {
            // Loading parses every declared keyword. The validation result is
            // ignored because null is only a schema-compilation probe.
            $this->validator->validate(null, self::schemaValue($schema));
        } catch (Throwable $error) {
            throw new InvalidArgumentException(
                'JSON Schema is invalid for Draft 2020-12.',
                previous: $error,
            );
        }
    }

    public function assert(array $schema, mixed $value): void
    {
        $this->assertSchema($schema);

        try {
            $result = $this->validator->validate(
                self::jsonValue($value),
                self::schemaValue($schema),
            );
        } catch (Throwable $error) {
            throw new InvalidArgumentException(
                'JSON value could not be validated against its schema.',
                previous: $error,
            );
        }

        $error = $result->error();
        if ($error === null) {
            return;
        }

        $details = $this->errors->formatFlat(
            $error,
            fn (ValidationError $failure): array => [
                'path' => $this->errors->formatErrorKey($failure),
                'keyword' => $failure->keyword(),
            ],
        );
        $detail = end($details);
        $path = is_array($detail) && is_string($detail['path'] ?? null)
            ? $detail['path']
            : '/';
        $keyword = is_array($detail) && is_string($detail['keyword'] ?? null)
            ? $detail['keyword']
            : 'schema';

        throw new JsonSchemaViolationException($path, $keyword);
    }

    private static function jsonValue(mixed $value): mixed
    {
        try {
            return json_decode(
                json_encode(
                    $value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'JSON Schema and values must be JSON serializable.',
                previous: $error,
            );
        }
    }

    /** @param array<string, mixed> $schema */
    private static function schemaValue(array $schema): object
    {
        $value = self::jsonValue($schema);
        if ($schema === []) {
            return new stdClass;
        }
        if (! is_object($value)) {
            throw new InvalidArgumentException('JSON Schema root must be an object.');
        }

        return $value;
    }

    /** @param array<mixed> $schema */
    private static function rejectRemoteReferences(array $schema): void
    {
        foreach ($schema as $keyword => $value) {
            if (
                is_string($keyword)
                && in_array($keyword, ['$ref', '$dynamicRef', '$recursiveRef'], true)
                && (! is_string($value) || ! str_starts_with($value, '#'))
            ) {
                throw new InvalidArgumentException(
                    "Remote JSON Schema reference in keyword [{$keyword}] is not allowed.",
                );
            }

            if (is_array($value)) {
                self::rejectRemoteReferences($value);
            }
        }
    }
}
