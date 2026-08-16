<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Strategy;

use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use LogicException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Strategy\AgentStepStrategy;
use Rick\Laravel\Application\Execution\Support\Llm\Agent\AgentRequestFactory;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Step\AgentStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Stringable;

final class AgentStepStrategyTest extends TestCase
{
    private AgentStepStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = new AgentStepStrategy(new AgentRequestFactory);
    }

    public function test_it_plans_exactly_one_request_under_the_agent_purpose(): void
    {
        $plan = $this->plan();

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(1, $plan->requests);
        self::assertInstanceOf(CompletionRequest::class, $plan->requests[0]);
        self::assertSame('agent:summarizer', $plan->requests[0]->purpose);
        self::assertSame(TextSummarizerAgent::class, $plan->requests[0]->metadata['agent_class']);
        self::assertSame('summarizer', $plan->requests[0]->metadata['agent_alias']);
        self::assertSame(3, $plan->requests[0]->metadata['agent_version']);
    }

    public function test_it_uses_the_steps_explicit_prompt_when_set(): void
    {
        $plan = $this->plan(prompt: 'Draft the summary.');

        self::assertSame('Draft the summary.', $plan->requests[0]->messages[1]->content);
    }

    public function test_it_builds_a_default_prompt_containing_the_run_input(): void
    {
        $plan = $this->plan();

        $prompt = $plan->requests[0]->messages[1]->content;
        $decoded = json_decode($prompt, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertSame(['claim_id' => 7], $decoded['input']);
        self::assertSame([], $decoded['artifacts']);
    }

    public function test_it_maps_a_structured_response_to_a_json_artifact(): void
    {
        $outcome = $this->strategy->reduce(
            $this->step(),
            $this->snapshot(),
            [$this->outcome(new CompletionResponse('ignored', ['title' => 'Invoice', 'total' => 12]))],
        );

        self::assertInstanceOf(StepOutcome::class, $outcome);
        self::assertCount(1, $outcome->artifacts);
        $artifact = $outcome->artifacts[0];
        self::assertSame('summarizer', $artifact->key);
        self::assertSame('agent', $artifact->type->toString());
        self::assertSame(
            '{"title":"Invoice","total":12}',
            $artifact->content,
        );
        self::assertSame(['title' => 'Invoice', 'total' => 12], $artifact->payload);
        self::assertSame([
            'agent_class' => TextSummarizerAgent::class,
            'agent_alias' => 'summarizer',
        ], $outcome->metadata);
    }

    public function test_it_maps_a_text_response_to_a_text_artifact(): void
    {
        $outcome = $this->strategy->reduce(
            $this->step(),
            $this->snapshot(),
            [$this->outcome(new CompletionResponse('Plain text answer'))],
        );

        self::assertCount(1, $outcome->artifacts);
        $artifact = $outcome->artifacts[0];
        self::assertSame('summarizer', $artifact->key);
        self::assertSame('agent', $artifact->type->toString());
        self::assertSame('Plain text answer', $artifact->content);
        self::assertSame([], $artifact->payload);
    }

    public function test_it_supports_only_the_agent_step_type(): void
    {
        self::assertTrue($this->strategy->supports(StepType::agent()));
        self::assertFalse($this->strategy->supports(StepType::generate()));
        self::assertFalse($this->strategy->supports(StepType::judge()));
    }

    public function test_it_rejects_an_incompatible_step(): void
    {
        $raw = new RawPromptStep(StepId::fromString('raw'), 'Prompt');

        try {
            $this->strategy->plan($raw, $this->snapshot());
            self::fail('An incompatible agent plan was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Agent strategy received an incompatible step.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Agent strategy received an incompatible step.');
        $this->strategy->reduce($raw, $this->snapshot(), [$this->outcome(new CompletionResponse('Text'))]);
    }

    private function step(?string $prompt = null): AgentStep
    {
        return new AgentStep(
            StepId::fromString('summarizer'),
            TextSummarizerAgent::class,
            3,
            null,
            'medium',
            $prompt,
        );
    }

    private function plan(?string $prompt = null): InvocationStepPlan
    {
        $plan = $this->strategy->plan($this->step(prompt: $prompt), $this->snapshot());
        self::assertInstanceOf(InvocationStepPlan::class, $plan);

        return $plan;
    }

    private function snapshot(): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('agent-run'),
            RunStatus::Running,
            1,
            new RunInput(['claim_id' => 7]),
            'Summarize the claim.',
            DefinitionOfDone::automatic(),
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            0,
            10,
            [],
        );
    }

    private function outcome(CompletionResponse $response): InvocationOutcome
    {
        return new InvocationOutcome(
            InvocationId::fromString('invocation-1'),
            0,
            1,
            InvocationStatus::Succeeded,
            $response,
            null,
            null,
        );
    }
}

abstract class AgentStepTestAgent implements Agent
{
    abstract public function instructions(): Stringable|string;

    /**
     * @param  array<array-key, mixed>  $attachments
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     */
    public function prompt(
        mixed $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): never {
        throw new LogicException('The adapted agent must never receive provider requests.');
    }

    /**
     * @param  array<array-key, mixed>  $attachments
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     */
    public function stream(
        mixed $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): never {
        throw new LogicException('The adapted agent must never receive provider requests.');
    }

    /**
     * @param  array<array-key, mixed>  $attachments
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     */
    public function queue(
        mixed $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): never {
        throw new LogicException('The adapted agent must never receive provider requests.');
    }

    /**
     * @param  Channel|array<array-key, mixed>  $channels
     * @param  array<array-key, mixed>  $attachments
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     */
    public function broadcast(
        mixed $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): never {
        throw new LogicException('The adapted agent must never receive provider requests.');
    }

    /**
     * @param  Channel|array<array-key, mixed>  $channels
     * @param  array<array-key, mixed>  $attachments
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     */
    public function broadcastNow(
        mixed $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): never {
        throw new LogicException('The adapted agent must never receive provider requests.');
    }

    /**
     * @param  Channel|array<array-key, mixed>  $channels
     * @param  array<array-key, mixed>  $attachments
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     */
    public function broadcastOnQueue(
        mixed $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): never {
        throw new LogicException('The adapted agent must never receive provider requests.');
    }
}

final class TextSummarizerAgent extends AgentStepTestAgent
{
    public function instructions(): string
    {
        return 'You are a summarizer.';
    }
}
