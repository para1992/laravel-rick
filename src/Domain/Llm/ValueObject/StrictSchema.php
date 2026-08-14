<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Llm\ValueObject;

use InvalidArgumentException;

final class StrictSchema
{
    /**
     * @param  array<mixed>  $properties
     * @return array<string, mixed>
     */
    public static function object(array $properties): array
    {
        if ($properties === [] || array_is_list($properties)) {
            throw new InvalidArgumentException('Strict schema objects must declare at least one property.');
        }

        $normalized = [];
        foreach ($properties as $name => $schema) {
            if (! is_string($name) || $name === '' || ! is_array($schema) || array_is_list($schema)) {
                throw new InvalidArgumentException('Strict schema object properties must use non-empty string names and object schemas.');
            }
            $normalized[$name] = self::map($schema, "$.{$name}");
        }

        return [
            'type' => 'object',
            'properties' => $normalized,
            'required' => array_keys($normalized),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<mixed>  $schema
     * @return array<string, mixed>
     */
    public static function nullable(array $schema): array
    {
        if (array_is_list($schema)) {
            throw new InvalidArgumentException('Nullable strict schemas must be objects.');
        }
        $schema = self::map($schema, '$');

        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            $schema['type'] = $type === 'null' ? 'null' : [$type, 'null'];

            return $schema;
        }
        if (! is_array($type) || ! array_is_list($type) || $type === []) {
            throw new InvalidArgumentException('Nullable strict schemas must declare a non-empty type.');
        }
        foreach ($type as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidArgumentException('Nullable strict schema types must be non-empty strings.');
            }
        }
        if (! in_array('null', $type, true)) {
            $type[] = 'null';
        }
        $schema['type'] = $type;

        return $schema;
    }

    /** @param array<string, mixed> $schema */
    public static function assertStrict(array $schema, string $path = '$'): void
    {
        if (! self::hasType($schema, 'object')) {
            throw new InvalidArgumentException("Structured output schema root [{$path}] must be an object.");
        }

        self::assertObject($schema, $path);
    }

    /** @param array<string, mixed> $schema */
    private static function assertObject(array $schema, string $path): void
    {
        if (self::hasType($schema, 'object')) {
            $properties = $schema['properties'] ?? null;
            if (! is_array($properties) || array_is_list($properties)) {
                throw new InvalidArgumentException(
                    "Structured output object [{$path}] must declare at least one property.",
                );
            }
            $propertyNames = [];
            foreach ($properties as $name => $property) {
                if (! is_string($name) || $name === '' || ! is_array($property) || array_is_list($property)) {
                    throw new InvalidArgumentException(
                        "Structured output properties at [{$path}] are invalid.",
                    );
                }
                $propertyNames[] = $name;
                self::assertNested(self::map($property, "{$path}.{$name}"), "{$path}.{$name}");
            }
            $required = $schema['required'] ?? null;
            if (! is_array($required) || ! array_is_list($required)) {
                throw new InvalidArgumentException(
                    "Structured output object [{$path}] must require every declared property.",
                );
            }
            $requiredNames = [];
            foreach ($required as $name) {
                if (! is_string($name) || $name === '') {
                    throw new InvalidArgumentException(
                        "Structured output required fields at [{$path}] must be non-empty strings.",
                    );
                }
                $requiredNames[] = $name;
            }
            foreach (array_values(array_diff($propertyNames, $requiredNames)) as $name) {
                throw new InvalidArgumentException(
                    "Structured output property [{$path}.{$name}] is declared but missing from [required]; use a nullable type for optional values.",
                );
            }
            foreach (array_values(array_diff($requiredNames, $propertyNames)) as $name) {
                throw new InvalidArgumentException(
                    "Structured output required field [{$path}.{$name}] is not declared in [properties].",
                );
            }
            if (
                ! array_key_exists('additionalProperties', $schema)
                || $schema['additionalProperties'] !== false
            ) {
                throw new InvalidArgumentException(
                    "Structured output object [{$path}] must forbid additional properties.",
                );
            }
        }

        self::assertNested($schema, $path);
    }

    /** @param array<string, mixed> $schema */
    private static function assertNested(array $schema, string $path): void
    {
        if (isset($schema['items'])) {
            if (! is_array($schema['items'])) {
                throw new InvalidArgumentException("Structured output array items at [{$path}] are invalid.");
            }
            self::assertObject(self::map($schema['items'], "{$path}[]"), "{$path}[]");
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (! isset($schema[$keyword])) {
                continue;
            }
            $branches = $schema[$keyword];
            if (! is_array($branches) || ! array_is_list($branches)) {
                throw new InvalidArgumentException(
                    "Structured output composition [{$path}.{$keyword}] must be a list.",
                );
            }
            foreach ($branches as $index => $branch) {
                if (! is_array($branch)) {
                    throw new InvalidArgumentException(
                        "Structured output branch [{$path}.{$keyword}.{$index}] is invalid.",
                    );
                }
                self::assertObject(
                    self::map($branch, "{$path}.{$keyword}.{$index}"),
                    "{$path}.{$keyword}.{$index}",
                );
            }
        }

        foreach (['$defs', 'definitions', 'patternProperties', 'dependentSchemas'] as $keyword) {
            if (! isset($schema[$keyword])) {
                continue;
            }
            if (! is_array($schema[$keyword]) || array_is_list($schema[$keyword])) {
                throw new InvalidArgumentException(
                    "Structured output schema map [{$path}.{$keyword}] is invalid.",
                );
            }
            foreach ($schema[$keyword] as $name => $branch) {
                if (! is_string($name) || ! is_array($branch)) {
                    throw new InvalidArgumentException(
                        "Structured output schema branch [{$path}.{$keyword}] is invalid.",
                    );
                }
                self::assertObject(
                    self::map($branch, "{$path}.{$keyword}.{$name}"),
                    "{$path}.{$keyword}.{$name}",
                );
            }
        }

        foreach (['contains', 'not', 'if', 'then', 'else', 'propertyNames'] as $keyword) {
            if (! isset($schema[$keyword])) {
                continue;
            }
            if (! is_array($schema[$keyword])) {
                throw new InvalidArgumentException(
                    "Structured output schema branch [{$path}.{$keyword}] is invalid.",
                );
            }
            self::assertObject(
                self::map($schema[$keyword], "{$path}.{$keyword}"),
                "{$path}.{$keyword}",
            );
        }

        if (isset($schema['prefixItems'])) {
            $prefixItems = $schema['prefixItems'];
            if (! is_array($prefixItems) || ! array_is_list($prefixItems)) {
                throw new InvalidArgumentException(
                    "Structured output prefix items at [{$path}] must be a list.",
                );
            }
            foreach ($prefixItems as $index => $branch) {
                if (! is_array($branch)) {
                    throw new InvalidArgumentException(
                        "Structured output prefix item [{$path}.{$index}] is invalid.",
                    );
                }
                self::assertObject(
                    self::map($branch, "{$path}.prefixItems.{$index}"),
                    "{$path}.prefixItems.{$index}",
                );
            }
        }
    }

    /** @param array<string, mixed> $schema */
    private static function hasType(array $schema, string $expected): bool
    {
        $type = $schema['type'] ?? null;

        return $type === $expected || (is_array($type) && in_array($expected, $type, true));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private static function map(array $value, string $path): array
    {
        if (array_is_list($value)) {
            throw new InvalidArgumentException("Structured output schema [{$path}] must be an object.");
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("Structured output schema [{$path}] must be an object.");
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
