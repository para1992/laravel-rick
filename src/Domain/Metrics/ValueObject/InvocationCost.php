<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Metrics\ValueObject;

use InvalidArgumentException;

final readonly class InvocationCost
{
    private const int NANODOLLARS_PER_DOLLAR = 1_000_000_000;

    public function __construct(
        public int $usdNanodollars,
    ) {
        if ($usdNanodollars < 0) {
            throw new InvalidArgumentException('Invocation cost cannot be negative.');
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromUsd(int|float|string $usd): self
    {
        $value = trim((string) $usd);

        if ($value === '' || ! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException('USD cost must be a non-negative number.');
        }

        if (str_contains(strtolower($value), 'e')) {
            $value = number_format((float) $value, 9, '.', '');
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('USD cost has an unsupported decimal format.');
        }

        $whole = (int) $matches[1];

        if ($whole > intdiv(PHP_INT_MAX, self::NANODOLLARS_PER_DOLLAR)) {
            throw new InvalidArgumentException('USD cost is too large.');
        }

        $fraction = substr(str_pad($matches[2] ?? '', 10, '0'), 0, 10);
        $nanodollars = (int) substr($fraction, 0, 9);

        if ((int) $fraction[9] >= 5) {
            $nanodollars++;
        }

        if ($nanodollars === self::NANODOLLARS_PER_DOLLAR) {
            $whole++;
            $nanodollars = 0;
        }

        return new self(($whole * self::NANODOLLARS_PER_DOLLAR) + $nanodollars);
    }

    public function plus(self $other): self
    {
        if ($other->usdNanodollars > PHP_INT_MAX - $this->usdNanodollars) {
            throw new InvalidArgumentException('Aggregated invocation cost is too large.');
        }

        return new self($this->usdNanodollars + $other->usdNanodollars);
    }

    public function multiplyTokens(int $tokens): self
    {
        if ($tokens < 0) {
            throw new InvalidArgumentException('Token count cannot be negative.');
        }

        if ($tokens === 0 || $this->usdNanodollars === 0) {
            return self::zero();
        }

        if ($this->usdNanodollars > intdiv(PHP_INT_MAX - 500_000, $tokens)) {
            throw new InvalidArgumentException('Token cost multiplication is too large.');
        }

        return new self(intdiv(($this->usdNanodollars * $tokens) + 500_000, 1_000_000));
    }

    public function times(int $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('Invocation cost multiplier cannot be negative.');
        }

        if ($multiplier === 0 || $this->usdNanodollars === 0) {
            return self::zero();
        }

        if ($this->usdNanodollars > intdiv(PHP_INT_MAX, $multiplier)) {
            throw new InvalidArgumentException('Aggregated invocation cost is too large.');
        }

        return new self($this->usdNanodollars * $multiplier);
    }

    public function toUsdDecimal(): string
    {
        $whole = intdiv($this->usdNanodollars, self::NANODOLLARS_PER_DOLLAR);
        $fraction = $this->usdNanodollars % self::NANODOLLARS_PER_DOLLAR;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return sprintf('%d.%s', $whole, rtrim(str_pad((string) $fraction, 9, '0', STR_PAD_LEFT), '0'));
    }
}
