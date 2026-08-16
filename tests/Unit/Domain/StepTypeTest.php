<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final class StepTypeTest extends TestCase
{
    public function test_application_and_agent_step_types_are_declared(): void
    {
        self::assertSame('application', StepType::application()->toString());
        self::assertSame('agent', StepType::agent()->toString());
    }

    public function test_application_and_agent_step_types_round_trip_through_from_string(): void
    {
        self::assertSame('application', StepType::fromString('application')->toString());
        self::assertSame('agent', StepType::fromString('AGENT')->toString());
    }
}
