<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Exception\CallBudgetExceededException;
use Rick\Laravel\Domain\Run\CallBudget;

final class CallBudgetTest extends TestCase
{
    public function test_reserves_single_and_multiple_calls_atomically(): void
    {
        $budget = new CallBudget(4);
        self::assertSame(0, $budget->used());
        self::assertSame(4, $budget->limit());
        self::assertSame(1, $budget->reserve('single'));
        self::assertSame([2, 3], $budget->reserveMany(2, 'batch'));
        self::assertSame(3, $budget->used());

        $restored = CallBudget::restore(4, 3);
        self::assertSame(3, $restored->used());
        self::assertSame(4, $restored->reserve('last'));
    }

    public function test_rejects_invalid_limits_restore_usage_and_batch_counts(): void
    {
        $operations = [
            static fn () => new CallBudget(0),
            static fn () => CallBudget::restore(2, -1),
            static fn () => CallBudget::restore(2, 3),
            static fn () => (new CallBudget(2))->reserveMany(0, 'batch'),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Invalid call budget state was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_budget_exhaustion_does_not_consume_calls(): void
    {
        $budget = new CallBudget(1);
        $budget->reserve('first');
        foreach ([
            static fn () => $budget->reserve('single overflow'),
            static fn () => $budget->reserveMany(2, 'batch overflow'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Call budget overflow was accepted.');
            } catch (CallBudgetExceededException) {
                self::assertSame(1, $budget->used());
            }
        }
    }
}
