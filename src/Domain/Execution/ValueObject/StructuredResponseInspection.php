<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

final readonly class StructuredResponseInspection
{
    /** @param array<string, mixed>|null $value */
    public function __construct(
        public ?array $value,
        public StructuredResponseDiagnostic $diagnostic,
    ) {}
}
