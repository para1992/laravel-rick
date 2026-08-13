<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use InvalidArgumentException;
use Rick\Laravel\Domain\Exception\CallBudgetExceededException;

final class CallBudget
{
    private int $used = 0;

    public function __construct(
        private readonly int $limit,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('Call limit must be at least 1.');
        }
    }

    public static function restore(int $limit, int $used): self
    {
        $budget = new self($limit);

        if ($used < 0 || $used > $limit) {
            throw new InvalidArgumentException(
                'Restored call usage must be between zero and the call limit.',
            );
        }

        $budget->used = $used;

        return $budget;
    }

    public function reserve(string $purpose): int
    {
        $next = $this->used + 1;

        if ($next > $this->limit) {
            throw new CallBudgetExceededException($next, $this->limit, $purpose);
        }

        return $this->used = $next;
    }

    /** @return non-empty-list<int> */
    public function reserveMany(int $count, string $purpose): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Reserved call count must be at least 1.');
        }

        $last = $this->used + $count;

        if ($last > $this->limit) {
            throw new CallBudgetExceededException($last, $this->limit, $purpose);
        }

        $calls = range($this->used + 1, $last);
        $this->used = $last;

        return $calls;
    }

    public function used(): int
    {
        return $this->used;
    }

    public function limit(): int
    {
        return $this->limit;
    }
}
