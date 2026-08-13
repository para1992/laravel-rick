<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Rules;

use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Support\Quality\Interface\ArtifactRuleBase;
use Rick\Laravel\Application\Execution\Support\Quality\Result\RuleResult;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

final readonly class MinimumCharactersRule implements ArtifactRuleBase
{
    public function __construct(private string $ruleId, private int $minimum)
    {
        if ($minimum < 1) {
            throw new InvalidArgumentException('Minimum characters must be positive.');
        }
    }

    public function id(): string
    {
        return $this->ruleId;
    }

    public function evaluate(Artifact $artifact, WorkflowRunSnapshot $run): RuleResult
    {
        $actual = mb_strlen(trim($artifact->content));
        $passed = $actual >= $this->minimum;

        return new RuleResult(
            $this->ruleId,
            $passed,
            $passed
                ? "Artifact contains {$actual} characters."
                : "Artifact contains {$actual} characters; at least {$this->minimum} are required.",
            'content',
            ['actual' => $actual, 'minimum' => $this->minimum],
        );
    }
}
