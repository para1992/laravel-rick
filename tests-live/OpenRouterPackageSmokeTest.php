<?php

declare(strict_types=1);

namespace Rick\Laravel\LiveTests;

use Illuminate\Database\ConnectionInterface;
use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeRegistry;
use Rick\Laravel\Domain\Event\StepFailed;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\RunMetrics;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Infrastructure\Configuration\ConfigurationInput;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\Support\AllLinksWorkflow;
use Rick\Laravel\Tests\TestCase;
use RuntimeException;

final class OpenRouterPackageSmokeTest extends TestCase
{
    private const string DEFAULT_MODEL = 'google/gemini-2.5-flash-lite';

    private const string DEFAULT_MAX_COST_PER_RUN = '0.001';

    private const float MAX_ALLOWED_COST_PER_RUN = 0.005;

    private const string DEFAULT_MAX_ALL_LINKS_COST = '0.003';

    private const float MAX_ALLOWED_ALL_LINKS_COST = 0.005;

    private const string DEFAULT_MAX_MULTICANDIDATE_COST = '0.004';

    private const float MAX_ALLOWED_MULTICANDIDATE_COST = 0.005;

    private const string DEFAULT_MAX_HUMANIZER_COST = '0.020';

    private const float MAX_ALLOWED_HUMANIZER_COST = 0.030;

    private const int MAX_PUBLIC_RESUME_ATTEMPTS = 128;

    /** @var list<array<string, mixed>> */
    private static array $reports = [];

    /** @var array<string, array{step_id: string, error_code: string, message: string}> */
    private array $stepFailures = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $model = self::model();
        $apiKey = (string) getenv('OPENROUTER_API_KEY');
        $providerCeiling = [
            'prompt' => 0.10,
            'completion' => 0.40,
        ];

