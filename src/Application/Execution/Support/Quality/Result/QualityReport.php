<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Result;

final readonly class QualityReport
{
    /** @param list<RuleResult> $results */
    public function __construct(
        public string $ruleSetId,
        public string $artifactKey,
        public int $artifactVersion,
        public array $results,
    ) {}

    public function passed(): bool
    {
        foreach ($this->results as $result) {
            if (! $result->passed) {
                return false;
            }
        }

        return true;
    }

    /** @return list<RuleResult> */
    public function violations(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (RuleResult $result): bool => ! $result->passed,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule_set_id' => $this->ruleSetId,
            'artifact_key' => $this->artifactKey,
            'artifact_version' => $this->artifactVersion,
            'passed' => $this->passed(),
            'results' => array_map(
                static fn (RuleResult $result): array => $result->toArray(),
                $this->results,
            ),
        ];
    }
}
