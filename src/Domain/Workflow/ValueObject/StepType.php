<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\ValueObject;

use InvalidArgumentException;

final readonly class StepType
{
    private function __construct(
        private string $value,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid step type [{$value}].");
        }
    }

    public static function fromString(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    public static function resolve(): self
    {
        return self::fromString('resolve');
    }

    public static function rawPrompt(): self
    {
        return self::fromString('raw_prompt');
    }

    public static function defineDod(): self
    {
        return self::fromString('define_dod');
    }

    public static function context(): self
    {
        return self::fromString('context');
    }

    public static function generate(): self
    {
        return self::fromString('generate');
    }

    public static function unfold(): self
    {
        return self::fromString('unfold');
    }

    public static function judge(): self
    {
        return self::fromString('judge');
    }

    public static function edit(): self
    {
        return self::fromString('edit');
    }

    public static function outputGlue(): self
    {
        return self::fromString('output_glue');
    }

    public static function operation(): self
    {
        return self::fromString('operation');
    }

    public static function qualityGate(): self
    {
        return self::fromString('quality_gate');
    }

    public static function groundedVerify(): self
    {
        return self::fromString('grounded_verify');
    }

    public static function parallel(): self
    {
        return self::fromString('parallel');
    }

    public static function map(): self
    {
        return self::fromString('map');
    }

    public static function join(): self
    {
        return self::fromString('join');
    }

    public static function branch(): self
    {
        return self::fromString('branch');
    }

    public static function waitForInput(): self
    {
        return self::fromString('wait_for_input');
    }

    public function toString(): string
    {
        return $this->value;
    }
}
