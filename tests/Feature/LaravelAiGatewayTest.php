<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\PricingBase;
use Rick\Laravel\Application\Execution\Support\Schema\ResponseSchemaResolver;
use Rick\Laravel\Application\Execution\Support\Schema\StructuredResponseDecoder;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Infrastructure\Llm\GenerationOptions;
use Rick\Laravel\Infrastructure\Llm\LaravelAiGateway;
use Rick\Laravel\Infrastructure\Llm\ModelRouter;
use Rick\Laravel\Infrastructure\Llm\PromptMapper;
use Rick\Laravel\Infrastructure\Llm\StructuredAgent;
use Rick\Laravel\Infrastructure\Llm\TextAgent;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\Support\AllLinksWorkflow;
use Rick\Laravel\Tests\TestCase;

final class LaravelAiGatewayTest extends TestCase
{
    public function test_openrouter_candidate_uses_the_exact_strict_response_format_fixture(): void
    {
        $this->configureProvider('openrouter');
        Http::fake(['*' => Http::response(self::openRouterResponse('Strict plan'))]);

        $response = $this->gateway('openrouter', 'openai/gpt-4.1-mini')->complete(
            new CompletionRequest(
                [
                    new Message('system', 'Create one structured plan candidate.'),
                    new Message('user', 'Create a plan'),
                ],
                ResponseContract::Candidate,
                'strict_candidate_test',
            ),
        );

        self::assertSame('Strict plan', $response->structured['content'] ?? null);
        self::assertIsString($response->metadata['gateway_invocation_id'] ?? null);
        self::assertNull($response->metadata['provider_request_id'] ?? null);
        self::assertNull($response->metadata['provider_generation_id'] ?? null);
        self::assertSame('unavailable', $response->metadata['provider_id_source'] ?? null);
        self::assertSame('stop', $response->diagnostic?->finishReason);
        $fixture = self::fixture('openrouter-candidate-response-format-v1.json');
        $recorded = Http::recorded();
        self::assertCount(1, $recorded);
        $exchange = $recorded[0] ?? null;
        self::assertNotNull($exchange);
        $request = $exchange[0];
        self::assertInstanceOf(Request::class, $request);
        self::assertSame($fixture, $request->data()['response_format'] ?? null);
    }

    public function test_gemini_candidate_uses_the_compatible_schema_fixture(): void
    {
        $this->configureProvider('gemini');
        Http::fake(['*' => Http::response(self::geminiResponse('Gemini plan'))]);

        $response = $this->gateway('gemini', 'gemini-2.5-flash-lite')->complete(
            new CompletionRequest(
                [
                    new Message('system', 'Create one structured plan candidate.'),
                    new Message('user', 'Create a plan'),
                ],
                ResponseContract::Candidate,
                'gemini_candidate_test',
            ),
        );

        self::assertSame('Gemini plan', $response->structured['content'] ?? null);
        $fixture = self::fixture('gemini-candidate-generation-config-v1.json');
        $recorded = Http::recorded();
        self::assertCount(1, $recorded);
        $exchange = $recorded[0] ?? null;
        self::assertNotNull($exchange);
        $request = $exchange[0];
        self::assertInstanceOf(Request::class, $request);
        self::assertSame($fixture, $request->data()['generationConfig'] ?? null);
    }

    public function test_invalid_strict_schema_fails_before_provider_dispatch(): void
    {
        $this->configureProvider('openrouter');
        Http::fake();

        try {
            $this->gateway('openrouter', 'openai/gpt-4.1-mini')->complete(
                new CompletionRequest(
                    [
                        new Message('system', 'Return strict JSON.'),
                        new Message('user', 'Create JSON'),
                    ],
                    ResponseContract::Json,
                    'invalid_schema_test',
                    responseSchema: [
                        'type' => 'object',
                        'properties' => [
                            'required_value' => ['type' => 'string'],
                            'optional_value' => ['type' => 'string'],
                        ],
                        'required' => ['required_value'],
                    ],
                ),
            );
            self::fail('Invalid strict schema was accepted.');
        } catch (ProviderRequestException $error) {
            self::assertSame('provider_request_preflight_failed', $error->safeCode);
        }

        Http::assertNothingSent();
    }

