<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Run\RunProgress;
use Rick\Laravel\Domain\Run\RunStatus;

final class RunProgressTest extends TestCase
{
    public function test_run_progress_exposes_a_safe_json_representation(): void
    {
        $progress = new RunProgress(
            RunStatus::Running,
            'risk-analysis',
            'Assessing risk',
            3,
            7,
        );

        self::assertSame([
            'status' => 'running',
            'step_id' => 'risk-analysis',
            'label' => 'Assessing risk',
            'current' => 3,
            'total' => 7,
        ], $progress->toArray());
    }

    public function test_run_progress_never_exposes_prompt_or_provider_bodies(): void
    {
        $progress = new RunProgress(RunStatus::Running, 'risk', null, 1, 3);

        $json = json_encode($progress, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('prompt', $json);
        self::assertStringNotContainsString('provider', $json);
        self::assertStringNotContainsString('body', $json);
        self::assertSame(
            ['status', 'step_id', 'label', 'current', 'total'],
            array_keys($progress->toArray()),
        );
    }

    public function test_run_progress_carries_a_null_step_and_label_for_terminal_runs(): void
    {
        $progress = new RunProgress(RunStatus::Completed, null, null, 5, 5);

        self::assertNull($progress->stepId);
        self::assertNull($progress->label);
        self::assertSame('completed', $progress->toArray()['status']);
    }
}
