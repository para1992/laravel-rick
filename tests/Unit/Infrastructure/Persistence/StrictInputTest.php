<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Infrastructure\Persistence\DatabaseRow;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use stdClass;
use UnexpectedValueException;

final class StrictInputTest extends TestCase
{
    public function test_generic_array_input_preserves_lists_and_maps_but_rejects_scalars(): void
    {
        self::assertSame(['one', 'two'], JsonInput::valueArray(['one', 'two'], 'payload'));
        self::assertSame(['key' => 'value'], JsonInput::valueArray(['key' => 'value'], 'payload'));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Persisted [payload] must be an array.');
        JsonInput::valueArray('not-an-array', 'payload');
    }

    public function test_database_row_reads_every_supported_shape(): void
    {
        $timestamp = new DateTimeImmutable('2026-08-08T12:00:00+00:00');
        $row = DatabaseRow::from((object) [
            'string' => 'value',
            'nullable' => null,
            'integer' => 2,
            'numeric_string' => '3',
            'timestamp' => $timestamp,
            'timestamp_string' => '2026-08-08T13:00:00+00:00',
        ]);

        self::assertSame('value', $row->value('string'));
        self::assertSame('value', $row->valueOr('string', 'default'));
        self::assertSame('default', $row->valueOr('missing', 'default'));
        self::assertTrue($row->has('nullable'));
        self::assertFalse($row->has('missing'));
        self::assertSame('value', $row->string('string'));
        self::assertNull($row->nullableString('nullable'));
        self::assertSame('default', $row->nullableStringOr('missing', 'default'));
        self::assertSame(2, $row->integer('integer'));
        self::assertSame(3, $row->integer('numeric_string'));
        self::assertSame(4, $row->integerOr('missing', 4));
        self::assertSame(5, DatabaseRow::integerValue(null, 'value', 5));
        self::assertEquals($timestamp, $row->timestamp('timestamp'));
        self::assertSame('13:00', $row->timestamp('timestamp_string')->format('H:i'));
        self::assertNull($row->nullableTimestamp('nullable'));
    }

    public function test_database_row_rejects_missing_and_wrong_typed_values(): void
    {
        $row = DatabaseRow::from((object) [
            'string' => 1,
            'nullable_string' => 1,
            'integer' => '1.5',
            'timestamp' => [],
            'bad_timestamp' => 'not-a-timestamp',
        ]);
        $operations = [
            static fn () => $row->value('missing'),
            static fn () => $row->string('string'),
            static fn () => $row->nullableString('nullable_string'),
            static fn () => $row->integer('integer'),
            static fn () => DatabaseRow::integerValue(new stdClass, 'integer'),
            static fn () => $row->timestamp('timestamp'),
            static fn () => $row->timestamp('bad_timestamp'),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Invalid database value was accepted.');
            } catch (UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_json_input_reads_every_supported_shape(): void
    {
        self::assertSame(['key' => 'value'], JsonInput::map(['key' => 'value'], 'map'));
        self::assertSame(['value'], JsonInput::list(['value'], 'list'));
        self::assertSame(['one', 'two'], JsonInput::strings(['one', 'two'], 'strings'));
        self::assertSame('value', JsonInput::string('value', 'string'));
        self::assertNull(JsonInput::nullableString(null, 'nullable'));
        self::assertSame('value', JsonInput::nullableString('value', 'nullable'));
        self::assertSame(1, JsonInput::integer(1, 'integer'));
        self::assertNull(JsonInput::nullableInteger(null, 'nullable_integer'));
        self::assertSame(1, JsonInput::nullableInteger(1, 'nullable_integer'));
        self::assertSame(1.0, JsonInput::number(1, 'number'));
        self::assertSame(1.5, JsonInput::number(1.5, 'number'));
        self::assertTrue(JsonInput::boolean(true, 'boolean'));
    }

    public function test_json_input_rejects_every_wrong_shape(): void
    {
        $operations = [
            static fn () => JsonInput::map('value', 'map'),
            static fn () => JsonInput::map(['value'], 'map'),
            static fn () => JsonInput::map([1 => 'value'], 'map'),
            static fn () => JsonInput::list(['key' => 'value'], 'list'),
            static fn () => JsonInput::strings([1], 'strings'),
            static fn () => JsonInput::string(1, 'string'),
            static fn () => JsonInput::integer('1', 'integer'),
            static fn () => JsonInput::number('1', 'number'),
            static fn () => JsonInput::boolean(1, 'boolean'),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Invalid persisted JSON input was accepted.');
            } catch (UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
