<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

use Rick\Laravel\Domain\ValueObject\Identifier;

final readonly class StepExecutionId
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        return new self(Identifier::normalize($value, 'Step execution ID'));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
