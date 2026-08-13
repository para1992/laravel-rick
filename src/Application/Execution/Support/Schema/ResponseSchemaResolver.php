<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Schema;

use InvalidArgumentException;
use JsonException;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;

final readonly class ResponseSchemaResolver
{
    public function __construct(private JsonSchemaValidatorBase $schemas) {}

    /** @return array<string, mixed> */
    public function for(CompletionRequest $request): array
    {
        if ($request->responseContract === ResponseContract::Text) {
            throw new InvalidArgumentException('Text completions do not have a structured output schema.');
        }

        $schema = $request->responseSchema ?? self::packageSchema($request->responseContract);
        $this->schemas->assertSchema($schema);
        if (! self::hasType($schema, 'object')) {
            throw new InvalidArgumentException('Structured output schema root must be an object.');
        }
        self::assertStrictObject($schema, '$');

        return $schema;
    }

    public function fingerprint(CompletionRequest $request): string
    {
        try {
            $json = json_encode(
                $this->for($request),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'Structured output schema is not JSON serializable.',
                previous: $error,
            );
        }

        return hash('sha256', $json);
    }

    /** @return array<string, mixed> */
    private static function packageSchema(ResponseContract $contract): array
    {
        return match ($contract) {
            ResponseContract::Candidate,
            ResponseContract::PlanCandidate => self::object([
                'content' => ['type' => 'string'],
            ]),
            ResponseContract::MemoryCandidate => self::object([
                'content' => ['type' => 'string'],
                'memory_delta' => self::object([
                    'facts_added' => self::strings(),
                    'decisions_added' => self::strings(),
                    'loops_opened' => self::strings(),
                    'loops_resolved' => self::strings(),
                    'requirements_covered' => self::strings(),
                    'requirements_violated' => self::strings(),
                ]),
            ]),
            ResponseContract::Judge => self::object([
                'selected_candidate_id' => ['type' => 'string'],
                'score' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
            ]),
            ResponseContract::UnfoldUnits => self::object([
                'units' => [
                    'type' => 'array',
                    'items' => self::object([
                        'unit_id' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'source_order' => ['type' => 'integer'],
                        'content' => ['type' => 'string'],
                        'constraints' => self::strings(),
                        'must_preserve' => self::strings(),
                        'dependencies' => self::strings(),
                        'must_cover' => self::strings(),
                        'must_not_repeat' => self::strings(),
                        'memory_reads' => self::strings(),
                        'memory_writes' => self::strings(),
                    ]),
                ],
            ]),
            ResponseContract::DefinitionOfDone => self::object([
                'criteria' => self::strings(),
            ]),
            ResponseContract::Json => throw new InvalidArgumentException(
                'The generic JSON response contract requires a response schema.',
            ),
            ResponseContract::Text => throw new InvalidArgumentException(
                'Text completions do not have a structured output schema.',
            ),
        };
    }

    /** @param array<string, mixed> $schema */
    private static function assertStrictObject(array $schema, string $path): void
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
                if (! is_string($name) || $name === '' || ! is_array($property)) {
                    throw new InvalidArgumentException(
                        "Structured output properties at [{$path}] are invalid.",
                    );
                }
                $propertyNames[] = $name;
                self::assertStrictObject(self::map($property, "{$path}.{$name}"), "{$path}.{$name}");
            }
            $required = $schema['required'] ?? null;
            if (! is_array($required) || ! array_is_list($required)) {
                throw new InvalidArgumentException(
                    "Structured output object [{$path}] must require every declared property.",
                );
            }
            $requiredNames = [];
            foreach ($required as $name) {
                if (! is_string($name)) {
                    throw new InvalidArgumentException(
                        "Structured output required fields at [{$path}] must be strings.",
                    );
                }
                $requiredNames[] = $name;
            }
            sort($propertyNames);
            sort($requiredNames);
            if ($requiredNames !== $propertyNames) {
                throw new InvalidArgumentException(
                    "Structured output object [{$path}] must require every declared property.",
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

        if (isset($schema['items'])) {
            if (! is_array($schema['items'])) {
                throw new InvalidArgumentException("Structured output array items at [{$path}] are invalid.");
            }
            self::assertStrictObject(self::map($schema['items'], "{$path}[]"), "{$path}[]");
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
                self::assertStrictObject(
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
                self::assertStrictObject(
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
            self::assertStrictObject(
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
                self::assertStrictObject(
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
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("Structured output schema [{$path}] must be an object.");
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function strings(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }
}
