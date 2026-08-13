<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

use InvalidArgumentException;

final readonly class InvocationCompletionPolicy
{
    private function __construct(
        public InvocationCompletionMode $mode,
        public ?int $minimumSuccessful,
    ) {
        if (
            $mode === InvocationCompletionMode::MinimumSuccessful
            && ($minimumSuccessful === null || $minimumSuccessful < 1)
        ) {
            throw new InvalidArgumentException('Minimum successful invocations must be positive.');
        }
        if ($mode === InvocationCompletionMode::AllRequired && $minimumSuccessful !== null) {
            throw new InvalidArgumentException('All-required completion does not accept a minimum.');
        }
    }

    public static function allRequired(): self
    {
        return new self(InvocationCompletionMode::AllRequired, null);
    }

    public static function minimumSuccessful(int $minimum): self
    {
        return new self(InvocationCompletionMode::MinimumSuccessful, $minimum);
    }

    public static function restore(InvocationCompletionMode $mode, ?int $minimum): self
    {
        return new self($mode, $minimum);
    }

    public function required(int $expected): int
    {
        if ($expected < 1) {
            throw new InvalidArgumentException('Expected invocation count must be positive.');
        }
        $required = $this->mode === InvocationCompletionMode::AllRequired
            ? $expected
            : ($this->minimumSuccessful ?? $expected);
        if ($required > $expected) {
            throw new InvalidArgumentException('Minimum successful invocations cannot exceed expected invocations.');
        }

        return $required;
    }
}
