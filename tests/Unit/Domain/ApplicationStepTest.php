<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Workflow\Step\ApplicationStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class ApplicationStepTest extends TestCase
{
    public function test_application_step_declares_stable_identity_and_label(): void
    {
        $step = new ApplicationStep(
            StepId::fromString('load-claim'),
            'App\\WorkflowSteps\\LoadClaim',
            handlerVersion: 2,
            label: 'Loading claim',
        );

        self::assertSame('load-claim', $step->id()->toString());
        self::assertSame('application', $step->type()->toString());
        self::assertSame('App\\WorkflowSteps\\LoadClaim', $step->handlerClass);
        self::assertSame(2, $step->handlerVersion);
        self::assertSame('Loading claim', $step->label());
    }

    public function test_application_step_reads_are_static_and_writes_are_runtime(): void
    {
        $step = new ApplicationStep(
            StepId::fromString('store-claim'),
            'App\\WorkflowSteps\\StoreClaim',
            reads: ['decision'],
        );

        self::assertSame(['decision'], $step->artifactReads());
        self::assertSame([], $step->artifactWrites());
    }

    public function test_application_step_defaults_are_safe(): void
    {
        $step = new ApplicationStep(
            StepId::fromString('load-claim'),
            'App\\WorkflowSteps\\LoadClaim',
        );

        self::assertSame(1, $step->handlerVersion);
        self::assertNull($step->label());
        self::assertSame([], $step->artifactReads());
    }

    public function test_application_step_rejects_invalid_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApplicationStep(StepId::fromString('load-claim'), '');
    }
}
