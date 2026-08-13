<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\ValueObject;

use InvalidArgumentException;

final readonly class ArtifactType
{
    private function __construct(
        private string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Artifact type must not be empty.');
        }
    }

    public static function fromString(string $value): self
    {
        return new self(trim($value));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
