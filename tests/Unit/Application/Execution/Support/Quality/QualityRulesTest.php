<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Quality;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Exception\QualityGateFailedException;
use Rick\Laravel\Application\Execution\Support\Quality\Result\QualityReport;
use Rick\Laravel\Application\Execution\Support\Quality\Result\RuleResult;
use Rick\Laravel\Application\Execution\Support\Quality\Rules\MinimumCharactersRule;
use Rick\Laravel\Application\Execution\Support\Quality\Rules\RegexRule;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;

final class QualityRulesTest extends TestCase
{
    public function test_minimum_character_rule_reports_pass_and_failure(): void
    {
        $rule = new MinimumCharactersRule('minimum', 4);
        self::assertSame('minimum', $rule->id());

        $passed = $rule->evaluate($this->artifact(' good '), $this->snapshot());
        self::assertTrue($passed->passed);
        self::assertSame(['actual' => 4, 'minimum' => 4], $passed->metadata);
        self::assertStringContainsString('contains 4', $passed->message);

        $failed = $rule->evaluate($this->artifact('bad'), $this->snapshot());
        self::assertFalse($failed->passed);
        self::assertStringContainsString('at least 4', $failed->message);
    }

    public function test_minimum_character_rule_rejects_non_positive_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MinimumCharactersRule('minimum', 0);
    }

    public function test_regex_rule_supports_required_and_forbidden_patterns(): void
    {
        $required = new RegexRule('required', '/approved/i', description: 'approval marker');
        self::assertSame('required', $required->id());
        self::assertTrue($required->evaluate($this->artifact('APPROVED'), $this->snapshot())->passed);
        self::assertFalse($required->evaluate($this->artifact('pending'), $this->snapshot())->passed);

        $forbidden = new RegexRule('forbidden', '/secret/', false, 'secret marker');
        self::assertTrue($forbidden->evaluate($this->artifact('public'), $this->snapshot())->passed);
        self::assertFalse($forbidden->evaluate($this->artifact('secret'), $this->snapshot())->passed);
    }

    public function test_regex_rule_rejects_invalid_patterns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RegexRule('invalid', '/[/');
    }

    public function test_quality_report_exposes_violations_and_exception_contract(): void
    {
        $pass = new RuleResult('pass', true, 'Passed.', 'content', ['value' => 1]);
        $failure = new RuleResult('failure', false, 'Failed.', 'content');
        $passing = new QualityReport('rules', 'draft', 1, [$pass]);
        self::assertTrue($passing->passed());
        self::assertSame([], $passing->violations());

        $report = new QualityReport('rules', 'draft', 2, [$pass, $failure]);
        self::assertFalse($report->passed());
        self::assertSame([$failure], $report->violations());
        $results = $report->toArray()['results'];
        self::assertIsArray($results);
        self::assertSame($pass->toArray(), $results[0] ?? null);

        $exception = new QualityGateFailedException($report);
        self::assertSame('quality_gate_failed', $exception->errorCode());
        self::assertSame($report, $exception->report);
        self::assertStringContainsString('Failed.', $exception->getMessage());
    }

    private function artifact(string $content): Artifact
    {
        return new Artifact('draft', ArtifactType::fromString('draft'), $content, [], [], 1);
    }

    private function snapshot(): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('run-quality'),
            RunStatus::Running,
            1,
            new RunInput([]),
            'Task',
            DefinitionOfDone::automatic(),
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            0,
            1,
        );
    }
}
