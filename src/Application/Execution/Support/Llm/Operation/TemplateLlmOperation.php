<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Operation;

use Rick\Laravel\Application\Execution\Support\Llm\Interface\LlmOperationBase;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\Result\OperationResult;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\Artifact;

final readonly class TemplateLlmOperation implements LlmOperationBase
{
    public function __construct(private LlmOperationDefinition $definition) {}

    public function definition(): LlmOperationDefinition
    {
        return $this->definition;
    }

    public function requests(OperationContext $context): array
    {
        $definition = $this->definition;
        $model = $definition->model;
        $responseSchema = $context->responseSchema ?? $definition->prompt->outputSchema;

        return [new CompletionRequest(
            [
                new Message('system', $definition->prompt->system),
                new Message(
                    'user',
                    $context->run->task."\n\n".json_encode(
                        $context->promptPayload() + [
                            'instruction' => $definition->prompt->instruction,
                            'output_schema' => $responseSchema,
                        ],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                    ),
                ),
            ],
            $definition->responseContract,
            'operation.'.$definition->id,
            $model->id,
            $model->options,
            metadata: [
                'operation_id' => $definition->id,
                'operation_version' => $definition->version,
                'validator_sets' => $definition->validatorSetIds,
                'output_key' => $context->outputKey,
            ],
            responseSchema: $definition->responseContract === ResponseContract::Json
                ? $responseSchema
                : null,
            structuredResponseAttempts: $context->structuredResponseAttempts,
        )];
    }

    public function reduce(OperationContext $context, array $responses): OperationResult
    {
        $response = $responses[0];
        $content = $response->structured === null
            ? $response->text
            : json_encode(
                $response->structured,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );
        if ($this->definition->responseContract === ResponseContract::Text) {
            $content = self::normalizeTextArtifactEnvelope($content);
        }
        $content = self::normalizeHtmlArtifact($content, $this->definition->outputType->toString());
        $payload = $response->structured ?? ['text' => $content];

        return new OperationResult([new Artifact(
            $context->outputKey,
            $this->definition->outputType,
            $content,
            $payload,
            [
                'operation_id' => $this->definition->id,
                'operation_version' => $this->definition->version,
                'provider' => $response->provider,
                'model' => $response->model,
            ],
        )]);
    }

    private static function normalizeTextArtifactEnvelope(string $content): string
    {
        $candidate = trim($content);

        for ($depth = 0; $depth < 4; $depth++) {
            $encoded = $candidate;
            if (preg_match('/\A```([a-z0-9_-]*)\s*(.*?)\s*```\z/is', $candidate, $matches) === 1) {
                if (! in_array(strtolower($matches[1]), ['', 'json'], true)) {
                    break;
                }
                $encoded = trim($matches[2]);
            }

            $decoded = json_decode($encoded, true);
            if (! is_array($decoded)
                || ! array_key_exists('schema_version', $decoded)
                || ! is_string($decoded['key'] ?? null)
                || ! is_string($decoded['type'] ?? null)) {
                break;
            }

            $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
            $nested = is_string($decoded['content'] ?? null) && trim($decoded['content']) !== ''
                ? $decoded['content']
                : ($payload['text'] ?? null);
            if (! is_string($nested) || trim($nested) === '' || trim($nested) === $candidate) {
                break;
            }

            $candidate = trim($nested);
        }

        return $candidate;
    }

    private static function normalizeHtmlArtifact(string $content, string $outputType): string
    {
        if ($outputType !== 'html') {
            return $content;
        }

        $trimmed = trim($content);
        if (preg_match('/\A```(?:html)?\s*(<!doctype\s+html>.*)\s*```\z/is', $trimmed, $matches) !== 1) {
            return $content;
        }

        return rtrim($matches[1]);
    }
}
