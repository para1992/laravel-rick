<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Rick\Laravel\Infrastructure\Schema\JsonSchemaValidator;

final class JsonSchemaValidatorTest extends TestCase
{
    public function test_draft_2020_12_keywords_compositions_and_local_refs_are_supported(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$defs' => [
                'profile' => [
                    'type' => 'object',
                    'properties' => [
                        'age' => ['type' => 'integer', 'minimum' => 18, 'maximum' => 120],
                        'code' => ['type' => 'string', 'pattern' => '^[A-Z]{2}-[0-9]{3}$'],
                        'role' => ['enum' => ['author', 'reviewer']],
                    ],
                    'required' => ['age', 'code', 'role'],
                    'additionalProperties' => false,
                ],
            ],
            'type' => 'object',
            'properties' => [
                'profile' => ['$ref' => '#/$defs/profile'],
                'contact' => [
                    'oneOf' => [
                        ['type' => 'string', 'format' => 'email'],
                        ['type' => 'string', 'pattern' => '^internal:[a-z]+$'],
                    ],
                ],
            ],
            'required' => ['profile', 'contact'],
            'allOf' => [
                ['not' => ['required' => ['disabled']]],
            ],
        ];

        $validator = new JsonSchemaValidator;
        $validator->assertSchema($schema);
        $validator->assert($schema, [
            'profile' => ['age' => 42, 'code' => 'PL-123', 'role' => 'author'],
            'contact' => 'internal:rick',
        ]);

        self::addToAssertionCount(1);
    }

    public function test_nested_validation_error_exposes_path_and_keyword_but_not_value(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'object',
                    'properties' => [
                        'age' => ['type' => 'integer', 'minimum' => 18],
                    ],
                    'required' => ['age'],
                ],
            ],
            'required' => ['profile'],
        ];

        try {
            (new JsonSchemaValidator)->assert($schema, [
                'profile' => ['age' => 7, 'secret' => 'do-not-echo-this'],
            ]);
            self::fail('The invalid nested value should be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('keyword [minimum]', $error->getMessage());
            self::assertStringContainsString('profile', $error->getMessage());
            self::assertStringNotContainsString('do-not-echo-this', $error->getMessage());
        }
    }

    public function test_enum_pattern_additional_properties_and_composition_failures_are_rejected(): void
    {
        $validator = new JsonSchemaValidator;
        $cases = [
            [
                ['enum' => ['yes', 'no']],
                'maybe',
                'enum',
            ],
            [
                ['type' => 'string', 'pattern' => '^RICK-[0-9]+$'],
                'invalid',
                'pattern',
            ],
            [
                [
                    'type' => 'object',
                    'properties' => ['known' => ['type' => 'boolean']],
                    'additionalProperties' => false,
                ],
                ['known' => true, 'unknown' => true],
                'additionalProperties',
            ],
            [
                ['anyOf' => [['type' => 'integer'], ['type' => 'boolean']]],
                'neither',
                'type',
            ],
        ];

        foreach ($cases as [$schema, $value, $keyword]) {
            try {
                $validator->assert($schema, $value);
                self::fail("Keyword [{$keyword}] should reject the value.");
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString("keyword [{$keyword}]", $error->getMessage());
            }
        }
    }

    public function test_remote_refs_are_rejected_without_a_network_attempt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote JSON Schema reference');

        (new JsonSchemaValidator)->assertSchema([
            '$ref' => 'https://example.invalid/schema.json',
        ]);
    }

    public function test_malformed_schema_is_rejected_during_compilation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON Schema is invalid');

        (new JsonSchemaValidator)->assertSchema([
            'type' => ['object', 42],
        ]);
    }

    public function test_empty_schema_accepts_values_and_schema_root_must_be_an_object(): void
    {
        $validator = new JsonSchemaValidator;
        $validator->assertSchema([]);
        $validator->assert([], ['anything' => true]);
        self::addToAssertionCount(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON Schema is invalid');
        (new ReflectionMethod(JsonSchemaValidator::class, 'assertSchema'))->invoke(
            $validator,
            [['type' => 'string']],
        );
    }

    public function test_non_json_serializable_values_are_rejected_safely(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be validated');

        (new JsonSchemaValidator)->assert([], "\xFF");
    }
}