        $app['config']->set('ai.providers.openrouter', [
            'driver' => 'openrouter',
            'key' => $apiKey,
        ]);
        foreach (['cheap', 'medium', 'quality'] as $tier) {
            $app['config']->set("rick.llm.models.{$tier}", [
                'provider' => 'openrouter',
                'model' => $model,
            ]);
        }
        foreach (['default', 'cheap', 'quality', 'cheap_then_quality'] as $policy) {
            $app['config']->set("rick.llm.policies.{$policy}.options", [
                'max_tokens' => $policy === 'cheap' ? 1280 : 640,
                'temperature' => 0,
                'top_p' => 0.8,
                'provider' => [
                    'sort' => 'price',
                    'allow_fallbacks' => true,
                    'require_parameters' => true,
                    'max_price' => $providerCeiling,
                ],
            ]);
        }
        $pricing = ConfigurationInput::map(
            $app['config']->get('rick.llm.pricing.models', []),
            'rick.llm.pricing.models',
        );
        $pricing["openrouter:{$model}"] = [
            'input_per_million' => '0.10',
            'cached_input_per_million' => '0.01',
            'output_per_million' => '0.40',
        ];
        $app['config']->set('rick.llm.pricing.models', $pricing);
        $app['events']->listen(StepFailed::class, function (StepFailed $event): void {
            $this->stepFailures[$event->runId->toString()] = [
                'step_id' => $event->stepId->toString(),
                'error_code' => $event->errorCode,
                'message' => $event->message,
            ];
        });
    }

    public function test_real_raw_prompt_reaches_openrouter_and_persists_measured_usage(): void
    {
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('live-raw-prompt')
            ->budget(
                maxTotalTokens: 2500,
                maxCostUsd: self::maxCostPerRun(),
                defaultOutputReservationTokens: 320,
                requireCompleteMetrics: true,
            )
            ->rawPrompt(
                'Return the literal marker RICK_LIVE_OK and no other words.',
            )
            ->build();

        $run = $rick->run($workflow, callLimit: 1);
        $metrics = $rick->metrics($run->id);

        self::assertSame(
            RunStatus::Completed,
            $run->status,
            $this->diagnostic($rick, $run),
        );
        self::assertStringContainsString('RICK_LIVE_OK', strtoupper($run->output()));
        $this->assertMeasuredOpenRouterCalls($metrics, 1, self::maxCostPerRun());
        self::record('raw_prompt', $run, $metrics, self::maxCostPerRun());
    }

    public function test_real_structured_generation_can_pause_select_and_complete(): void
    {
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('live-structured-review')
            ->budget(
                maxTotalTokens: 2500,
                maxCostUsd: self::maxCostPerRun(),
                defaultOutputReservationTokens: 320,
                requireCompleteMetrics: true,
            )
            ->resolve(
                'Create one short candidate containing the literal marker RICK_STRUCTURED_OK.',
                'The candidate content contains RICK_STRUCTURED_OK.',
            )
            ->generate('draft', candidates: 1, outputKey: 'draft')
            ->manualJudge()
            ->outputGlue('draft')
            ->build();

        $waiting = $rick->run($workflow, callLimit: 1);
        $review = $rick->pendingReview($waiting->id);

        self::assertSame(
            RunStatus::AwaitingInput,
            $waiting->status,
            $this->diagnostic($rick, $waiting),
        );
        self::assertCount(1, $review->candidates);
        self::assertStringContainsString(
            'RICK_STRUCTURED_OK',
            strtoupper($review->candidates[0]->content),
        );

        $rick->selectCandidate($waiting->id, $review->candidates[0]->id);
        $completed = $this->terminalSnapshot($rick, $waiting);
        $metrics = $rick->metrics($waiting->id);

        self::assertSame(
            RunStatus::Completed,
            $completed->status,
            $this->diagnostic($rick, $completed),
        );
        self::assertStringContainsString('RICK_STRUCTURED_OK', strtoupper($completed->output()));
        $this->assertMeasuredOpenRouterCalls($metrics, 1, self::maxCostPerRun());
        self::record('structured_review', $completed, $metrics, self::maxCostPerRun());
    }

    public function test_real_all_built_in_links_execute_in_one_maximal_flow(): void
    {
        $rick = $this->application()->make(Rick::class);
        $workflow = AllLinksWorkflow::build($rick, self::maxAllLinksCost());
        $coveredTypes = AllLinksWorkflow::coveredTypes($rick, $workflow);
        $configuredTypes = array_keys(ConfigurationInput::map(
            config('rick.execution.strategies'),
            'rick.execution.strategies',
        ));
        sort($configuredTypes);

        self::assertSame(
            $configuredTypes,
            $coveredTypes,
            'Paid smoke coverage must change whenever a built-in strategy is added or removed.',
        );

        $waitingForInput = $rick->run($workflow, [
            'source' => 'RICK_ALL_LINKS_OK confirms every registered workflow link is exercised',
            'evidence' => 'RICK_ALL_LINKS_OK confirms every registered workflow link is exercised',
            'collection' => ['items' => ['RICK_MAP_OK']],
            'condition' => 'yes',
        ], callLimit: 10);

        self::assertSame(
            RunStatus::AwaitingInput,
            $waitingForInput->status,
            $this->diagnostic($rick, $waitingForInput),
        );
        self::assertSame(1, $waitingForInput->callsUsed);
        self::assertSame('approval', $rick->pendingInput($waitingForInput->id)->key);

        $afterInput = $rick->submitInput(
            $waitingForInput->id,
            'approval',
            ['approved' => true],
        );
        $waitingForReview = $this->interactionSnapshot($rick, $afterInput);
        $review = $rick->pendingReview($waitingForInput->id);

        self::assertSame(RunStatus::AwaitingInput, $waitingForReview->status);
        self::assertCount(1, $review->candidates);

        $rick->selectCandidate($waitingForInput->id, $review->candidates[0]->id);
        $completed = $this->terminalSnapshot($rick, $waitingForInput);
        $metrics = $rick->metrics($waitingForInput->id);

        self::assertSame(
            RunStatus::Completed,
            $completed->status,
            $this->diagnostic($rick, $completed),
        );
        self::assertNotSame('', trim($completed->output()));
        self::assertSame('true', $completed->artifact('selected')->metadata['branch']);
        self::assertTrue($completed->artifact('checked.quality')->payload['passed']);
        self::assertTrue($completed->artifact('verified.verification')->payload['passed']);
        self::assertSame(['approved' => true], $completed->artifact('approval')->payload);
        self::assertNotSame('', trim($completed->artifact('unit')->content));
        $unfoldState = array_values(array_filter(
            $completed->stepStates,
            static fn (array $state): bool => isset($state['phase'], $state['memory']),
        ))[0] ?? [];
        self::assertSame('complete', $unfoldState['phase'] ?? null);
        $this->assertMeasuredOpenRouterCalls($metrics, 10, self::maxAllLinksCost());
        self::record('all_built_in_links', $completed, $metrics, self::maxAllLinksCost());
    }

    public function test_real_multicandidate_unfold_keeps_three_units_distinct(): void
    {
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('live-multicandidate-unfold-regression')
            ->budget(
                maxTotalTokens: 15000,
                maxCostUsd: self::maxMulticandidateCost(),
                defaultOutputReservationTokens: 1280,
                requireCompleteMetrics: true,
            )
            ->resolve(
                'First create only a terse three-unit outline for a compact detective story, '
                .'then expand it into prose. Every outline unit is at most twelve words and '
                .'contains no ready prose. Unit one advances only LEDGER_BLUE, unit two only '
                .'CLOCK_0317, and unit three only VANISHING_FERRY. Every expanded scene adds '
                .'new events and must not retell, summarize, or repeat an earlier scene.',
                'The selected plan has exactly three ordered outline units of at most twelve '
                .'words each. The assembled result has '
                .'three distinct prose scenes of 80-140 words. Each required literal marker '
                .'appears in its own scene exactly once, with no repeated paragraph.',
            )
            ->generate('plan', candidates: 3, outputKey: 'plan')
            ->manualJudge()
            ->unfold('plan', 'scene', candidates: 2, maxUnits: 3)
            ->outputGlue('scene')
            ->build();

        $waiting = $rick->run($workflow, callLimit: 10);
        $review = $rick->pendingReview($waiting->id);

        self::assertSame(
            RunStatus::AwaitingInput,
            $waiting->status,
            $this->diagnostic($rick, $waiting),
        );
        self::assertSame(3, $waiting->callsUsed);
        self::assertCount(3, $review->candidates);

        $plans = array_values(array_filter(
            $review->candidates,
            static function ($candidate): bool {
                $content = strtoupper($candidate->content);

                return str_contains($content, 'LEDGER_BLUE')
                    && str_contains($content, 'CLOCK_0317')
                    && str_contains($content, 'VANISHING_FERRY');
            },
        ));
        self::assertNotEmpty($plans, 'No plan candidate preserved all required markers.');
        usort(
            $plans,
            static fn ($left, $right): int => mb_strlen($left->content) <=> mb_strlen($right->content),
        );
        $rick->selectCandidate($waiting->id, $plans[0]->id);
        $completed = $this->terminalSnapshot($rick, $waiting);
        $metrics = $rick->metrics($waiting->id);

        self::assertSame(
            RunStatus::Completed,
            $completed->status,
            $this->diagnostic($rick, $completed),
        );
        self::assertSame(10, $completed->callsUsed);
        $scenes = array_values(array_filter(
            $completed->acceptedCandidates,
            static fn ($candidate): bool => $candidate->artifact->toString() === 'scene',
        ));
        self::assertCount(3, $scenes);
        self::assertCount(3, array_unique(array_map(
            static fn ($candidate): string => mb_strtolower(trim($candidate->content)),
            $scenes,
        )));
        foreach (['LEDGER_BLUE', 'CLOCK_0317', 'VANISHING_FERRY'] as $marker) {
            self::assertSame(
                1,
                substr_count(strtoupper($completed->output()), $marker),
                "Marker [{$marker}] must occur exactly once in the assembled output.",
            );
        }
        $this->assertNoRepeatedParagraphs($completed->output());
        $this->assertMeasuredOpenRouterCalls(
            $metrics,
            10,
            self::maxMulticandidateCost(),
            minimumSucceeded: 7,
        );
        self::record(
            'multicandidate_unfold_regression',
            $completed,
            $metrics,
            self::maxMulticandidateCost(),
        );
    }

    public function test_real_humanizer_recipe_rewrites_and_grounds_an_ai_styled_text(): void
    {
        $source = <<<'TEXT'
Established in 2024, the Northstar Lab stands as a vibrant testament to our
enduring commitment to innovation. Located in Warsaw, the lab serves as a
pivotal hub, fostering collaboration, showcasing groundbreaking ideas, and
shaping the evolving technology landscape. It has 17 researchers and uses the
internal codename ORBIT-17. Despite the challenges of a rapidly changing
world, the future looks bright. It is not just a lab; it is a movement.
TEXT;
        $rick = $this->application()->make(Rick::class);
        $workflow = $this->application()->make(RecipeRegistry::class)->build('rick.humanizer');

        $run = $rick->run($workflow, ['source' => $source], callLimit: 6);
        $metrics = $rick->metrics($run->id);

        self::assertSame(RunStatus::Completed, $run->status, $this->diagnostic($rick, $run));
        self::assertGreaterThanOrEqual(4, $run->callsUsed);
        self::assertLessThanOrEqual(6, $run->callsUsed);
        self::assertNotSame(trim($source), trim($run->output()));
        foreach (['2024', 'Warsaw', '17', 'ORBIT-17'] as $fact) {
            self::assertStringContainsString($fact, $run->output());
        }
        self::assertTrue($run->artifact('humanizer.verified.verification')->payload['passed']);
        self::assertTrue($run->artifact('humanizer.output.quality')->payload['passed']);
        $this->assertMeasuredOpenRouterCalls(
            $metrics,
            $run->callsUsed,
            self::maxHumanizerCost(),
        );
        self::record('humanizer', $run, $metrics, self::maxHumanizerCost());
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$reports === []) {
            return;
        }

        $directory = dirname(__DIR__).'/build/live-smoke';
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create live smoke report directory [{$directory}].");
        }
        $report = json_encode([
            'schema_version' => 2,
            'generated_at' => gmdate(DATE_ATOM),
            'provider' => 'openrouter',
            'model' => self::model(),
            'maximum_suite_cost_usd' => number_format(
                array_sum(array_map(
                    static fn (array $run): float => InvocationCost::fromUsd(
                        JsonInput::string(
                            $run['maximum_cost_usd'] ?? null,
                            'live_report.maximum_cost_usd',
                        ),
                    )->usdNanodollars / 1_000_000_000,
                    self::$reports,
                )),
                6,
                '.',
                '',
            ),
            'runs' => self::$reports,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents($directory.'/latest.json', $report."\n");
        file_put_contents(
            $directory.'/'.gmdate('Ymd-His').'.json',
            $report."\n",
        );
    }

    private function assertMeasuredOpenRouterCalls(
        RunMetrics $metrics,
        int $expectedCalls,
        string $maximumCost,
        ?int $minimumSucceeded = null,
    ): void {
        self::assertSame($expectedCalls, $metrics->totals->calls);
        if ($minimumSucceeded === null) {
            self::assertSame($expectedCalls, $metrics->totals->succeededCalls);
        } else {
            self::assertGreaterThanOrEqual($minimumSucceeded, $metrics->totals->succeededCalls);
        }
        self::assertSame(
            $metrics->totals->succeededCalls,
            $metrics->totals->measuredSucceededCalls,
        );
        self::assertSame(
            $expectedCalls - $metrics->totals->succeededCalls,
            $metrics->totals->failedCalls,
        );
        self::assertSame(0, $metrics->totals->indeterminateCalls);
        self::assertSame(0, $metrics->totals->pendingCalls);
        self::assertSame(0, $metrics->totals->runningCalls);
        self::assertSame(0, $metrics->totals->unpricedSucceededCalls);
        self::assertGreaterThan(0, $metrics->totals->tokens->totalTokens);
        self::assertGreaterThan(0, $metrics->totals->latencyMilliseconds);
        self::assertLessThanOrEqual(
            InvocationCost::fromUsd($maximumCost)->usdNanodollars,
            $metrics->totals->cost->usdNanodollars,
        );

        foreach ($metrics->invocations as $invocation) {
            self::assertSame('openrouter', $invocation->provider);
            self::assertStringContainsString(
                'gemini-2.5-flash-lite',
                $invocation->model ?? throw new RuntimeException('Live invocation model is missing.'),
            );
            self::assertTrue($invocation->usageComplete);
        }
    }

    private function terminalSnapshot(Rick $rick, WorkflowRunSnapshot $run): WorkflowRunSnapshot
    {
        for ($attempt = 0; $attempt < self::MAX_PUBLIC_RESUME_ATTEMPTS; $attempt++) {
            $snapshot = $rick->snapshot($run->id);
            if ($snapshot->status->isTerminal()) {
                return $snapshot;
            }
            $rick->resume($run->id);
        }

        return $rick->snapshot($run->id);
    }

    private function interactionSnapshot(Rick $rick, WorkflowRunSnapshot $run): WorkflowRunSnapshot
    {
        for ($attempt = 0; $attempt < self::MAX_PUBLIC_RESUME_ATTEMPTS; $attempt++) {
            $snapshot = $rick->snapshot($run->id);
            if ($snapshot->status === RunStatus::AwaitingInput || $snapshot->status->isTerminal()) {
                return $snapshot;
            }
            $rick->resume($run->id);
        }

        return $rick->snapshot($run->id);
    }

    private function diagnostic(Rick $rick, WorkflowRunSnapshot $run): string
    {
        $database = $this->application()->make(ConnectionInterface::class);

        return json_encode([
            'status' => $run->status->value,
            'calls_used' => $run->callsUsed,
            'step_failure' => $this->stepFailures[$run->id->toString()] ?? null,
            'metrics' => $rick->metrics($run->id),
            'step_executions' => $database
                ->table('rick_step_executions')
                ->where('run_id', $run->id->toString())
                ->get(['step_id', 'status', 'error_code', 'error_message'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'invocations' => $database
                ->table('rick_llm_invocations')
                ->where('run_id', $run->id->toString())
                ->get(['step_id', 'status', 'error_code', 'error_message'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function record(
        string $scenario,
        WorkflowRunSnapshot $run,
        RunMetrics $metrics,
        string $maximumCost,
    ): void {
        self::$reports[] = [
            'scenario' => $scenario,
            'run_id' => $run->id->toString(),
            'status' => $run->status->value,
            'output' => $run->output(),
            'maximum_cost_usd' => $maximumCost,
            'metrics' => $metrics->toArray(),
        ];
    }

    private function assertNoRepeatedParagraphs(string $output): void
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($output));
        self::assertIsArray($paragraphs);
        $normalized = array_values(array_filter(array_map(
            static function (string $paragraph): string {
                $value = mb_strtolower(trim($paragraph));
                $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

                return trim(is_string($value) ? $value : '');
            },
            $paragraphs,
        ), static fn (string $paragraph): bool => mb_strlen($paragraph) >= 80));

        self::assertCount(
            count($normalized),
            array_unique($normalized),
            'The assembled output contains a repeated long paragraph.',
        );
    }

    private static function model(): string
    {
        return self::DEFAULT_MODEL;
    }

    private static function maxCostPerRun(): string
    {
        $cost = getenv('RICK_LIVE_MAX_COST_PER_RUN_USD');

        return is_string($cost)
            && preg_match('/^0\\.\\d{1,6}$/', $cost) === 1
            && (float) $cost > 0
            && (float) $cost <= self::MAX_ALLOWED_COST_PER_RUN
            ? $cost
            : self::DEFAULT_MAX_COST_PER_RUN;
    }

    private static function maxAllLinksCost(): string
    {
        $cost = getenv('RICK_LIVE_MAX_ALL_LINKS_COST_USD');

        return is_string($cost)
            && preg_match('/^0\\.\\d{1,6}$/', $cost) === 1
            && (float) $cost > 0
            && (float) $cost <= self::MAX_ALLOWED_ALL_LINKS_COST
            ? $cost
            : self::DEFAULT_MAX_ALL_LINKS_COST;
    }

    private static function maxMulticandidateCost(): string
    {
        $cost = getenv('RICK_LIVE_MAX_MULTICANDIDATE_COST_USD');

        return is_string($cost)
            && preg_match('/^0\\.\\d{1,6}$/', $cost) === 1
            && (float) $cost > 0
            && (float) $cost <= self::MAX_ALLOWED_MULTICANDIDATE_COST
            ? $cost
            : self::DEFAULT_MAX_MULTICANDIDATE_COST;
    }

    private static function maxHumanizerCost(): string
    {
        $cost = getenv('RICK_LIVE_MAX_HUMANIZER_COST_USD');

        return is_string($cost)
            && preg_match('/^0\\.\\d{1,6}$/', $cost) === 1
            && (float) $cost > 0
            && (float) $cost <= self::MAX_ALLOWED_HUMANIZER_COST
            ? $cost
            : self::DEFAULT_MAX_HUMANIZER_COST;
    }
}
