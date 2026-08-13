<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Llm\Prompt;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\StepPromptDefinition;

final class StepPromptDefinitionTest extends TestCase
{
    public function test_it_preserves_fields_and_hashes_the_exact_delimited_contract(): void
    {
        $definition = new StepPromptDefinition(
            'rick.step.generate',
            '2.1.0',
            'System Zażółć/slash',
        );

        self::assertSame('rick.step.generate', $definition->id);
        self::assertSame('2.1.0', $definition->version);
        self::assertSame('System Zażółć/slash', $definition->system);
        self::assertSame(
            hash('sha256', 'rick.step.generate'."\0".'2.1.0'."\0".'System Zażółć/slash'),
            $definition->hash(),
        );
    }

    #[DataProvider('invalidDefinitions')]
    public function test_it_rejects_each_invalid_definition(
        string $id,
        string $version,
        string $system,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new StepPromptDefinition($id, $version, $system);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'empty id' => ['', '1', 'System', 'Step prompt ID must be a stable identifier.'];
        yield 'uppercase id' => ['Rick.step', '1', 'System', 'Step prompt ID must be a stable identifier.'];
        yield 'id starts with digit' => ['1rick.step', '1', 'System', 'Step prompt ID must be a stable identifier.'];
        yield 'id contains slash' => ['rick/step', '1', 'System', 'Step prompt ID must be a stable identifier.'];
        yield 'blank version' => ['rick.step', " \t ", 'System', 'Step prompt version must not be empty.'];
        yield 'blank system' => ['rick.step', '1', "\n\t", 'Step system prompt must not be empty.'];
    }
}
