<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Workflow\Step\AgentStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class AgentStepTest extends TestCase
{
    public function test_agent_step_declares_stable_identity_and_label(): void
    {
        $step = new AgentStep(
            StepId::fromString('extract-facts'),
            'App\\Ai\\Agents\\ExtractClaimFacts',
            agentVersion: 3,
            label: 'Extracting claim facts',
        );

        self::assertSame('extract-facts', $step->id()->toString());
        self::assertSame('agent', $step->type()->toString());
        self::assertSame('App\\Ai\\Agents\\ExtractClaimFacts', $step->agentClass);
        self::assertSame(3, $step->agentVersion);
        self::assertSame('Extracting claim facts', $step->label());
    }

    public function test_agent_step_writes_its_alias_and_reads_are_declared(): void
    {
        $step = new AgentStep(
            StepId::fromString('risk'),
            'App\\Ai\\Agents\\RiskAgent',
            reads: ['facts'],
        );

        self::assertSame(['facts'], $step->artifactReads());
        self::assertSame(['risk'], $step->artifactWrites());
    }

    public function test_agent_step_defaults_are_safe(): void
    {
        $step = new AgentStep(
            StepId::fromString('facts'),
            'App\\Ai\\Agents\\ExtractClaimFacts',
        );

        self::assertSame(1, $step->agentVersion);
        self::assertNull($step->label());
        self::assertSame('medium', $step->modelPolicy);
        self::assertNull($step->prompt);
        self::assertSame([], $step->artifactReads());
    }

    public function test_agent_step_rejects_invalid_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AgentStep(StepId::fromString('facts'), '');
    }
}
