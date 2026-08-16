<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Llm\Agent;

use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Schemable;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Tools\Request;
use LogicException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Exception\UnsupportedAgentCapabilityException;
use Rick\Laravel\Application\Execution\Support\Llm\Agent\AgentRequestFactory;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Stringable;

final class AgentRequestFactoryTest extends TestCase
{
    private AgentRequestFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new AgentRequestFactory;
    }

    public function test_it_builds_a_single_text_request_from_a_plain_agent(): void
    {
        $request = $this->factory->create(
            TextSummarizerAgent::class,
            'summarizer',
            'Summarize the report.',
            'quality',
            ['run_id' => 7],
        );

        self::assertInstanceOf(CompletionRequest::class, $request);
        self::assertCount(2, $request->messages);
        self::assertSame('system', $request->messages[0]->role);
        self::assertSame('You are a summarizer.', $request->messages[0]->content);
        self::assertSame('user', $request->messages[1]->role);
        self::assertSame('Summarize the report.', $request->messages[1]->content);
        self::assertSame(ResponseContract::Text, $request->responseContract);
        self::assertNull($request->responseSchema);
        self::assertSame('agent:summarizer', $request->purpose);
        self::assertSame('quality', $request->modelTier);
        self::assertSame([], $request->options);
        self::assertSame(TextSummarizerAgent::class, $request->metadata['agent_class']);
        self::assertSame('summarizer', $request->metadata['agent_alias']);
        self::assertSame(7, $request->metadata['run_id']);
    }

    public function test_it_accepts_stringable_instructions(): void
    {
        $request = $this->factory->create(StringableInstructionsAgent::class, 'extractor', 'Extract the fields.');

        self::assertInstanceOf(CompletionRequest::class, $request);
        self::assertCount(2, $request->messages);
        self::assertSame('Summarize the evidence.', $request->messages[0]->content);
    }

    public function test_it_maps_a_schemable_agent_to_one_json_request_with_its_schema(): void
    {
        $request = $this->factory->create(SchemableExtractorAgent::class, 'extractor', 'Extract the fields.');

        self::assertInstanceOf(CompletionRequest::class, $request);
        self::assertCount(2, $request->messages);
        self::assertSame(ResponseContract::Json, $request->responseContract);
        self::assertSame(['title' => 'string', 'sentiment' => 'string'], $request->responseSchema);
    }

    public function test_it_rejects_agents_that_declare_tools(): void
    {
        try {
            $this->factory->create(ToolsAgent::class, 'toolsmith', 'Use your tools.');
            self::fail('Expected the tools capability to be rejected.');
        } catch (UnsupportedAgentCapabilityException $exception) {
            self::assertSame(HasTools::class, $exception->capability);
            self::assertStringContainsString('HasTools', $exception->getMessage());
        }
    }

    public function test_it_rejects_agents_that_declare_approvals(): void
    {
        try {
            $this->factory->create(ApprovalAgent::class, 'approver', 'Ask for approval.');
            self::fail('Expected the approval capability to be rejected.');
        } catch (UnsupportedAgentCapabilityException $exception) {
            self::assertSame(Approvable::class, $exception->capability);
        }
    }

    public function test_it_rejects_conversational_agents(): void
    {
        try {
            $this->factory->create(ConversationalAgent::class, 'chatter', 'Keep the conversation going.');
            self::fail('Expected the conversational capability to be rejected.');
        } catch (UnsupportedAgentCapabilityException $exception) {
            self::assertSame(Conversational::class, $exception->capability);
        }
    }

    public function test_it_rejects_classes_that_are_not_agents(): void
    {
        $this->expectException(UnsupportedAgentCapabilityException::class);
        $this->expectExceptionMessage(Agent::class);

        $this->factory->create(NotAnAgent::class, 'orphan', 'Adapt me.');
    }

    public function test_it_rejects_agents_with_empty_schemas(): void
    {
        $this->expectException(UnsupportedAgentCapabilityException::class);
        $this->expectExceptionMessage('non-empty schema');

        $this->factory->create(EmptySchemaAgent::class, 'extractor', 'Extract the fields.');
    }

    public function test_it_reads_provider_model_and_generation_attributes_into_options(): void
    {
        $request = $this->factory->create(PinnedAgent::class, 'pinned', 'Pin the route.');

        self::assertSame('openrouter', $request->options['provider']);
        self::assertSame('google/gemini-flash', $request->options['model']);
        self::assertSame(512, $request->options['max_tokens']);
        self::assertSame(0.2, $request->options['temperature']);
        self::assertSame(0.9, $request->options['top_p']);
    }

    public function test_it_normalizes_provider_enum_attributes_to_their_string_value(): void
    {
        $request = $this->factory->create(LabProviderAgent::class, 'pinned', 'Pin the lab.');

        self::assertSame('openrouter', $request->options['provider']);
    }
}

abstract class FakeAgent implements Agent
{
    abstract public function instructions(): Stringable|string;

    /**
     * @param  array<array-key, mixed>  $attachments
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     */
    public function prompt(
        Decisions|string $prompt,
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
        Decisions|string $prompt,
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
        Decisions|string $prompt,
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
        Decisions|string $prompt,
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
        Decisions|string $prompt,
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
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): never {
        throw new LogicException('The adapted agent must never receive provider requests.');
    }
}

final class TextSummarizerAgent extends FakeAgent
{
    public function instructions(): string
    {
        return 'You are a summarizer.';
    }
}

final class StringableInstructionsAgent extends FakeAgent
{
    public function instructions(): Stringable
    {
        return new class implements Stringable
        {
            public function __toString(): string
            {
                return 'Summarize the evidence.';
            }
        };
    }
}

final class SchemableExtractorAgent extends FakeAgent implements Schemable
{
    public function instructions(): string
    {
        return 'Extract structured fields.';
    }

    public function name(): string
    {
        return 'extraction';
    }

    public function toSchema(): array
    {
        return ['title' => 'string', 'sentiment' => 'string'];
    }
}

final class EmptySchemaAgent extends FakeAgent implements Schemable
{
    public function instructions(): string
    {
        return 'Extract nothing.';
    }

    public function name(): string
    {
        return 'empty';
    }

    public function toSchema(): array
    {
        return [];
    }
}

final class ToolsAgent extends FakeAgent implements HasTools
{
    public function instructions(): string
    {
        return 'Use the search tool.';
    }

    public function tools(): iterable
    {
        return [];
    }
}

final class ApprovalAgent extends FakeAgent implements Approvable
{
    public function instructions(): string
    {
        return 'Ask before acting.';
    }

    public function requireApproval(?string $reason = null): static
    {
        return $this;
    }

    public function withoutApproval(): static
    {
        return $this;
    }

    public function shouldRequestApproval(Request $request): never
    {
        throw new LogicException('The adapted agent must never run tool requests.');
    }
}

final class ConversationalAgent extends FakeAgent implements Conversational
{
    public function instructions(): string
    {
        return 'Keep chatting.';
    }

    public function messages(): iterable
    {
        return [];
    }
}

#[Provider('openrouter')]
#[Model('google/gemini-flash')]
#[MaxTokens(512)]
#[Temperature(0.2)]
#[TopP(0.9)]
final class PinnedAgent extends FakeAgent
{
    public function instructions(): string
    {
        return 'Use the pinned provider and model.';
    }
}

#[Provider(Lab::OpenRouter)]
final class LabProviderAgent extends FakeAgent
{
    public function instructions(): string
    {
        return 'Use the pinned lab.';
    }
}

final class NotAnAgent {}