    public function test_two_plan_candidates_complete_on_an_openai_compatible_strict_provider(): void
    {
        Queue::fake();
        $this->configureProvider('openrouter');
        $call = 0;
        Http::fake(['*' => function () use (&$call) {
            $call++;

            return Http::response(self::openRouterResponse('Plan '.$call));
        }]);
        $this->application()->instance(
            GatewayBase::class,
            $this->gateway('openrouter', 'openai/gpt-4.1-mini'),
        );
        $rick = $this->application()->make(Rick::class);

        $waiting = $rick->run($rick->workflow('strict-two-plans')
            ->resolve('Prepare a rollout.', 'Two plans are reviewable.')
            ->plan(candidates: 2)
            ->manualJudge()
            ->outputGlue('plan')
            ->build());

        self::assertCount(2, $rick->pendingReview($waiting->id)->candidates);
        self::assertSame(2, $call);
    }

    public function test_text_response_is_mapped_from_a_fake_laravel_ai_agent(): void
    {
        TextAgent::fake([
            new TextResponse(
                'Text result',
                new Usage(promptTokens: 5, completionTokens: 3),
                new Meta('fake-provider', 'fake-text'),
            ),
        ]);

        $response = $this->application()->make(GatewayBase::class)->complete(new CompletionRequest(
            [
                new Message('system', 'Return one text result.'),
                new Message('user', 'Write text'),
            ],
            ResponseContract::Text,
            'text_test',
        ));

        self::assertSame('Text result', $response->text);
        self::assertNull($response->structured);
        self::assertSame('fake-provider', $response->provider);
        self::assertSame('fake-text', $response->model);
        self::assertSame(8, $response->metrics?->tokens->totalTokens);
    }

    public function test_structured_response_is_mapped_from_a_fake_laravel_ai_agent(): void
    {
        StructuredAgent::fake([
            new StructuredTextResponse(
                ['content' => 'Structured result'],
                '{"content":"Structured result"}',
                new Usage(promptTokens: 7, completionTokens: 4),
                new Meta('fake-provider', 'fake-structured'),
            ),
        ]);

        $response = $this->application()->make(GatewayBase::class)->complete(new CompletionRequest(
            [
                new Message('system', 'Return one structured result.'),
                new Message('user', 'Write structured text'),
            ],
            ResponseContract::Candidate,
            'structured_test',
        ));

        self::assertSame(['content' => 'Structured result'], $response->structured);
        self::assertSame('fake-provider', $response->provider);
        self::assertSame('fake-structured', $response->model);
        self::assertSame(11, $response->metrics?->tokens->totalTokens);
    }

    public function test_zero_sdk_usage_is_preserved_as_missing_instead_of_complete(): void
    {
        StructuredAgent::fake([
            new StructuredTextResponse(
                ['content' => 'Structured result'],
                '{"content":"Structured result"}',
                new Usage,
                new Meta('fake-provider', 'fake-structured'),
            ),
        ]);

        $response = $this->application()->make(GatewayBase::class)->complete(new CompletionRequest(
            [
                new Message('system', 'Return one structured result.'),
                new Message('user', 'Write structured text'),
            ],
            ResponseContract::Candidate,
            'missing_usage_test',
        ));

        self::assertNotNull($response->metrics);
        self::assertNotNull($response->diagnostic);
        self::assertFalse($response->metrics->usagePresent);
        self::assertFalse($response->metrics->usageComplete);
        self::assertFalse($response->diagnostic->usagePresent);
        self::assertFalse($response->diagnostic->usageComplete);
    }

