<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Tests\TestCase;
use Rick\Laravel\Workflow;

final class MakeRickWorkflowCommandTest extends TestCase
{
    private ?string $generatedPath = null;

    public function test_command_generates_a_valid_workflow_class(): void
    {
        $this->artisanCommand('make:rick-workflow', ['name' => 'ContractReview'])
            ->assertSuccessful();

        $path = app_path('Workflows/ContractReview.php');
        $this->generatedPath = $path;

        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        self::assertStringContainsString('final class ContractReview extends Workflow', $contents);
        self::assertStringContainsString('Rick\Laravel\WorkflowBuilder', $contents);
        self::assertStringNotContainsString('Rick\Laravel\Application', $contents);
        self::assertStringNotContainsString('Rick\Laravel\Domain', $contents);
        self::assertStringNotContainsString('Rick\Laravel\Infrastructure', $contents);

        require $path;

        $workflow = new ('App\\Workflows\\'.basename($path, '.php'));

        self::assertInstanceOf(Workflow::class, $workflow);
        self::assertSame('contract-review', $workflow->name());
    }

    protected function tearDown(): void
    {
        if ($this->generatedPath !== null) {
            @unlink($this->generatedPath);
            @rmdir(dirname($this->generatedPath));
        }

        parent::tearDown();
    }
}
