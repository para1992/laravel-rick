<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality;

use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Support\Quality\Interface\ArtifactRuleBase;
use Rick\Laravel\Application\Execution\Support\Quality\Result\QualityReport;
use Rick\Laravel\Application\Execution\Support\Quality\Result\RuleResult;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

final readonly class RuleSet
{
    /** @param list<ArtifactRuleBase> $rules */
    public function __construct(public string $id, public array $rules)
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1 || $rules === []) {
            throw new InvalidArgumentException('A rule set requires a valid id and at least one rule.');
        }
    }

    public function evaluate(Artifact $artifact, WorkflowRunSnapshot $run): QualityReport
    {
        return new QualityReport(
            $this->id,
            $artifact->key,
            $artifact->version,
            array_map(
                static fn (ArtifactRuleBase $rule): RuleResult => $rule->evaluate($artifact, $run),
                $this->rules,
            ),
        );
    }
}
