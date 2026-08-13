<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use JsonException;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Llm\ValueObject\TextResponsePolicy;
use UnexpectedValueException;

final readonly class CompletionRequestCodec
{
    private const int VERSION = 1;

    public function encode(CompletionRequest $request): string
    {
        return json_encode([
            'schema_version' => self::VERSION,
            'request' => [
                'messages' => array_map(
                    static fn (Message $message): array => [
                        'role' => $message->role,
                        'content' => $message->content,
                    ],
                    $request->messages,
                ),
                'response_contract' => $request->responseContract->value,
                'purpose' => $request->purpose,
                'model_tier' => $request->modelTier,
                'options' => $request->options,
                'text_response_policy' => $request->textResponsePolicy === null ? null : [
                    'minimum_characters' => $request->textResponsePolicy->minimumCharacters,
                    'rejected_prefixes' => $request->textResponsePolicy->rejectedPrefixes,
                    'allow_truncated' => $request->textResponsePolicy->allowTruncated,
                ],
                'metadata' => $request->metadata,
                'response_schema' => $request->responseSchema,
                'structured_response_attempts' => $request->structuredResponseAttempts,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $payload): CompletionRequest
    {
        $data = $this->payload($payload, 'request');
        $policy = $data['text_response_policy'] ?? null;
        $messages = [];
        foreach (JsonInput::list($data['messages'] ?? null, 'completion request.messages') as $value) {
            $message = JsonInput::map($value, 'completion request.message');
            $messages[] = new Message(
                JsonInput::string($message['role'] ?? null, 'completion request.message.role'),
                JsonInput::string($message['content'] ?? null, 'completion request.message.content'),
            );
        }
        $responseSchema = $data['response_schema'] ?? null;

        return new CompletionRequest(
            $messages,
            ResponseContract::from(JsonInput::string(
                $data['response_contract'] ?? null,
                'completion request.response_contract',
            )),
            JsonInput::string($data['purpose'] ?? null, 'completion request.purpose'),
            JsonInput::string($data['model_tier'] ?? null, 'completion request.model_tier'),
            JsonInput::map($data['options'] ?? [], 'completion request.options'),
            $policy === null ? null : self::policy(JsonInput::map(
                $policy,
                'completion request.text_response_policy',
            )),
            JsonInput::map($data['metadata'] ?? [], 'completion request.metadata'),
            $responseSchema === null
                ? null
                : JsonInput::map($responseSchema, 'completion request.response_schema'),
            isset($data['structured_response_attempts'])
                ? JsonInput::integer(
                    $data['structured_response_attempts'],
                    'completion request.structured_response_attempts',
                )
                : null,
        );
    }

    /** @param array<string, mixed> $policy */
    private static function policy(array $policy): TextResponsePolicy
    {
        return new TextResponsePolicy(
            JsonInput::integer(
                $policy['minimum_characters'] ?? null,
                'completion request.text_response_policy.minimum_characters',
            ),
            JsonInput::strings(
                $policy['rejected_prefixes'] ?? null,
                'completion request.text_response_policy.rejected_prefixes',
            ),
            JsonInput::boolean(
                $policy['allow_truncated'] ?? null,
                'completion request.text_response_policy.allow_truncated',
            ),
        );
    }

    /** @return array<string, mixed> */
    private function payload(string $payload, string $key): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted completion request is not valid JSON.', previous: $error);
        }
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Persisted completion request must be an object.');
        }
        $envelope = JsonInput::map($decoded, 'completion request envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported completion request schema version.');
        }

        return JsonInput::map($envelope[$key] ?? null, "completion request.{$key}");
    }
}
