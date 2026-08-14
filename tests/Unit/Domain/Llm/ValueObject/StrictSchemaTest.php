<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain\Llm\ValueObject;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Llm\ValueObject\StrictSchema;

final class StrictSchemaTest extends TestCase
{
    public function test_object_makes_every_property_required_and_forbids_additional_properties(): void
    {
        $schema = StrictSchema::object([
            'winner' => ['type' => 'integer'],
            'reason' => StrictSchema::nullable(['type' => 'string']),
            'details' => StrictSchema::nullable(StrictSchema::object([
                'confidence' => ['type' => 'number'],
            ])),
        ]);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'winner' => ['type' => 'integer'],
                'reason' => ['type' => ['string', 'null']],
                'details' => [
                    'type' => ['object', 'null'],
                    'properties' => ['confidence' => ['type' => 'number']],
                    'required' => ['confidence'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['winner', 'reason', 'details'],
            'additionalProperties' => false,
        ], $schema);
        StrictSchema::assertStrict($schema);
    }

    public function test_nullable_is_idempotent_and_keeps_existing_type_order(): void
    {
        $schema = StrictSchema::nullable(['type' => ['null', 'string']]);

        self::assertSame(['null', 'string'], StrictSchema::nullable($schema)['type']);
    }

    public function test_builders_reject_malformed_definitions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one property');

        StrictSchema::object([]);
    }

    public function test_nullable_rejects_a_schema_without_a_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare a non-empty type');

        StrictSchema::nullable(['enum' => ['yes', 'no']]);
    }

    public function test_strict_validation_reports_the_missing_property_and_nullable_guidance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[$.winner_confidence]');
        $this->expectExceptionMessage('missing from [required]');
        $this->expectExceptionMessage('nullable type');

        StrictSchema::assertStrict([
            'type' => 'object',
            'properties' => [
                'winner' => ['type' => 'integer'],
                'winner_confidence' => ['type' => 'number'],
            ],
            'required' => ['winner'],
            'additionalProperties' => false,
        ]);
    }
}
