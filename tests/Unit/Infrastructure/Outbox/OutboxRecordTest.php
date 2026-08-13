<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Outbox;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Infrastructure\Outbox\OutboxRecord;
use UnexpectedValueException;

final class OutboxRecordTest extends TestCase
{
    public function test_hydrates_string_integer_and_nullable_fields(): void
    {
        $record = OutboxRecord::fromRow((object) [
            'id' => 'outbox-1',
            'kind' => 'continue_run',
            'run_id' => 'run-1',
            'invocation_id' => null,
            'event_type' => null,
            'payload' => '{"safe":true}',
            'attempts' => '2',
            'version' => 3,
            'lease_token' => 'lease-1',
        ]);

        self::assertSame('outbox-1', $record->id);
        self::assertNull($record->invocationId);
        self::assertSame('{"safe":true}', $record->payload);
        self::assertSame(2, $record->attempts);
        self::assertSame(3, $record->version);
    }

    public function test_rejects_invalid_required_nullable_and_integer_fields(): void
    {
        $base = [
            'id' => 'outbox-1',
            'kind' => 'continue_run',
            'run_id' => 'run-1',
            'invocation_id' => null,
            'event_type' => null,
            'payload' => null,
            'attempts' => 0,
            'version' => 1,
            'lease_token' => 'lease-1',
        ];
        $rows = [
            $base + ['missing' => true],
            array_replace($base, ['id' => '']),
            array_replace($base, ['invocation_id' => 1]),
            array_replace($base, ['attempts' => '1.5']),
        ];
        unset($rows[0]['id']);

        foreach ($rows as $row) {
            try {
                OutboxRecord::fromRow((object) $row);
                self::fail('Invalid outbox row was accepted.');
            } catch (UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
