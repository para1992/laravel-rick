<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Llm;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Infrastructure\Llm\PromptMapper;

final class PromptMapperTest extends TestCase
{
    public function test_raw_prompt_has_no_implicit_system_instruction(): void
    {
        $mapped = (new PromptMapper)->map(new CompletionRequest(
            [new Message('user', '  Keep this exact prompt.  ')],
            ResponseContract::Text,
            'raw_prompt',
            metadata: ['raw_prompt' => true],
        ));

        self::assertSame('', $mapped['instructions']);
        self::assertSame([], $mapped['history']);
        self::assertSame('  Keep this exact prompt.  ', $mapped['prompt']);
    }

    public function test_regular_request_maps_its_explicit_system_instruction(): void
    {
        $mapped = (new PromptMapper)->map(new CompletionRequest(
            [
                new Message('system', 'Explicit step instructions.'),
                new Message('user', 'Normal request'),
            ],
            ResponseContract::Text,
            'generate',
            metadata: ['raw_prompt' => true],
        ));

        self::assertSame('Explicit step instructions.', $mapped['instructions']);
    }

    public function test_regular_request_without_system_instruction_fails_fast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require an explicit system prompt');

        (new PromptMapper)->map(new CompletionRequest(
            [new Message('user', 'Normal request')],
            ResponseContract::Text,
            'generate',
        ));
    }
}