    public function test_generation_limits_are_exposed_to_the_laravel_ai_sdk(): void
    {
        $options = GenerationOptions::from([
            'max_tokens' => 160,
            'temperature' => 0.1,
            'top_p' => 0.8,
            'provider' => ['sort' => 'price'],
        ], 30);
        $agents = [
            new TextAgent('Text', [], options: $options),
            new StructuredAgent(
                'Structured',
                [],
                schema: ['type' => 'object'],
                options: $options,
            ),
        ];

        foreach ($agents as $agent) {
            self::assertSame(160, $agent->maxTokens());
            self::assertSame(0.1, $agent->temperature());
            self::assertSame(0.8, $agent->topP());
            self::assertSame(['provider' => ['sort' => 'price']], $agent->providerOptions('openrouter'));
        }
    }

    public function test_unfold_contract_exposes_units_to_the_laravel_ai_sdk(): void
    {
        StructuredAgent::fake([
            new StructuredTextResponse(
                ['units' => [[
                    'unit_id' => 'unit-1',
                    'title' => 'One unit',
                    'source_order' => 1,
                    'content' => 'Execute one unit.',
                ]]],
                '{"units":[{"unit_id":"unit-1","title":"One unit","source_order":1,"content":"Execute one unit."}]}',
                new Usage(promptTokens: 7, completionTokens: 4),
                new Meta('fake-provider', 'fake-structured'),
            ),
        ]);

        $response = $this->application()->make(GatewayBase::class)->complete(new CompletionRequest(
            [
                new Message('system', 'Split the source into execution units.'),
                new Message('user', 'Split into one unit'),
            ],
            ResponseContract::UnfoldUnits,
            'unfold_contract_test',
        ));

        self::assertArrayHasKey('units', $response->structured ?? []);
        StructuredAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $agent = $prompt->agent;
            if (! $agent instanceof HasStructuredOutput) {
                return false;
            }

            $schema = $agent->schema(new JsonSchemaTypeFactory);
            self::assertArrayHasKey('units', $schema);

            return true;
        });
    }

    public function test_paid_all_links_definition_covers_every_built_in_strategy(): void
    {
        $rick = $this->application()->make(Rick::class);
        $coveredTypes = AllLinksWorkflow::coveredTypes(
            $rick,
            AllLinksWorkflow::build($rick, '0.003'),
        );
        $configuredTypes = array_keys((array) config('rick.execution.strategies'));
        sort($configuredTypes);

        self::assertSame($configuredTypes, $coveredTypes);
    }

    private function gateway(string $provider, string $model): LaravelAiGateway
    {
        return new LaravelAiGateway(
            new ModelRouter(['medium' => ['provider' => $provider, 'model' => $model]]),
            $this->application()->make(PromptMapper::class),
            $this->application()->make(PricingBase::class),
            responseSchemas: $this->application()->make(ResponseSchemaResolver::class),
            structuredResponses: $this->application()->make(StructuredResponseDecoder::class),
        );
    }

    private function configureProvider(string $provider): void
    {
        $configuration = config("ai.providers.{$provider}", []);
        self::assertIsArray($configuration);
        config(["ai.providers.{$provider}" => [...$configuration, 'key' => 'test-key']]);
    }

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        $json = file_get_contents(dirname(__DIR__).'/Fixtures/structured-output/'.$name);
        self::assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return JsonInput::map($decoded, 'structured output fixture');
    }

    /** @return array<string, mixed> */
    private static function openRouterResponse(string $content): array
    {
        return [
            'id' => 'generation-test',
            'model' => 'openai/gpt-4.1-mini',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode(['content' => $content], JSON_THROW_ON_ERROR),
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];
    }

    /** @return array<string, mixed> */
    private static function geminiResponse(string $content): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode(['content' => $content], JSON_THROW_ON_ERROR),
                ]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
        ];
    }
}
