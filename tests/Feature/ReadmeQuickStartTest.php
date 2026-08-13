<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Infrastructure\Queue\Job\ContinueRunJob;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\TestCase;

final class ReadmeQuickStartTest extends TestCase
{
    public function test_readme_documents_the_provider_setup(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2).'/README.md');
        self::assertIsString($readme);

        foreach ([
            'OPENROUTER_API_KEY=your-key-here',
            'RICK_LLM_PROVIDER=openrouter',
            'RICK_LLM_MODEL=google/gemini-3.5-flash-lite',
            'php artisan vendor:publish --tag=rick-config',
            "'provider' => env('RICK_LLM_PROVIDER', 'openrouter')",
            "'model' => env('RICK_LLM_MODEL')",
            'php artisan config:clear',
        ] as $instruction) {
            self::assertStringContainsString($instruction, $readme);
        }
    }

    public function test_readme_quick_start_executes_one_raw_prompt(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2).'/README.md');
        self::assertIsString($readme);
        self::assertStringContainsString("->workflow('summary')", $readme);
        self::assertStringContainsString("->rawPrompt('Summarize the latest customer feedback.')", $readme);
        self::assertStringContainsString('echo $run->output();', $readme);
        self::assertStringNotContainsString('->judge()', $readme);

        $gateway = (new FakeGateway)->respond(new CompletionResponse(text: 'Short summary'));
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('summary')
            ->rawPrompt('Summarize the latest customer feedback.')
            ->build();
        $run = $rick->run($workflow);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('Short summary', $run->output());
        self::assertSame(1, $run->callsUsed);
        $gateway->assertRequested(times: 1);
    }

    public function test_compiled_workflow_runs_inline_and_schedules_without_recompilation(): void
    {
        Queue::fake();
        $rick = $this->application()->make(Rick::class);
        $definition = $rick->workflow('compiled')
            ->resolve('Use the compiled plan', 'Both execution modes accept it')
            ->build();
        $compiled = $rick->compile($definition);

        $inline = $rick->run($compiled);
        $scheduled = $rick->schedule($compiled);

        self::assertSame(RunStatus::Completed, $inline->status);
        self::assertSame(RunStatus::Created, $scheduled->status);
        Queue::assertPushed(
            ContinueRunJob::class,
            static fn (ContinueRunJob $job): bool => $job->runId === $scheduled->id->toString(),
        );
    }
}
