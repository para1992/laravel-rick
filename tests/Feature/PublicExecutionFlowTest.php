<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Request\ContinueRunRequest;
use Rick\Laravel\Application\Execution\Request\ExecuteInvocationRequest;
use Rick\Laravel\Application\Execution\Result\ContinueRunResult;
use Rick\Laravel\Application\Execution\Result\ContinueRunStatus;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Execution\Support\Quality\Rules\MinimumCharactersRule;
use Rick\Laravel\Application\Execution\Support\Quality\RuleSet;
use Rick\Laravel\Application\Execution\Support\Quality\RuleSetRegistry;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Infrastructure\Queue\Job\ContinueRunJob;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\Support\AllLinksWorkflow;
use Rick\Laravel\Tests\TestCase;
use RuntimeException;

final class PublicExecutionFlowTest extends TestCase
{
    public function test_raw_prompt_performs_one_unwrapped_call_and_exposes_budget_metrics(): void
    {
        $gateway = new class implements GatewayBase
        {
            public ?CompletionRequest $request = null;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->request = $request;

                return new CompletionResponse(
                    text: 'Measured answer',
                    provider: 'fake',
                    model: 'fake-raw',
                    metrics: new CompletionMetrics(
                        new TokenUsage(23, 7),
                        InvocationCost::fromUsd('0.00042'),
                        18,
                    ),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $prompt = '  Answer this prompt exactly as supplied.  ';
        $workflow = $rick->workflow('raw-measurement')
            ->budget(maxCostUsd: '0.10', requireKnownPricing: false)
            ->rawPrompt($prompt)
            ->build();

        $run = $rick->run($workflow);
        $metrics = $rick->metrics($run->id);
        $request = $gateway->request ?? throw new RuntimeException('The gateway request was not captured.');

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('Measured answer', $run->output());
        self::assertSame(1, $run->callsUsed);
        self::assertCount(1, $request->messages);
        self::assertSame('user', $request->messages[0]->role);
        self::assertSame($prompt, $request->messages[0]->content);
        self::assertSame('raw_prompt', $request->purpose);
        self::assertSame('medium', $request->modelTier);
        self::assertTrue(JsonInput::boolean($request->metadata['raw_prompt'] ?? null, 'request.metadata.raw_prompt'));
        self::assertSame(1, $metrics->totals->calls);
        self::assertSame(30, $metrics->totals->tokens->totalTokens);
        self::assertSame('0.00042', $metrics->totals->cost->toUsdDecimal());
    }

    public function test_public_run_reaches_terminal_state_and_exposes_snapshot_and_metrics(): void
    {
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('synchronous')
            ->resolve('Return a result', 'The workflow reaches its terminal step')
            ->build();

        $run = $rick->run($workflow, ['subject' => 'Laravel'], 7);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('Return a result', $run->task);
        self::assertSame($run->id->toString(), $rick->snapshot($run->id)->id->toString());
        $metrics = $rick->metrics($run->id);
        self::assertSame($run->id->toString(), $metrics->runId->toString());
        self::assertSame(RunStatus::Completed, $metrics->status);
        self::assertSame($run->version, $metrics->runVersion);
        self::assertSame(0, $metrics->callsUsed);
        self::assertSame(7, $metrics->callLimit);
        self::assertSame(0, $metrics->totals->calls);
        self::assertSame(
            [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'cached_input_tokens' => 0,
                'cache_write_input_tokens' => 0,
                'reasoning_tokens' => 0,
            ],
            $metrics->totals->tokens->toArray(),
        );
    }

    public function test_public_external_input_flow_persists_an_artifact_and_continues_by_job(): void
    {
        Queue::fake();
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('external-input')
            ->resolve('Ask for approval', 'An approval artifact is persisted')
            ->waitForInput(
                'approval',
                'Approve publication?',
                ['type' => 'object', 'required' => ['approved']],
                'approval',
            )
            ->build();

        $waiting = $rick->run($workflow);
        $pending = $rick->pendingInput($waiting->id);

        self::assertSame(RunStatus::AwaitingInput, $waiting->status);
        self::assertSame('approval', $pending->key);
        self::assertSame('Approve publication?', $pending->prompt);

        try {
            $rick->submitInput($waiting->id, 'approval', ['wrong' => true]);
            self::fail('Invalid external input should have been rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('keyword [required]', $error->getMessage());
        }
        self::assertSame(RunStatus::AwaitingInput, $rick->snapshot($waiting->id)->status);

        $resumed = $rick->submitInput($waiting->id, 'approval', ['approved' => true]);

        self::assertSame(RunStatus::Running, $resumed->status);
        self::assertSame('{"approved":true}', $resumed->artifact('approval')->content);
        self::assertSame('external_input', $resumed->artifact('approval')->metadata['source']);
        Queue::assertPushed(
            ContinueRunJob::class,
            static fn (ContinueRunJob $job): bool => $job->tenantId === 'default'
                && $job->runId === $waiting->id->toString(),
        );

        (new ContinueRunJob('default', $waiting->id->toString()))->handle(
            $this->application()->make(Handler::class),
            $this->application()->make(TenantContextBase::class),
        );

        self::assertSame(RunStatus::Completed, $rick->snapshot($waiting->id)->status);
    }

    public function test_public_schedule_queues_a_tenant_scoped_continuation(): void
    {
        Queue::fake();
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('scheduled')
            ->resolve('Run later', 'The continuation is queued')
            ->build();

        $scheduled = $rick->schedule($workflow);

        self::assertSame(RunStatus::Created, $scheduled->status);
        Queue::assertPushed(
            ContinueRunJob::class,
            static fn (ContinueRunJob $job): bool => $job->tenantId === 'default'
                && $job->runId === $scheduled->id->toString(),
        );
    }

    public function test_resource_budget_fails_before_an_oversized_invocation_is_dispatched(): void
    {
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('budgeted')
            ->resourceBudget(new ResourceBudget(
                maxInputTokens: 1,
                maxOutputTokens: 1,
                maxTotalTokens: 2,
                defaultOutputReservationTokens: 1,
            ))
            ->resolve('Generate a detailed result', 'The result is detailed')
            ->generate('draft')
            ->build();

        $run = $rick->run($workflow);

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(0, $run->callsUsed);
    }

    public function test_structured_invocation_metrics_and_manual_selection_complete_the_run(): void
    {
        Queue::fake();
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                return new CompletionResponse(
                    text: 'Candidate body',
                    structured: ['content' => 'Candidate body'],
                    provider: 'fake',
                    model: 'fake-structured',
                    metrics: new CompletionMetrics(
                        new TokenUsage(11, 7),
                        InvocationCost::fromUsd('0.0012'),
                        25,
                    ),
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('reviewed')
            ->resolve('Write a candidate', 'The selected draft is returned')
            ->generate('draft', outputKey: 'draft')
            ->manualJudge()
            ->outputGlue('draft')
            ->build();

        $waiting = $rick->run($workflow);
        $review = $rick->pendingReview($waiting->id);
        $metrics = $rick->metrics($waiting->id);

        self::assertSame(RunStatus::AwaitingInput, $waiting->status);
        self::assertCount(1, $review->candidates);
        self::assertSame('Candidate body', $review->candidates[0]->content);
        self::assertSame(1, $metrics->totals->succeededCalls);
        self::assertSame(18, $metrics->totals->tokens->totalTokens);
        self::assertSame('0.0012', $metrics->totals->cost->toUsdDecimal());

        $selected = $rick->selectCandidate($waiting->id, $review->candidates[0]->id);
        self::assertSame('Candidate body', $selected->artifact('draft')->content);

        (new ContinueRunJob('default', $waiting->id->toString()))->handle(
            $this->application()->make(Handler::class),
            $this->application()->make(TenantContextBase::class),
        );

        $completed = $rick->snapshot($waiting->id);
        self::assertSame(RunStatus::Completed, $completed->status);
        self::assertSame('Candidate body', $completed->output());
    }

    public function test_five_generated_plans_pause_for_manual_selection_and_return_the_selected_plan(): void
    {
        Queue::fake();
        $gateway = (new FakeGateway)->respondUsing(
            static function (CompletionRequest $request): CompletionResponse {
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                ) + 1;

                return new CompletionResponse(
                    structured: ['content' => 'Implementation plan '.$index],
                    provider: 'fake',
                    model: 'fake-planner',
                    metrics: new CompletionMetrics(new TokenUsage(10, 5)),
                );
            },
        );
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('choose-a-plan')
            ->resolve(
                'Create a migration plan for a large Laravel application.',
                'Five distinct implementation plans are available for review.',
            )
            ->plan(candidates: 5)
            ->manualJudge()
            ->outputGlue('plan')
            ->build();

        $waiting = $rick->run($workflow);
        $review = $rick->pendingReview($waiting->id);

        self::assertSame(RunStatus::AwaitingInput, $waiting->status);
        self::assertTrue($review->exists());
        self::assertCount(5, $review->candidates);
        $gateway->assertRequested(
            static fn (CompletionRequest $request): bool => $request->purpose === 'generate_candidate',
            times: 5,
        );
        self::assertSame(
            ['Candidate 1', 'Candidate 2', 'Candidate 3', 'Candidate 4', 'Candidate 5'],
            array_map(static fn ($candidate): string => $candidate->title, $review->candidates),
        );
        self::assertCount(5, array_unique(array_map(
            static fn ($candidate): string => $candidate->id->toString(),
            $review->candidates,
        )));

        $waitingVersion = $waiting->version;
        for ($continuation = 0; $continuation < 5; $continuation++) {
            (new ContinueRunJob('default', $waiting->id->toString()))->handle(
                $this->application()->make(Handler::class),
                $this->application()->make(TenantContextBase::class),
            );
        }

        $stillWaiting = $rick->snapshot($waiting->id);
        self::assertSame(RunStatus::AwaitingInput, $stillWaiting->status);
        self::assertSame($waitingVersion, $stillWaiting->version);
        self::assertCount(5, $rick->pendingReview($waiting->id)->candidates);

        $chosen = $review->candidates[3];
        $selected = $rick->selectCandidate($waiting->id, $chosen->id);

        self::assertSame('Implementation plan 4', $selected->artifact('plan')->content);
        self::assertSame(4, $chosen->metadata['candidate_number']);
        self::assertSame(3, $chosen->metadata['original_index']);

        (new ContinueRunJob('default', $waiting->id->toString()))->handle(
            $this->application()->make(Handler::class),
            $this->application()->make(TenantContextBase::class),
        );

        $completed = $rick->snapshot($waiting->id);
        self::assertSame(RunStatus::Completed, $completed->status);
        self::assertSame('Implementation plan 4', $completed->output());
        self::assertSame(5, $completed->callsUsed);
        self::assertSame(75, $rick->metrics($completed->id)->totals->tokens->totalTokens);
        self::assertCount(1, $completed->acceptedCandidates);
        self::assertSame(
            $chosen->id->toString(),
            $completed->acceptedCandidates[0]->id->toString(),
        );
    }

    public function test_quality_rule_set_produces_a_persisted_report(): void
    {
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('quality')
            ->resolve('Check a draft', 'The draft passes configured rules')
            ->context('draft')
            ->qualityGate('draft', 'non_empty', output: 'checked_draft')
            ->outputGlue('checked_draft')
            ->build();

        $run = $rick->run($workflow, ['draft' => 'Verified content']);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('Verified content', $run->artifact('checked_draft')->content);
        $report = JsonInput::map($run->artifact('checked_draft.quality')->payload, 'quality_report');
        $results = JsonInput::list($report['results'] ?? null, 'quality_report.results');
        $result = JsonInput::map($results[0] ?? null, 'quality_report.results.0');
        self::assertTrue(JsonInput::boolean($report['passed'] ?? null, 'quality_report.passed'));
        self::assertSame(
            'content.present',
            $result['rule_id'],
        );
    }

    public function test_registered_llm_operation_uses_model_policy_and_produces_artifact(): void
    {
        $gateway = new class implements GatewayBase
        {
            public ?CompletionRequest $request = null;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->request = $request;

                return new CompletionResponse(
                    text: 'Operation result',
                    provider: 'fake',
                    model: 'fake-operation',
                    metrics: new CompletionMetrics(new TokenUsage(3, 2)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('operation')
            ->resolve('Transform input', 'A transformed artifact is returned')
            ->context('source')
            ->operation('rick.text', 'transformed', ['source'])
            ->outputGlue('transformed')
            ->build();

        $run = $rick->run($workflow, ['source' => 'Input']);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('Operation result', $run->artifact('transformed')->content);
        self::assertSame('rick.text', $run->artifact('transformed')->metadata['operation_id']);
        self::assertSame('medium', $gateway->request?->modelTier);
    }

    public function test_grounded_verification_persists_evidence_report(): void
    {
        $gateway = new class implements GatewayBase
        {
            public ?CompletionRequest $request = null;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->request = $request;

                return new CompletionResponse(
                    structured: [
                        'passed' => true,
                        'claims' => [[
                            'unit_id' => 'unit-00001',
                            'claim' => 'Laravel pipelines are deterministic.',
                            'source_quote' => 'Laravel pipelines are deterministic.',
                            'verdict' => 'supported',
                            'evidence' => [[
                                'artifact_key' => 'evidence',
                                'quote' => 'Laravel pipelines are deterministic',
                            ]],
                        ]],
                    ],
                    provider: 'fake',
                    model: 'fake-grounding',
                    metrics: new CompletionMetrics(new TokenUsage(5, 3)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('grounded')
            ->resolve('Verify a statement', 'The statement is grounded in evidence')
            ->context('target')
            ->context('evidence')
            ->groundedVerify(
                'target',
                ['evidence'],
                output: 'verified',
                minimumQuoteCharacters: 7,
            )
            ->outputGlue('verified')
            ->build();

        $run = $rick->run($workflow, [
            'target' => 'Laravel pipelines are deterministic.',
            'evidence' => 'Laravel pipelines are deterministic when their order is explicit.',
        ]);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertTrue($run->artifact('verified.verification')->payload['passed']);
        self::assertTrue($run->artifact('verified.verification')->payload['model_passed']);
        self::assertSame(['unit-00001'], $run->artifact('verified.verification')->payload['covered_unit_ids']);
        self::assertSame([], $run->artifact('verified.verification')->payload['violations']);
        self::assertSame(1, $run->callsUsed);
        $request = $gateway->request ?? throw new RuntimeException('The grounding request was not captured.');
        $schema = JsonInput::map($request->responseSchema, 'request.response_schema');
        $properties = JsonInput::map($schema['properties'] ?? null, 'request.response_schema.properties');
        $claims = JsonInput::map($properties['claims'] ?? null, 'request.response_schema.properties.claims');
        $items = JsonInput::map($claims['items'] ?? null, 'request.response_schema.properties.claims.items');
        $claimProperties = JsonInput::map(
            $items['properties'] ?? null,
            'request.response_schema.properties.claims.items.properties',
        );
        $verdict = JsonInput::map(
            $claimProperties['verdict'] ?? null,
            'request.response_schema.properties.claims.items.properties.verdict',
        );
        self::assertSame(
            ['supported', 'unsupported', 'no_claims'],
            JsonInput::strings($verdict['enum'] ?? null, 'request.response_schema.properties.claims.items.properties.verdict.enum'),
        );
        $message = $request->messages[1] ?? throw new RuntimeException('The grounding prompt is missing.');
        $promptParts = explode("\n\n", $message->content, 2);
        $prompt = json_decode(
            $promptParts[1] ?? '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $prompt = JsonInput::map($prompt, 'grounding_prompt');
        $parameters = JsonInput::map($prompt['parameters'] ?? null, 'grounding_prompt.parameters');
        self::assertSame('target', $parameters['target_input_key']);
        self::assertSame(['evidence'], $parameters['evidence_artifact_keys']);
        self::assertStringContainsString(
            'verdict must be exactly supported, unsupported, or no_claims',
            JsonInput::string($prompt['instruction'] ?? null, 'grounding_prompt.instruction'),
        );
    }

    public function test_fabricated_grounding_quote_fails_the_run_with_a_persisted_report(): void
    {
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                return new CompletionResponse(
                    structured: [
                        'passed' => true,
                        'claims' => [[
                            'unit_id' => 'unit-00001',
                            'claim' => 'A claim',
                            'source_quote' => 'This quote was never in the target.',
                            'verdict' => 'supported',
                            'evidence' => [[
                                'artifact_key' => 'evidence',
                                'quote' => 'This quote was never in the evidence.',
                            ]],
                        ]],
                    ],
                    provider: 'fake',
                    model: 'fake-grounding',
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('rejected-grounding')
            ->resolve('Verify a statement', 'Fabricated evidence is rejected')
            ->context('target')
            ->context('evidence')
            ->groundedVerify('target', ['evidence'], output: 'verified')
            ->build();

        $run = $rick->run($workflow, [
            'target' => 'The real target statement.',
            'evidence' => 'The real evidence statement.',
        ]);

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertFalse($run->artifact('verified.verification')->payload['passed']);
        self::assertNotEmpty($run->artifact('verified.verification')->payload['violations']);
        self::assertSame(3, $run->callsUsed);
        self::assertSame(0, $run->stepStates['004_grounded_verify']['repairs_used'] ?? null);
        self::assertSame(2, $run->stepStates['004_grounded_verify']['verification_retries_used'] ?? null);
    }

    public function test_grounded_verification_repairs_then_reverifies_the_repaired_artifact(): void
    {
        $gateway = new class implements GatewayBase
        {
            /** @var list<string> */
            public array $purposes = [];

            /** @var list<CompletionRequest> */
            public array $requests = [];

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->purposes[] = $request->purpose;
                $this->requests[] = $request;

                if ($request->purpose === 'operation.rick.repair.text') {
                    return new CompletionResponse(
                        text: 'Laravel pipelines are deterministic.',
                        provider: 'fake',
                        model: 'fake-repair',
                        metrics: new CompletionMetrics(new TokenUsage(3, 2)),
                    );
                }

                $verified = count($this->purposes) === 3;

                return new CompletionResponse(
                    structured: [
                        'passed' => $verified,
                        'claims' => [[
                            'unit_id' => 'unit-00001',
                            'claim' => $verified ? 'A supported claim' : 'An unsupported claim',
                            'source_quote' => $verified
                                ? 'Laravel pipelines are deterministic.'
                                : 'Laravel pipelines are magical.',
                            'verdict' => $verified ? 'supported' : 'unsupported',
                            'evidence' => $verified ? [[
                                'artifact_key' => 'evidence',
                                'quote' => 'Laravel pipelines are deterministic',
                            ]] : [],
                        ]],
                    ],
                    provider: 'fake',
                    model: 'fake-grounding',
                    metrics: new CompletionMetrics(new TokenUsage(5, 3)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('repaired-grounding')
            ->resolve('Repair and verify a statement', 'The repaired statement is grounded')
            ->context('target')
            ->context('evidence')
            ->groundedVerify(
                'target',
                ['evidence'],
                repairOperation: 'rick.repair.text',
                maxRepairs: 1,
                output: 'verified',
                minimumQuoteCharacters: 7,
            )
            ->outputGlue('verified')
            ->build();

        $run = $rick->run($workflow, [
            'target' => 'Laravel pipelines are magical.',
            'evidence' => 'Laravel pipelines are deterministic when their order is explicit.',
        ]);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('Laravel pipelines are deterministic.', $run->output());
        self::assertSame(3, $run->callsUsed);
        self::assertSame([
            'operation.rick.verify.grounded',
            'operation.rick.repair.text',
            'operation.rick.verify.grounded',
        ], $gateway->purposes);
        self::assertSame('rick.repair.text', $run->artifact('verified')->metadata['repaired_by']);
        self::assertSame(1, $run->artifact('verified')->metadata['grounding_repairs']);
        self::assertSame('rick.verify.grounded', $run->artifact('verified')->metadata['verified_by']);
        self::assertTrue($run->artifact('verified.verification')->payload['passed']);
        $state = $run->stepStates['004_grounded_verify'];
        self::assertSame([
            'phase',
            'repairs_used',
            'verification_retries_used',
            'reports',
            'violations',
            'protocol_violations',
        ], array_keys($state));
        self::assertSame('passed', $state['phase']);
        self::assertSame(1, $state['repairs_used']);
        self::assertSame([], $state['violations']);
        $reports = $state['reports'];
        self::assertIsArray($reports);
        $firstReport = $reports[0] ?? null;
        $secondReport = $reports[1] ?? null;
        self::assertIsArray($firstReport);
        self::assertIsArray($secondReport);
        self::assertSame([
            'artifact_key',
            'passed',
            'model_passed',
            'claims',
            'violations',
            'protocol_violations',
            'content_violations',
            'expected_unit_ids',
            'covered_unit_ids',
            'missing_unit_ids',
        ], array_keys($firstReport));
        self::assertFalse($firstReport['passed']);
        self::assertFalse($firstReport['model_passed']);
        self::assertSame(['unit-00001'], $firstReport['expected_unit_ids']);
        self::assertSame(['unit-00001'], $firstReport['covered_unit_ids']);
        self::assertSame([], $firstReport['missing_unit_ids']);
        self::assertSame([
            'Claim [An unsupported claim] is unsupported; only supported or no_claims units may pass.',
        ], $firstReport['violations']);
        self::assertSame('verified', $secondReport['artifact_key']);
        self::assertTrue($secondReport['passed']);
        self::assertTrue($secondReport['model_passed']);
        self::assertSame([], $secondReport['violations']);
        self::assertSame(['unit-00001'], $secondReport['expected_unit_ids']);
        self::assertSame(['unit-00001'], $secondReport['covered_unit_ids']);
        self::assertSame([], $secondReport['missing_unit_ids']);

        $first = self::operationPayload($gateway->requests[0]);
        $repair = self::operationPayload($gateway->requests[1]);
        $second = self::operationPayload($gateway->requests[2]);
        $firstInputs = self::arrayValue($first['inputs'] ?? null);
        $firstTarget = self::arrayValue($firstInputs['target'] ?? null);
        $repairParameters = self::arrayValue($repair['parameters'] ?? null);
        $repairInputs = self::arrayValue($repair['inputs'] ?? null);
        $repairTarget = self::arrayValue($repairInputs['target'] ?? null);
        $secondParameters = self::arrayValue($second['parameters'] ?? null);
        $secondInputs = self::arrayValue($second['inputs'] ?? null);
        $secondTarget = self::arrayValue($secondInputs['target'] ?? null);
        self::assertSame([
            'target_input_key' => 'target',
            'evidence_artifact_keys' => ['evidence'],
            'phase' => 'verify',
            'batch_index' => 0,
            'batch_count' => 1,
            'units' => [[
                'id' => 'unit-00001',
                'content' => 'Laravel pipelines are magical.',
            ]],
            'previous_protocol_violations' => [],
        ], $first['parameters']);
        self::assertSame('Laravel pipelines are magical.', $firstTarget['content']);
        self::assertSame(0, $gateway->requests[0]->metadata['grounding_batch']);
        self::assertSame(['unit-00001'], $gateway->requests[0]->metadata['grounding_unit_ids']);
        self::assertSame('repair', $repairParameters['phase']);
        self::assertSame($firstReport['violations'], $repairParameters['violations']);
        self::assertSame([[
            'artifact_key' => 'target',
            'passed' => false,
            'violations' => $firstReport['violations'],
            'protocol_violations' => [],
            'content_violations' => $firstReport['content_violations'],
            'missing_unit_ids' => [],
        ]], $repairParameters['reports']);
        self::assertSame('Laravel pipelines are magical.', $repairTarget['content']);
        self::assertSame('verify', $secondParameters['phase']);
        self::assertSame(0, $secondParameters['batch_index']);
        self::assertSame(1, $secondParameters['batch_count']);
        self::assertSame([[
            'id' => 'unit-00001',
            'content' => 'Laravel pipelines are deterministic.',
        ]], $secondParameters['units']);
        self::assertSame('Laravel pipelines are deterministic.', $secondTarget['content']);
        self::assertSame('verified', $second['output_key']);
    }

    public function test_quality_gate_honours_the_configured_bounded_repair_count(): void
    {
        $gateway = new class implements GatewayBase
        {
            private int $repair = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->repair++;

                return new CompletionResponse(
                    text: $this->repair === 1 ? 'short' : 'long enough',
                    provider: 'fake',
                    model: 'fake-repair',
                    metrics: new CompletionMetrics(new TokenUsage(2, 2)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $this->application()->instance(RuleSetRegistry::class, new RuleSetRegistry([
            new RuleSet('minimum_ten', [
                new MinimumCharactersRule('content.minimum', 10),
            ]),
        ]));
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('bounded-quality-repair')
            ->resolve('Repair content', 'The artifact passes the quality gate')
            ->context('draft')
            ->qualityGate(
                'draft',
                'minimum_ten',
                'bounded_repair',
                'rick.repair.text',
                2,
                'approved',
            )
            ->outputGlue('approved')
            ->build();

        $run = $rick->run($workflow, ['draft' => 'bad']);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('long enough', $run->output());
        self::assertSame(2, $run->callsUsed);
        self::assertSame(2, $run->artifact('approved')->metadata['quality_repairs']);
        self::assertTrue($run->artifact('approved.quality')->payload['passed']);
    }

    public function test_join_and_branch_use_the_compiled_artifact_graph(): void
    {
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('branch')
            ->resolve('Choose output', 'The true branch is selected')
            ->context('condition')
            ->context('left')
            ->context('right')
            ->join(['left', 'right'], 'joined', separator: '|')
            ->branch('condition', '.', 'equals', 'yes', 'joined', 'right', 'selected')
            ->outputGlue('selected')
            ->build();

        $run = $rick->run($workflow, [
            'condition' => 'yes',
            'left' => 'A',
            'right' => 'B',
        ]);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('A|B', $run->output());
        self::assertSame('true', $run->artifact('selected')->metadata['branch']);
    }

    public function test_map_fans_out_and_reduces_collection_payload(): void
    {
        $gateway = new class implements GatewayBase
        {
            private int $index = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                return new CompletionResponse(
                    text: 'mapped-'.++$this->index,
                    provider: 'fake',
                    model: 'fake-map',
                    metrics: new CompletionMetrics(new TokenUsage(2, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('map')
            ->resolve('Map items', 'Every item is mapped')
            ->context('collection')
            ->map('collection', 'items', 'rick.text', 'mapped')
            ->outputGlue('mapped')
            ->build();

        $run = $rick->run($workflow, ['collection' => ['items' => ['a', 'b']]]);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame(['items' => ['mapped-1', 'mapped-2']], $run->artifact('mapped')->payload);
        self::assertSame(2, $run->callsUsed);
    }

    public function test_define_dod_unfold_parallel_and_edit_strategies_reduce_responses(): void
    {
        $gateway = new class implements GatewayBase
        {
            private int $unfoldUnit = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                if ($request->responseContract === ResponseContract::DefinitionOfDone) {
                    return new CompletionResponse(
                        structured: ['criteria' => ['The output is complete']],
                        provider: 'fake',
                        model: 'fake-dod',
                        metrics: new CompletionMetrics(new TokenUsage(2, 1)),
                    );
                }
                if ($request->responseContract === ResponseContract::UnfoldUnits) {
                    return new CompletionResponse(
                        structured: ['units' => [
                            [
                                'unit_id' => 'u1',
                                'title' => 'First',
                                'source_order' => 1,
                                'content' => 'First',
                                'constraints' => [],
                                'must_preserve' => [],
                                'dependencies' => [],
                                'must_cover' => [],
                                'must_not_repeat' => [],
                                'memory_reads' => [],
                                'memory_writes' => [],
                            ],
                            [
                                'unit_id' => 'u2',
                                'title' => 'Second',
                                'source_order' => 2,
                                'content' => 'Second',
                                'constraints' => [],
                                'must_preserve' => [],
                                'dependencies' => [],
                                'must_cover' => [],
                                'must_not_repeat' => [],
                                'memory_reads' => [],
                                'memory_writes' => [],
                            ],
                        ]],
                        provider: 'fake',
                        model: 'fake-unfold',
                        metrics: new CompletionMetrics(new TokenUsage(3, 2)),
                    );
                }
                if ($request->responseContract === ResponseContract::MemoryCandidate) {
                    $this->unfoldUnit++;

                    return new CompletionResponse(
                        structured: [
                            'artifact_type' => 'units',
                            'title' => 'Expanded unit '.$this->unfoldUnit,
                            'summary' => 'Summary '.$this->unfoldUnit,
                            'content' => 'Expanded '.$this->unfoldUnit,
                            'memory_delta' => [
                                'facts_added' => [],
                                'decisions_added' => [],
                                'loops_opened' => [],
                                'loops_resolved' => [],
                                'requirements_covered' => [],
                                'requirements_violated' => [],
                            ],
                        ],
                        provider: 'fake',
                        model: 'fake-unfold-candidate',
                        metrics: new CompletionMetrics(new TokenUsage(2, 1)),
                    );
                }

                $text = $request->purpose === 'edit'
                    ? 'Edited final output'
                    : JsonInput::string(
                        $request->metadata['output_key'] ?? 'parallel',
                        'request.metadata.output_key',
                    );

                return new CompletionResponse(
                    text: $text,
                    provider: 'fake',
                    model: 'fake-text',
                    metrics: new CompletionMetrics(new TokenUsage(2, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('remaining-strategies')
            ->resolve('Expand and edit', DefinitionOfDone::automatic())
            ->context('source')
            ->unfold('source', 'units')
            ->parallel([
                new OperationCall('first', 'rick.text', null, ['units'], 'parallel_one'),
                new OperationCall('second', 'rick.text', null, ['units'], 'parallel_two'),
            ])
            ->join(['parallel_one', 'parallel_two'], 'joined')
            ->edit()
            ->outputGlue()
            ->build();

        $run = $rick->run($workflow, ['source' => 'Source']);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame(['criteria' => ['The output is complete']], $run->dod->value());
        $unitsPayload = JsonInput::map($run->artifact('units')->payload, 'units_artifact');
        self::assertCount(2, JsonInput::list($unitsPayload['units'] ?? null, 'units_artifact.units'));
        self::assertSame('parallel_one', $run->artifact('parallel_one')->content);
        self::assertSame('parallel_two', $run->artifact('parallel_two')->content);
        self::assertSame('Edited final output', $run->output());
        self::assertSame(7, $run->callsUsed);
    }

    public function test_unfold_processes_units_sequentially_and_commits_working_memory(): void
    {
        $gateway = new class implements GatewayBase
        {
            public int $unit = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                if ($request->responseContract === ResponseContract::UnfoldUnits) {
                    return new CompletionResponse(
                        structured: ['units' => [
                            [
                                'unit_id' => 'intro',
                                'title' => 'Introduction',
                                'source_order' => 1,
                                'content' => 'Write the introduction.',
                                'constraints' => [],
                                'must_preserve' => [],
                                'dependencies' => [],
                                'must_cover' => ['scope'],
                                'must_not_repeat' => [],
                                'memory_reads' => [],
                                'memory_writes' => [],
                            ],
                            [
                                'unit_id' => 'details',
                                'title' => 'Details',
                                'source_order' => 2,
                                'content' => 'Write the details.',
                                'constraints' => [],
                                'must_preserve' => [],
                                'dependencies' => [],
                                'must_cover' => ['implementation'],
                                'must_not_repeat' => [],
                                'memory_reads' => [],
                                'memory_writes' => [],
                            ],
                        ]],
                        provider: 'fake',
                        model: 'fake-unfold',
                        metrics: new CompletionMetrics(new TokenUsage(3, 2)),
                    );
                }

                $this->unit++;

                return new CompletionResponse(
                    structured: [
                        'artifact_type' => 'section',
                        'title' => 'Expanded unit '.$this->unit,
                        'summary' => 'Unit '.$this->unit.' summary',
                        'content' => 'Expanded unit '.$this->unit,
                        'memory_delta' => [
                            'facts_added' => ['Fact '.$this->unit],
                            'decisions_added' => [],
                            'loops_opened' => [],
                            'loops_resolved' => [],
                            'requirements_covered' => [
                                $this->unit === 1 ? 'scope' : 'implementation',
                            ],
                            'requirements_violated' => [],
                        ],
                    ],
                    provider: 'fake',
                    model: 'fake-memory-candidate',
                    metrics: new CompletionMetrics(new TokenUsage(4, 3)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('sequential-unfold')
            ->resolve('Expand a plan', 'Every unit is expanded in order')
            ->context('source')
            ->unfold('source', 'section', candidates: 1, maxUnits: 5)
            ->outputGlue('section')
            ->build();

        $run = $rick->run($workflow, ['source' => 'A two-part plan']);
        $unfoldState = array_values(array_filter(
            $run->stepStates,
            static fn (array $state): bool => isset($state['phase'], $state['memory']),
        ))[0] ?? null;

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('Expanded unit 1'."\n\n".'Expanded unit 2', $run->output());
        self::assertSame('Expanded unit 1'."\n\n".'Expanded unit 2', $run->artifact('section')->content);
        self::assertSame(3, $run->callsUsed);
        self::assertIsArray($unfoldState);
        $memory = JsonInput::map($unfoldState['memory'], 'unfold_state.memory');
        self::assertSame('complete', $unfoldState['phase']);
        self::assertSame(2, $memory['version']);
        self::assertSame(['Fact 1', 'Fact 2'], $memory['facts']);
        self::assertCount(2, JsonInput::list($memory['unit_cards'] ?? null, 'unfold_state.memory.unit_cards'));
        self::assertSame(
            3,
            $this->application()
                ->make(ConnectionInterface::class)
                ->table('rick_step_executions')
                ->where('run_id', $run->id->toString())
                ->where('step_id', array_key_first(array_filter(
                    $run->stepStates,
                    static fn (array $state): bool => isset($state['phase'], $state['memory']),
                )))
                ->count(),
        );
    }

    public function test_unfold_uses_a_direct_list_payload_without_an_explosion_request(): void
    {
        $gateway = new class implements GatewayBase
        {
            /** @var list<ResponseContract> */
            public array $contracts = [];

            private int $unit = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->contracts[] = $request->responseContract;
                $this->unit++;

                return new CompletionResponse(structured: [
                    'artifact_type' => 'section',
                    'title' => 'List unit '.$this->unit,
                    'summary' => 'List summary '.$this->unit,
                    'content' => 'Expanded list unit '.$this->unit,
                    'memory_delta' => [
                        'facts_added' => [],
                        'decisions_added' => [],
                        'loops_opened' => [],
                        'loops_resolved' => [],
                        'requirements_covered' => [],
                        'requirements_violated' => [],
                    ],
                ], provider: 'fake', model: 'fake-list-unfold');
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('list-unfold')
            ->resolve('Expand a list.', 'Every list item is expanded.')
            ->context('source')
            ->unfold('source', 'section', candidates: 1, maxUnits: 2)
            ->outputGlue('section')
            ->build();

        $run = $rick->run($workflow, ['source' => ['First unit', 'Second unit']]);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame(
            'Expanded list unit 1'."\n\n".'Expanded list unit 2',
            $run->output(),
        );
        self::assertSame([
            ResponseContract::MemoryCandidate,
            ResponseContract::MemoryCandidate,
        ], $gateway->contracts);
        self::assertSame(2, $run->callsUsed);
    }

    public function test_fourth_unfold_unit_uses_bounded_continuity_and_rejects_a_copy_of_the_first(): void
    {
        $gateway = new class implements GatewayBase
        {
            /** @var list<CompletionRequest> */
            public array $requests = [];

            private int $unit = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->requests[] = $request;
                $this->unit++;
                $content = match ($this->unit) {
                    1 => 'FIRST_SCENE_FULL_TEXT The storm traps Mara inside the observatory.',
                    2 => 'SECOND_SCENE_FULL_TEXT Mara discovers a damaged emergency transmitter.',
                    3 => 'THIRD_SCENE_FULL_TEXT A distant reply reveals another survivor nearby.',
                    default => 'FIRST_SCENE_FULL_TEXT The storm traps Mara inside the observatory.',
                };

                return new CompletionResponse(
                    structured: [
                        'artifact_type' => 'scene',
                        'title' => 'Scene '.$this->unit,
                        'summary' => 'Continuity summary for beat '.$this->unit,
                        'content' => $content,
                        'memory_delta' => [
                            'facts_added' => [],
                            'decisions_added' => [],
                            'loops_opened' => [],
                            'loops_resolved' => [],
                            'requirements_covered' => ['beat-'.$this->unit],
                            'requirements_violated' => [],
                        ],
                    ],
                    provider: 'fake',
                    model: 'fake-scene-writer',
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $units = array_map(
            static fn (int $number): array => [
                'unit_id' => 'beat-'.$number,
                'title' => 'Beat '.$number,
                'source_order' => $number,
                'content' => 'Write only narrative beat '.$number.'.',
                'constraints' => ['Use prose, not an outline.'],
                'must_preserve' => [],
                'dependencies' => $number === 1 ? [] : ['beat-'.($number - 1)],
                'must_cover' => ['beat-'.$number],
                'must_not_repeat' => [],
                'memory_reads' => [],
                'memory_writes' => [],
            ],
            range(1, 4),
        );
        $workflow = $rick->workflow('unfold-distinct-scenes')
            ->resolve('Write four scenes.', 'Every beat becomes one distinct scene.')
            ->context('outline')
            ->unfold('outline', 'scene', candidates: 1, maxUnits: 4)
            ->outputGlue('scene')
            ->build();

        $run = $rick->run($workflow, [
            'outline' => [
                'private_source_marker' => 'FULL_SOURCE_OUTLINE_MUST_NOT_REACH_GENERATION',
                'units' => $units,
            ],
        ], callLimit: 6);
        $fourth = $gateway->requests[3] ?? throw new RuntimeException(
            'The fourth UNFOLD request was not captured.',
        );
        $userPrompt = $fourth->messages[1]->content;

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(4, $run->callsUsed);
        self::assertCount(3, $run->acceptedCandidates);
        self::assertStringContainsString('Required artifact type: scene.', $userPrompt);
        self::assertStringContainsString('current unit only', $userPrompt);
        self::assertStringContainsString('Write only narrative beat 4.', $userPrompt);
        self::assertStringContainsString('Continuity summary for beat 1', $userPrompt);
        self::assertStringNotContainsString(
            'FULL_SOURCE_OUTLINE_MUST_NOT_REACH_GENERATION',
            $userPrompt,
        );
        self::assertStringNotContainsString('FIRST_SCENE_FULL_TEXT', $userPrompt);
        self::assertSame('scene', $fourth->metadata['expected_artifact_type'] ?? null);
        $distinctness = $fourth->metadata['content_distinctness'] ?? null;
        self::assertIsArray($distinctness);
        $priorSignatures = $distinctness['prior_signatures'] ?? null;
        self::assertIsArray($priorSignatures);
        self::assertCount(
            3,
            $priorSignatures,
        );
        self::assertStringNotContainsString('FIRST_SCENE_FULL_TEXT', json_encode(
            $fourth->metadata,
            JSON_THROW_ON_ERROR,
        ));
    }

    public function test_unfold_manual_judge_resumes_each_unit_without_completing_the_step_early(): void
    {
        Queue::fake();
        $gateway = new class implements GatewayBase
        {
            private int $unit = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                if ($request->responseContract === ResponseContract::UnfoldUnits) {
                    return new CompletionResponse(
                        structured: ['units' => [
                            [
                                'unit_id' => 'one',
                                'title' => 'One',
                                'source_order' => 1,
                                'content' => 'One',
                                'constraints' => [],
                                'must_preserve' => [],
                                'dependencies' => [],
                                'must_cover' => [],
                                'must_not_repeat' => [],
                                'memory_reads' => [],
                                'memory_writes' => [],
                            ],
                            [
                                'unit_id' => 'two',
                                'title' => 'Two',
                                'source_order' => 2,
                                'content' => 'Two',
                                'constraints' => [],
                                'must_preserve' => [],
                                'dependencies' => [],
                                'must_cover' => [],
                                'must_not_repeat' => [],
                                'memory_reads' => [],
                                'memory_writes' => [],
                            ],
                        ]],
                        provider: 'fake',
                        model: 'fake-unfold',
                    );
                }
                $this->unit++;

                return new CompletionResponse(
                    structured: [
                        'artifact_type' => 'section',
                        'title' => 'Reviewed unit '.$this->unit,
                        'summary' => 'Reviewed summary '.$this->unit,
                        'content' => 'Reviewed unit '.$this->unit,
                        'memory_delta' => [
                            'facts_added' => [],
                            'decisions_added' => [],
                            'loops_opened' => [],
                            'loops_resolved' => [],
                            'requirements_covered' => [],
                            'requirements_violated' => [],
                        ],
                    ],
                    provider: 'fake',
                    model: 'fake-candidate',
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('reviewed-unfold')
            ->resolve('Expand reviewed units', 'Every unit is manually selected')
            ->context('source')
            ->unfoldManualJudge('source', 'section', candidates: 1, maxUnits: 2)
            ->outputGlue('section')
            ->build();
        $waiting = $rick->run($workflow, ['source' => 'Two units']);

        self::assertSame(RunStatus::AwaitingInput, $waiting->status);
        $first = $rick->pendingReview($waiting->id)->candidates[0];
        $afterFirst = $rick->selectCandidate($waiting->id, $first->id);
        self::assertSame(RunStatus::Running, $afterFirst->status);
        $this->driveQueuedRun($waiting->id);

        $secondWaiting = $rick->snapshot($waiting->id);
        self::assertSame(RunStatus::AwaitingInput, $secondWaiting->status);
        $second = $rick->pendingReview($waiting->id)->candidates[0];
        self::assertNotSame($first->id->toString(), $second->id->toString());
        $rick->selectCandidate($waiting->id, $second->id);
        $this->driveQueuedRun($waiting->id);

        $completed = $rick->snapshot($waiting->id);
        self::assertSame(RunStatus::Completed, $completed->status);
        self::assertSame(
            'Reviewed unit 1'."\n\n".'Reviewed unit 2',
            $completed->output(),
        );
        self::assertCount(2, $completed->acceptedCandidates);
        $states = array_values(array_filter(
            $completed->stepStates,
            static fn (array $state): bool => isset($state['memory']),
        ));
        $state = JsonInput::map($states[0] ?? null, 'completed.step_state');
        $memory = JsonInput::map($state['memory'] ?? null, 'completed.step_state.memory');
        self::assertSame(
            2,
            $memory['version'],
        );
    }

    public function test_paid_smoke_all_links_definition_executes_every_non_raw_strategy(): void
    {
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                $structured = match ($request->responseContract) {
                    ResponseContract::DefinitionOfDone => [
                        'criteria' => ['Every registered workflow link completes.'],
                    ],
                    ResponseContract::Candidate => [
                        'content' => 'RICK_ALL_LINKS_OK',
                    ],
                    ResponseContract::Json => [
                        'passed' => true,
                        'claims' => [[
                            'unit_id' => 'unit-00001',
                            'claim' => 'RICK_ALL_LINKS_OK confirms every registered workflow link is exercised',
                            'source_quote' => 'RICK_ALL_LINKS_OK confirms every registered workflow link is exercised',
                            'verdict' => 'supported',
                            'evidence' => [[
                                'artifact_key' => 'evidence',
                                'quote' => 'RICK_ALL_LINKS_OK confirms every registered workflow link is exercised',
                            ]],
                        ]],
                    ],
                    ResponseContract::UnfoldUnits => [
                        'units' => [[
                            'unit_id' => 'all-links',
                            'title' => 'All links',
                            'source_order' => 1,
                            'content' => 'Exercise every link.',
                            'constraints' => [],
                            'must_preserve' => [],
                            'dependencies' => [],
                            'must_cover' => [],
                            'must_not_repeat' => [],
                            'memory_reads' => [],
                            'memory_writes' => [],
                        ]],
                    ],
                    ResponseContract::MemoryCandidate => [
                        'artifact_type' => 'unit',
                        'title' => 'Expanded all-links unit',
                        'summary' => 'All links were expanded.',
                        'content' => 'Expanded all-links unit',
                        'memory_delta' => [
                            'facts_added' => ['All links executed'],
                            'decisions_added' => [],
                            'loops_opened' => [],
                            'loops_resolved' => [],
                            'requirements_covered' => [],
                            'requirements_violated' => [],
                        ],
                    ],
                    default => null,
                };

                return new CompletionResponse(
                    text: $structured === null ? 'Short operation result' : '',
                    structured: $structured,
                    provider: 'fake',
                    model: 'fake-all-links',
                    metrics: new CompletionMetrics(new TokenUsage(2, 1)),
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $workflow = AllLinksWorkflow::build($rick, '0.003', requireKnownPricing: false);
        $waitingForInput = $rick->run($workflow, [
            'source' => 'RICK_ALL_LINKS_OK confirms every registered workflow link is exercised',
            'evidence' => 'RICK_ALL_LINKS_OK confirms every registered workflow link is exercised',
            'collection' => ['items' => ['RICK_MAP_OK']],
            'condition' => 'yes',
        ], callLimit: 10);

        self::assertSame(
            RunStatus::AwaitingInput,
            $waitingForInput->status,
            json_encode([
                'calls_used' => $waitingForInput->callsUsed,
                'step_states' => $waitingForInput->stepStates,
                'metrics' => $rick->metrics($waitingForInput->id),
            ], JSON_THROW_ON_ERROR),
        );
        $rick->submitInput($waitingForInput->id, 'approval', ['approved' => true]);
        $this->drivePublicRun($rick, $waitingForInput->id);

        $review = $rick->pendingReview($waitingForInput->id);
        self::assertCount(1, $review->candidates);
        $rick->selectCandidate($waitingForInput->id, $review->candidates[0]->id);
        $this->drivePublicRun($rick, $waitingForInput->id);

        $completed = $rick->snapshot($waitingForInput->id);

        self::assertSame(
            RunStatus::Completed,
            $completed->status,
            json_encode([
                'calls_used' => $completed->callsUsed,
                'artifacts' => array_keys($completed->artifacts),
                'step_states' => $completed->stepStates,
                'metrics' => $rick->metrics($waitingForInput->id),
            ], JSON_THROW_ON_ERROR),
        );
        self::assertSame(10, $completed->callsUsed);
        self::assertSame('true', $completed->artifact('selected')->metadata['branch']);
        self::assertTrue($completed->artifact('checked.quality')->payload['passed']);
        self::assertTrue($completed->artifact('verified.verification')->payload['passed']);
        self::assertSame('Expanded all-links unit', $completed->artifact('unit')->content);
        self::assertSame('Short operation result', $completed->output());
    }

    private function driveQueuedRun(RunId $runId): void
    {
        $handler = $this->application()->make(Handler::class);
        for ($transition = 0; $transition < 20; $transition++) {
            $result = $handler
                ->handle(Parcel::fromArray([
                    new ContinueRunRequest($runId),
                ]))
                ->get(ContinueRunResult::class);
            foreach ($result->invocations as $invocationId) {
                $handler->handle(Parcel::fromArray([
                    new ExecuteInvocationRequest(
                        $invocationId,
                    ),
                ]));
            }
            if (! in_array(
                $result->status,
                [
                    ContinueRunStatus::Continue,
                    ContinueRunStatus::Dispatch,
                ],
                true,
            )) {
                return;
            }
        }

        self::fail('Queued run did not reach an interaction or terminal barrier.');
    }

    /** @return array<string, mixed> */
    private static function operationPayload(CompletionRequest $request): array
    {
        $message = $request->messages[1] ?? throw new RuntimeException('Operation payload is missing.');
        $parts = explode("\n\n", $message->content, 2);

        return JsonInput::map(
            json_decode($parts[1] ?? '', true, flags: JSON_THROW_ON_ERROR),
            'operation_payload',
        );
    }

    /** @return array<mixed> */
    private static function arrayValue(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private function drivePublicRun(Rick $rick, RunId $runId): void
    {
        for ($transition = 0; $transition < 128; $transition++) {
            $snapshot = $rick->snapshot($runId);
            if ($snapshot->status === RunStatus::AwaitingInput || $snapshot->status->isTerminal()) {
                return;
            }
            $rick->resume($runId);
        }

        $database = $this->application()->make(ConnectionInterface::class);
        self::fail(json_encode([
            'message' => 'Public resume did not reach an interaction or terminal barrier.',
            'snapshot' => $rick->snapshot($runId),
            'outbox' => $database
                ->table('rick_outbox')
                ->where('run_id', $runId->toString())
                ->orderBy('created_at')
                ->get(['kind', 'status', 'attempts', 'last_error_code'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
