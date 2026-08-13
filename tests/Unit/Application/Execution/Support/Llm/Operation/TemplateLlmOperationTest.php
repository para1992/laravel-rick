<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Llm\Operation;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Llm\ModelPolicy;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationDefinition;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\OperationContext;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\PromptTemplate;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\TemplateLlmOperation;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;

final class TemplateLlmOperationTest extends TestCase
{
    public function test_definition_and_json_request_preserve_the_complete_operation_contract(): void
    {
        $definition = $this->definition(ResponseContract::Json, 'json', [
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
            'additionalProperties' => false,
        ]);
        $operation = new TemplateLlmOperation($definition);

        self::assertSame($definition, $operation->definition());
        $requests = $operation->requests($this->context());
        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame('System instruction', $request->messages[0]->content);
        self::assertSame('operation.rick.test', $request->purpose);
        self::assertSame('operation-model', $request->modelTier);
        self::assertSame(['temperature' => 0.25], $request->options);
        self::assertSame(ResponseContract::Json, $request->responseContract);
        self::assertSame($definition->prompt->outputSchema, $request->responseSchema);
        self::assertSame([
            'operation_id' => 'rick.test',
            'operation_version' => '2.1.0',
            'validator_sets' => ['strict', 'safe'],
            'output_key' => 'result',
        ], $request->metadata);

        $payload = json_decode(
            explode("\n\n", $request->messages[1]->content, 2)[1],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('Do Zażółć/slash', explode("\n\n", $request->messages[1]->content, 2)[0]);
        self::assertSame([
            'task' => 'Do Zażółć/slash',
            'definition_of_done' => ['criteria' => ['Exact']],
            'inputs' => [
                'source' => [
                    'schema_version' => 1,
                    'key' => 'source',
                    'type' => 'text',
                    'content' => 'Source',
                    'payload' => ['value' => 7],
                    'metadata' => ['origin' => 'test'],
                    'version' => 2,
                ],
            ],
            'parameters' => ['tone' => 'clear'],
            'output_key' => 'result',
            'instruction' => 'Return the exact result.',
            'output_schema' => $definition->prompt->outputSchema,
        ], $payload);
    }

    public function test_non_json_request_does_not_attach_a_response_schema(): void
    {
        $request = (new TemplateLlmOperation(
            $this->definition(ResponseContract::Candidate, 'draft'),
        ))->requests($this->context())[0];

        self::assertNull($request->responseSchema);
        self::assertSame(ResponseContract::Candidate, $request->responseContract);
    }

    public function test_reduce_preserves_structured_payload_pretty_content_and_provider_metadata(): void
    {
        $result = (new TemplateLlmOperation(
            $this->definition(ResponseContract::Json, 'json', ['type' => 'object']),
        ))->reduce($this->context(), [new CompletionResponse(
            text: 'ignored',
            structured: ['answer' => 'Zażółć/slash'],
            provider: 'fake-provider',
            model: 'fake-model',
        )]);

        self::assertSame([[
            'schema_version' => 1,
            'key' => 'result',
            'type' => 'json',
            'content' => "{\n    \"answer\": \"Zażółć\\/slash\"\n}",
            'payload' => ['answer' => 'Zażółć/slash'],
            'metadata' => [
                'operation_id' => 'rick.test',
                'operation_version' => '2.1.0',
                'provider' => 'fake-provider',
                'model' => 'fake-model',
            ],
            'version' => 1,
        ]], array_map(static fn (Artifact $artifact): array => $artifact->toArray(), $result->artifacts));
    }

    public function test_reduce_keeps_plain_text_and_does_not_normalize_non_html_fences(): void
    {
        $content = "```html\n<!doctype html><p>Text</p>\n```";
        $result = (new TemplateLlmOperation(
            $this->definition(ResponseContract::Text, 'text'),
        ))->reduce($this->context(), [new CompletionResponse($content)]);

        self::assertSame($content, $result->artifacts[0]->content);
        self::assertSame(['text' => $content], $result->artifacts[0]->payload);
    }

    public function test_reduce_unwraps_a_fenced_artifact_envelope_for_a_text_response(): void
    {
        $content = "```json\n".json_encode([
            'schema_version' => 1,
            'key' => 'result',
            'type' => 'retell_chapter',
            'content' => 'Only the repaired chapter.',
            'payload' => ['text' => 'Only the repaired chapter.'],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n```";
        $result = (new TemplateLlmOperation(
            $this->definition(ResponseContract::Text, 'retell_chapter'),
        ))->reduce($this->context(), [new CompletionResponse($content)]);

        self::assertSame('Only the repaired chapter.', $result->artifacts[0]->content);
        self::assertSame(['text' => 'Only the repaired chapter.'], $result->artifacts[0]->payload);
    }

    public function test_reduce_unwraps_only_a_complete_fenced_html_document(): void
    {
        $operation = new TemplateLlmOperation($this->definition(ResponseContract::Text, 'html'));
        $fenced = "  ```html\n<!DOCTYPE html>\n<html><body>Result</body></html>   \n```  ";

        self::assertSame(
            "<!DOCTYPE html>\n<html><body>Result</body></html>",
            $operation->reduce($this->context(), [new CompletionResponse($fenced)])->artifacts[0]->content,
        );

        $fragment = "```html\n<section>Fragment</section>\n```";
        self::assertSame(
            $fragment,
            $operation->reduce($this->context(), [new CompletionResponse($fragment)])->artifacts[0]->content,
        );
    }

    /** @param array<string, mixed>|null $schema */
    private function definition(
        ResponseContract $contract,
        string $outputType,
        ?array $schema = null,
    ): LlmOperationDefinition {
        return new LlmOperationDefinition(
            'rick.test',
            '2.1.0',
            new PromptTemplate('System instruction', 'Return the exact result.', $schema),
            $contract,
            ArtifactType::fromString($outputType),
            new ModelPolicy('operation-model', options: ['temperature' => 0.25]),
            ['strict', 'safe'],
        );
    }

    private function context(): OperationContext
    {
        return new OperationContext(
            new WorkflowRunSnapshot(
                RunId::fromString('operation-run'),
                RunStatus::Running,
                1,
                new RunInput([]),
                'Do Zażółć/slash',
                DefinitionOfDone::structured(['criteria' => ['Exact']]),
                [],
                [],
                [],
                [],
                [],
                null,
                null,
                0,
                10,
            ),
            [
                'source' => new Artifact(
                    'source',
                    ArtifactType::fromString('text'),
                    'Source',
                    ['value' => 7],
                    ['origin' => 'test'],
                    2,
                ),
            ],
            'result',
            ['tone' => 'clear'],
            2,
        );
    }
}
