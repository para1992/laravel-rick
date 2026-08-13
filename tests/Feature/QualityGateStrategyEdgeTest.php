<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use LogicException;
use Rick\Laravel\Application\Execution\Exception\QualityGateFailedException;
use Rick\Laravel\Application\Execution\Strategy\QualityGateStrategy;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\LlmOperationBase;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationDefinition;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\OperationContext;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\Result\OperationResult;
use Rick\Laravel\Application\Execution\Support\Quality\RepairPolicyRegistry;
use Rick\Laravel\Application\Execution\Support\Quality\Rules\MinimumCharactersRule;
use Rick\Laravel\Application\Execution\Support\Quality\RuleSet;
use Rick\Laravel\Application\Execution\Support\Quality\RuleSetRegistry;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\Plan\ImmediateStepPlan;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Step\QualityGateStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Tests\TestCase;

final class QualityGateStrategyEdgeTest extends TestCase
{
    public function test_type_support_and_incompatible_steps_are_exact(): void
    {
        $strategy = $this->strategy();

        self::assertTrue($strategy->supports(StepType::qualityGate()));
        self::assertFalse($strategy->supports(StepType::rawPrompt()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Quality-gate strategy received an incompatible step.');
        $strategy->plan(new RawPromptStep(StepId::fromString('raw'), 'prompt'), $this->snapshot());
    }

    public function test_plan_passes_immediately_with_exact_report_and_artifacts(): void
    {
        $plan = $this->strategy()->plan($this->step(policy: 'fail'), $this->snapshot('long enough'));

        self::assertInstanceOf(ImmediateStepPlan::class, $plan);
        $outcome = $plan->outcome;
        self::assertFalse($outcome->continuesStep);
        self::assertSame([
            'phase' => 'passed',
            'repairs_used' => 0,
            'reports' => [[
                'rule_set_id' => 'minimum_ten',
                'artifact_key' => 'draft',
                'artifact_version' => 1,
                'passed' => true,
                'results' => [[
                    'rule_id' => 'content.minimum',
                    'passed' => true,
                    'message' => 'Artifact contains 11 characters.',
                    'path' => 'content',
                    'metadata' => ['actual' => 11, 'minimum' => 10],
                ]],
            ]],
        ], $outcome->stepState);
        self::assertSame(['quality_gate' => 'passed', 'repairs_used' => 0], $outcome->metadata);
        self::assertSame(['approved', 'approved.quality'], array_column($outcome->artifacts, 'key'));
        self::assertSame('long enough', $outcome->artifacts[0]->content);
        self::assertSame(['origin' => 'input', 'quality_repairs' => 0], $outcome->artifacts[0]->metadata);
        self::assertTrue($outcome->artifacts[1]->payload['passed']);
        self::assertSame($outcome->artifacts[1]->payload, json_decode($outcome->artifacts[1]->content, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_plan_fail_policy_throws_the_exact_quality_report(): void
    {
        try {
            $this->strategy()->plan($this->step(policy: 'fail'), $this->snapshot('short'));
            self::fail('Fail policy accepted an invalid artifact.');
        } catch (QualityGateFailedException $error) {
            self::assertSame('quality_gate_failed', $error->errorCode());
            self::assertSame('minimum_ten', $error->report->ruleSetId);
            self::assertSame('draft', $error->report->artifactKey);
            self::assertFalse($error->report->passed());
            self::assertSame('Artifact contains 5 characters; at least 10 are required.', $error->report->violations()[0]->message);
        }
    }

    public function test_plan_repair_defaults_malformed_state_and_builds_exact_operation_context(): void
    {
        $plan = $this->strategy()->plan(
            $this->step(),
            $this->snapshot('short', ['repairs_used' => 'invalid', 'reports' => 'invalid']),
        );

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(1, $plan->requests);
        $request = $plan->requests[0];
        self::assertSame([
            'operation_id' => 'rick.repair.text',
            'operation_version' => '1.0.0',
            'validator_sets' => [],
            'output_key' => 'approved',
        ], $request->metadata);
        $payload = $this->operationPayload($request->messages[1]->content);
        $inputs = self::arrayValue($payload['inputs'] ?? null);
        $artifact = self::arrayValue($inputs['artifact'] ?? null);
        $parameters = self::arrayValue($payload['parameters'] ?? null);
        $qualityReport = self::arrayValue($parameters['quality_report'] ?? null);
        self::assertSame('draft', $artifact['key']);
        self::assertSame('short', $artifact['content']);
        self::assertSame(1, $parameters['repair_number']);
        self::assertSame(2, $parameters['maximum_repairs']);
        self::assertFalse($qualityReport['passed']);
        self::assertSame('approved', $payload['output_key']);
    }

    public function test_reduce_accepts_a_repair_and_preserves_exact_metadata(): void
    {
        $outcome = $this->strategy()->reduce(
            $this->step(),
            $this->snapshot('short'),
            [$this->invocation('long enough')],
        );

        self::assertFalse($outcome->continuesStep);
        $stepState = self::arrayValue($outcome->stepState);
        $reports = self::arrayValue($stepState['reports'] ?? null);
        $report = self::arrayValue($reports[0] ?? null);
        self::assertSame('passed', $stepState['phase']);
        self::assertSame(1, $stepState['repairs_used']);
        self::assertTrue($report['passed']);
        self::assertSame(['quality_gate' => 'passed', 'repairs_used' => 1], $outcome->metadata);
        self::assertSame(['approved', 'approved.quality'], array_column($outcome->artifacts, 'key'));
        self::assertSame('long enough', $outcome->artifacts[0]->content);
        self::assertSame(['text' => 'long enough'], $outcome->artifacts[0]->payload);
        self::assertSame([
            'origin' => 'input',
            'operation_id' => 'rick.repair.text',
            'operation_version' => '1.0.0',
            'provider' => 'repair-provider',
            'model' => 'repair-model',
            'repaired_by' => 'rick.repair.text',
            'quality_repairs' => 1,
        ], $outcome->artifacts[0]->metadata);
    }

    public function test_reduce_returns_exact_repair_then_failed_continuations(): void
    {
        $repair = $this->strategy()->reduce(
            $this->step(),
            $this->snapshot('bad', ['reports' => 'invalid']),
            [$this->invocation('still bad')],
        );
        self::assertTrue($repair->continuesStep);
        self::assertSame(['phase' => 'repair', 'repairs_used' => 1, 'reports' => [$repair->artifacts[1]->payload]], $repair->stepState);
        self::assertSame(['quality_gate' => 'repair', 'repairs_used' => 1], $repair->metadata);
        self::assertSame('still bad', $repair->artifacts[0]->content);
        self::assertSame(1, $repair->artifacts[0]->metadata['quality_repairs']);

        $failed = $this->strategy()->reduce(
            $this->step(),
            $this->snapshot('bad', ['repairs_used' => 1, 'reports' => [$repair->artifacts[1]->payload]], 'still bad'),
            [$this->invocation('tiny')],
        );
        self::assertTrue($failed->continuesStep);
        $failedState = self::arrayValue($failed->stepState);
        self::assertSame('failed', $failedState['phase']);
        self::assertSame(2, $failedState['repairs_used']);
        self::assertCount(2, self::arrayValue($failedState['reports'] ?? null));
        self::assertSame(['quality_gate' => 'failed', 'repairs_used' => 2], $failed->metadata);
        self::assertSame('tiny', $failed->artifacts[0]->content);
        self::assertSame(2, $failed->artifacts[0]->metadata['quality_repairs']);
    }

    public function test_repair_state_reads_the_previous_output_and_missing_operation_fails_closed(): void
    {
        $plan = $this->strategy()->plan(
            $this->step(),
            $this->snapshot('original draft', ['repairs_used' => 1], 'bad'),
        );
        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        $payload = $this->operationPayload($plan->requests[0]->messages[1]->content);
        $inputs = self::arrayValue($payload['inputs'] ?? null);
        $artifact = self::arrayValue($inputs['artifact'] ?? null);
        $parameters = self::arrayValue($payload['parameters'] ?? null);
        self::assertSame('approved', $artifact['key']);
        self::assertSame('bad', $artifact['content']);
        self::assertSame(2, $parameters['repair_number']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Quality repair has no configured operation.');
        $this->strategy()->reduce(
            $this->step(operation: null),
            $this->snapshot('bad'),
            [$this->invocation('long enough')],
        );
    }

    public function test_current_artifact_selection_observes_both_boolean_boundaries(): void
    {
        $withoutOutput = $this->strategy()->plan(
            $this->step(),
            $this->snapshot('bad', ['repairs_used' => 1]),
        );
        self::assertInstanceOf(InvocationStepPlan::class, $withoutOutput);
        $withoutOutputPayload = $this->operationPayload($withoutOutput->requests[0]->messages[1]->content);
        $withoutOutputInputs = self::arrayValue($withoutOutputPayload['inputs'] ?? null);
        self::assertSame('draft', self::arrayValue($withoutOutputInputs['artifact'] ?? null)['key']);

        $zeroRepairs = $this->strategy()->plan(
            $this->step(),
            $this->snapshot('bad', ['repairs_used' => 0], 'long enough'),
        );
        self::assertInstanceOf(InvocationStepPlan::class, $zeroRepairs);
        $payload = $this->operationPayload($zeroRepairs->requests[0]->messages[1]->content);
        $inputs = self::arrayValue($payload['inputs'] ?? null);
        $artifact = self::arrayValue($inputs['artifact'] ?? null);
        self::assertSame('draft', $artifact['key']);
        self::assertSame('bad', $artifact['content']);
    }

    public function test_reduce_passes_the_exact_repair_context_to_the_operation(): void
    {
        $definition = $this->application()->make(LlmOperationRegistry::class)
            ->get('rick.repair.text')
            ->definition();
        $operation = new class($definition) implements LlmOperationBase
        {
            public ?OperationContext $reductionContext = null;

            public function __construct(private readonly LlmOperationDefinition $operationDefinition) {}

            public function definition(): LlmOperationDefinition
            {
                return $this->operationDefinition;
            }

            public function requests(OperationContext $context): array
            {
                return [new CompletionRequest(
                    [new Message('user', 'repair')],
                    ResponseContract::Text,
                    'quality.repair.spy',
                )];
            }

            public function reduce(OperationContext $context, array $responses): OperationResult
            {
                $this->reductionContext = $context;

                return new OperationResult([new Artifact(
                    $context->outputKey,
                    ArtifactType::fromString('text'),
                    $responses[0]->text,
                )]);
            }
        };
        $strategy = $this->strategy(new LlmOperationRegistry([$operation]));

        $strategy->reduce(
            $this->step(),
            $this->snapshot('original draft', ['repairs_used' => 1], 'bad'),
            [$this->invocation('long enough')],
        );

        self::assertNotNull($operation->reductionContext);
        self::assertSame('approved', $operation->reductionContext->outputKey);
        self::assertSame(2, $operation->reductionContext->attempt);
        self::assertSame(['artifact'], array_keys($operation->reductionContext->inputs));
        self::assertSame('approved', $operation->reductionContext->inputs['artifact']->key);
        self::assertSame('bad', $operation->reductionContext->inputs['artifact']->content);
        self::assertSame(2, $operation->reductionContext->parameters['repair_number']);
        self::assertSame(2, $operation->reductionContext->parameters['maximum_repairs']);
        $qualityReport = self::arrayValue($operation->reductionContext->parameters['quality_report'] ?? null);
        self::assertSame('approved', $qualityReport['artifact_key']);
        self::assertFalse($qualityReport['passed']);
    }

    private function strategy(?LlmOperationRegistry $operations = null): QualityGateStrategy
    {
        return new QualityGateStrategy(
            new RuleSetRegistry([new RuleSet('minimum_ten', [new MinimumCharactersRule('content.minimum', 10)])]),
            new RepairPolicyRegistry,
            $operations ?? $this->application()->make(LlmOperationRegistry::class),
        );
    }

    private function step(
        string $policy = 'bounded_repair',
        ?string $operation = 'rick.repair.text',
    ): QualityGateStep {
        return new QualityGateStep(
            StepId::fromString('quality'),
            'draft',
            'minimum_ten',
            $policy,
            $operation,
            maxRepairs: 2,
            outputKey: 'approved',
        );
    }

    /** @param array<string, mixed> $state */
    private function snapshot(string $content = 'short', array $state = [], ?string $approved = null): WorkflowRunSnapshot
    {
        $artifacts = [
            'draft' => new Artifact('draft', ArtifactType::fromString('text'), $content, metadata: ['origin' => 'input']),
        ];
        if ($approved !== null) {
            $artifacts['approved'] = new Artifact('approved', ArtifactType::fromString('text'), $approved, metadata: ['origin' => 'repair']);
        }

        return new WorkflowRunSnapshot(
            RunId::fromString('quality-run'),
            RunStatus::Running,
            1,
            new RunInput([]),
            'Repair draft',
            DefinitionOfDone::automatic(),
            [],
            [],
            [],
            [],
            $state === [] ? [] : ['quality' => $state],
            null,
            null,
            0,
            10,
            $artifacts,
        );
    }

    private function invocation(string $text): InvocationOutcome
    {
        return new InvocationOutcome(
            InvocationId::fromString('quality-invocation'),
            0,
            1,
            InvocationStatus::Succeeded,
            new CompletionResponse($text, provider: 'repair-provider', model: 'repair-model'),
            null,
            null,
        );
    }

    /** @return array<string, mixed> */
    private function operationPayload(string $message): array
    {
        [, $json] = explode("\n\n", $message, 2);

        return JsonInput::map(
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            'quality operation payload',
        );
    }

    /** @return array<mixed> */
    private static function arrayValue(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
