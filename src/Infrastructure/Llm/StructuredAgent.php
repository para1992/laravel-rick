<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Llm;

use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaBase;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use UnexpectedValueException;

#[Strict]
final class StructuredAgent extends AnonymousAgent implements HasProviderOptions, HasStructuredOutput
{
    private readonly GenerationOptions $options;

    /**
     * @param  iterable<mixed>  $messages
     * @param  iterable<mixed>  $tools
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools = [],
        private readonly array $schema = [],
        ?GenerationOptions $options = null,
    ) {
        parent::__construct($instructions, $messages, $tools);
        $this->options = $options ?? GenerationOptions::from([], 60);
    }

    public function maxTokens(): ?int
    {
        return $this->options->maxTokens;
    }

    public function temperature(): ?float
    {
        return $this->options->temperature;
    }

    public function topP(): ?float
    {
        return $this->options->topP;
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->options->provider;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchemaBase $factory): array
    {
        $result = [];
        $required = array_fill_keys(self::strings($this->schema['required'] ?? []), true);
        foreach (self::map($this->schema['properties'] ?? []) as $name => $definition) {
            $type = JsonSchema::fromArray(self::map($definition));
            if (isset($required[$name])) {
                $type->required();
            }
            $result[$name] = $type;
        }

        return $result;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException('Structured response required fields must be a list.');
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new UnexpectedValueException('Structured response required fields must be strings.');
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('Structured response schema properties must be objects.');
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Structured response schema properties must use string keys.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
