<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Infrastructure\Configuration\ConfigurationInput;

final class ConfigurationInputTest extends TestCase
{
    public function test_every_typed_accessor_accepts_and_normalizes_valid_values(): void
    {
        ConfigurationInput::keys(['known' => true], ['known'], 'root');
        self::assertSame(['key' => 'value'], ConfigurationInput::map(['key' => 'value'], 'map'));
        self::assertSame(['value'], ConfigurationInput::list(['value'], 'list'));
        self::assertSame('value', ConfigurationInput::string(' value ', 'string'));
        self::assertNull(ConfigurationInput::nullableString(null, 'nullable'));
        self::assertSame('value', ConfigurationInput::nullableString(' value ', 'nullable'));
        self::assertTrue(ConfigurationInput::boolean(true, 'boolean'));
        self::assertSame(2, ConfigurationInput::integer(2, 'integer', 1, 3));
        self::assertSame([1, 2], ConfigurationInput::integerList([1, 2], 'integers', 1));
        self::assertSame(['one', 'two'], ConfigurationInput::stringList([' one ', 'two'], 'strings'));
        self::assertSame('valid-id', ConfigurationInput::identifier('valid-id', 'identifier'));
        self::assertSame('rick_runs_2', ConfigurationInput::table('rick_runs_2', 'table'));
        self::assertSame('rick.queue-2', ConfigurationInput::queueName('rick.queue-2', 'queue'));
        self::assertSame('1.250', ConfigurationInput::decimal('1.250', 'decimal'));
    }

    public function test_every_typed_accessor_rejects_invalid_values(): void
    {
        $tooLongTable = 't'.str_repeat('x', 63);
        $tooLongQueue = 'q'.str_repeat('x', 128);
        $operations = [
            static fn () => ConfigurationInput::keys(['unknown' => true], ['known'], 'root'),
            static fn () => ConfigurationInput::map('value', 'map'),
            static fn () => ConfigurationInput::map(['value'], 'map'),
            static fn () => ConfigurationInput::map([1 => 'value'], 'map'),
            static fn () => ConfigurationInput::list(['key' => 'value'], 'list'),
            static fn () => ConfigurationInput::string(' ', 'string'),
            static fn () => ConfigurationInput::boolean(1, 'boolean'),
            static fn () => ConfigurationInput::integer(0, 'integer', 1),
            static fn () => ConfigurationInput::integer(4, 'integer', 1, 3),
            static fn () => ConfigurationInput::integerList([0], 'integers', 1),
            static fn () => ConfigurationInput::stringList([1], 'strings'),
            static fn () => ConfigurationInput::identifier("\xFF", 'identifier'),
            static fn () => ConfigurationInput::table('2bad', 'table'),
            static fn () => ConfigurationInput::table($tooLongTable, 'table'),
            static fn () => ConfigurationInput::queueName('-bad', 'queue'),
            static fn () => ConfigurationInput::queueName($tooLongQueue, 'queue'),
            static fn () => ConfigurationInput::decimal(1.2, 'decimal'),
            static fn () => ConfigurationInput::decimal('-1', 'decimal'),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Invalid configuration input was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('configuration', strtolower($error->getMessage()));
            }
        }
    }
}
