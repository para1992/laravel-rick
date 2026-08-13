<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\ValueObject;

use Rick\Laravel\Domain\ValueObject\Identifier;

final readonly class StepId
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        return new self(Identifier::normalize($value, 'Step ID'));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
