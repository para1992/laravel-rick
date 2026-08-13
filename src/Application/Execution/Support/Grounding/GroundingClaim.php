<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Grounding;

final readonly class GroundingClaim
{
    /** @param list<GroundingEvidence> $evidence */
    public function __construct(
        public string $unitId,
        public string $claim,
        public string $sourceQuote,
        public string $verdict,
        public array $evidence,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        $evidence = is_array($value['evidence'] ?? null)
            ? array_values(array_filter($value['evidence'], 'is_array'))
            : [];

        return new self(
            is_string($value['unit_id'] ?? null) ? $value['unit_id'] : '',
            is_string($value['claim'] ?? null) ? $value['claim'] : '',
            is_string($value['source_quote'] ?? null) ? $value['source_quote'] : '',
            is_string($value['verdict'] ?? null) ? $value['verdict'] : '',
            array_map(
                static fn (array $reference): GroundingEvidence => new GroundingEvidence(
                    is_string($reference['artifact_key'] ?? null)
                        ? $reference['artifact_key']
                        : '',
                    is_string($reference['quote'] ?? null) ? $reference['quote'] : '',
                ),
                $evidence,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unit_id' => $this->unitId,
            'claim' => $this->claim,
            'source_quote' => $this->sourceQuote,
            'verdict' => $this->verdict,
            'evidence' => array_map(
                static fn (GroundingEvidence $reference): array => $reference->toArray(),
                $this->evidence,
            ),
        ];
    }
}
