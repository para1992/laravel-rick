<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Llm\PromptBounds;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;

final class PromptBoundsTest extends TestCase
{
    public function test_prompt_bounds_preserve_system_contract_and_audit_deterministic_truncation(): void
    {
        $request = new CompletionRequest(
            [
                new Message('system', 'System contract'),
                new Message('user', str_repeat('x', 2_000)),
            ],
            ResponseContract::Text,
            'bounded',
        );

        $bounded = (new PromptBounds(1_000))->apply($request);

        self::assertSame('System contract', $bounded->messages[0]->content);
        self::assertStringEndsWith('[TRUNCATED]', $bounded->messages[1]->content);
        self::assertSame(1_000, array_sum(array_map(
            static fn (Message $message): int => mb_strlen($message->content),
            $bounded->messages,
        )));
        $audit = JsonInput::map($bounded->metadata['prompt_bounds'] ?? null, 'metadata.prompt_bounds');
        self::assertTrue(JsonInput::boolean($audit['truncated'] ?? null, 'metadata.prompt_bounds.truncated'));
        self::assertSame(
            64,
            strlen(JsonInput::string($audit['source_hash'] ?? null, 'metadata.prompt_bounds.source_hash')),
        );
    }

    public function test_prompt_bounds_compact_duplicate_artifact_json_without_corrupting_it(): void
    {
        $task = str_repeat('t', 600);
        $artifact = str_repeat('e', 600);
        $structuredPayload = ['frames' => [['title' => 'Frame one']]];
        $structuredContent = json_encode(
            $structuredPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
        $payload = [
            'task' => $task,
            'inputs' => [
                'evidence' => [
                    'content' => $artifact,
                    'payload' => ['text' => $artifact],
                ],
                'frames' => [
                    'content' => $structuredContent,
                    'payload' => $structuredPayload,
                ],
            ],
            'parameters' => ['units' => [['id' => 'unit-00001', 'content' => 'Unit.']]],
            'instruction' => 'Verify every unit.',
            'output_schema' => ['type' => 'object'],
        ];
        $request = new CompletionRequest(
            [
                new Message('system', 'System contract'),
                new Message('user', $task."\n\n".json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                )),
            ],
            ResponseContract::Json,
            'bounded-json',
            responseSchema: [
                'type' => 'object',
                'properties' => ['answer' => ['type' => 'string']],
                'required' => ['answer'],
                'additionalProperties' => false,
            ],
            structuredResponseAttempts: 2,
        );

        $bounded = (new PromptBounds(2_000))->apply($request);
        $separator = strrpos($bounded->messages[1]->content, "\n\n{");
        self::assertIsInt($separator);
        $decoded = json_decode(
            substr($bounded->messages[1]->content, $separator + 2),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        self::assertSame('[DUPLICATED BEFORE JSON]', $decoded['task'] ?? null);
        $inputs = $decoded['inputs'] ?? null;
        self::assertIsArray($inputs);
        $evidence = $inputs['evidence'] ?? null;
        self::assertIsArray($evidence);
        $artifactPayload = $evidence['payload'] ?? null;
        self::assertIsArray($artifactPayload);
        self::assertSame(
            '[DUPLICATED IN ARTIFACT CONTENT]',
            $artifactPayload['text'] ?? null,
        );
        self::assertSame($artifact, $evidence['content'] ?? null);
        $frames = $inputs['frames'] ?? null;
        self::assertIsArray($frames);
        self::assertSame(
            '[DUPLICATED IN ARTIFACT CONTENT]',
            $frames['payload'] ?? null,
        );
        $parameters = $decoded['parameters'] ?? null;
        self::assertIsArray($parameters);
        $units = $parameters['units'] ?? null;
        self::assertIsArray($units);
        $unit = $units[0] ?? null;
        self::assertIsArray($unit);
        self::assertSame('unit-00001', $unit['id'] ?? null);
        self::assertSame(2, $bounded->structuredResponseAttempts);
        self::assertLessThanOrEqual(2_000, array_sum(array_map(
            static fn (Message $message): int => mb_strlen($message->content),
            $bounded->messages,
        )));
    }
}
