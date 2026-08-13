<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Grounding;

final readonly class GroundingReport
{
    /**
     * @param  list<GroundingClaim>  $claims
     * @param  list<string>  $violations
     * @param  list<string>  $protocolViolations
     * @param  list<string>  $contentViolations
     * @param  list<string>  $expectedUnitIds
     * @param  list<string>  $coveredUnitIds
     * @param  list<string>  $missingUnitIds
     */
    public function __construct(
        public string $artifactKey,
        public bool $passed,
        public bool $modelPassed,
        public array $claims,
        public array $violations,
        public array $protocolViolations,
        public array $contentViolations,
        public array $expectedUnitIds,
        public array $coveredUnitIds,
        public array $missingUnitIds,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'artifact_key' => $this->artifactKey,
            'passed' => $this->passed,
            'model_passed' => $this->modelPassed,
            'claims' => array_map(
                static fn (GroundingClaim $claim): array => $claim->toArray(),
                $this->claims,
            ),
            'violations' => $this->violations,
            'protocol_violations' => $this->protocolViolations,
            'content_violations' => $this->contentViolations,
            'expected_unit_ids' => $this->expectedUnitIds,
            'covered_unit_ids' => $this->coveredUnitIds,
            'missing_unit_ids' => $this->missingUnitIds,
        ];
    }
}
