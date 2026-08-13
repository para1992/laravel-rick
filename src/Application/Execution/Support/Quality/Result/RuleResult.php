<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Result;

final readonly class RuleResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $ruleId,
        public bool $passed,
        public string $message,
        public ?string $path = null,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'passed' => $this->passed,
            'message' => $this->message,
            'path' => $this->path,
            'metadata' => $this->metadata,
        ];
    }
}
