<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Grounding;

final readonly class GroundingEvidence
{
    public function __construct(
        public string $artifactKey,
        public string $quote,
    ) {}

    /** @return array{artifact_key: string, quote: string} */
    public function toArray(): array
    {
        return [
            'artifact_key' => $this->artifactKey,
            'quote' => $this->quote,
        ];
    }
}
