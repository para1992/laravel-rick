<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Rules;

use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Support\Quality\Interface\ArtifactRuleBase;
use Rick\Laravel\Application\Execution\Support\Quality\Result\RuleResult;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

final readonly class RegexRule implements ArtifactRuleBase
{
    public function __construct(
        private string $ruleId,
        private string $pattern,
        private bool $mustMatch = true,
        private string $description = 'regex constraint',
    ) {
        set_error_handler(static fn (): bool => true);
        try {
            $valid = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (! $valid) {
            throw new InvalidArgumentException("Invalid quality regex [{$pattern}].");
        }
    }

    public function id(): string
    {
        return $this->ruleId;
    }

    public function evaluate(Artifact $artifact, WorkflowRunSnapshot $run): RuleResult
    {
        $matches = preg_match($this->pattern, $artifact->content) === 1;
        $passed = $this->mustMatch ? $matches : ! $matches;

        return new RuleResult(
            $this->ruleId,
            $passed,
            $passed
                ? "Artifact satisfies {$this->description}."
                : "Artifact violates {$this->description}.",
            'content',
        );
    }
}
