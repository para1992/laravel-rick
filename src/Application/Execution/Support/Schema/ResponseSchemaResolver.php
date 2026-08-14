<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Schema;

use InvalidArgumentException;
use JsonException;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Llm\ValueObject\StrictSchema;

final readonly class ResponseSchemaResolver
{
    public function __construct(private JsonSchemaValidatorBase $schemas) {}

    /** @return array<string, mixed> */
    public function for(CompletionRequest $request): array
    {
        if ($request->responseContract === ResponseContract::Text) {
            throw new InvalidArgumentException('Text completions do not have a structured output schema.');
        }

        $schema = $request->responseSchema ?? self::packageSchema($request->responseContract);
        $this->schemas->assertSchema($schema);
        StrictSchema::assertStrict($schema);

        return $schema;
    }

    public function fingerprint(CompletionRequest $request): string
    {
        try {
            $json = json_encode(
                $this->for($request),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'Structured output schema is not JSON serializable.',
                previous: $error,
            );
        }

        return hash('sha256', $json);
    }

    /** @return array<string, mixed> */
    private static function packageSchema(ResponseContract $contract): array
    {
        return match ($contract) {
            ResponseContract::Candidate,
            ResponseContract::PlanCandidate => StrictSchema::object([
                'content' => ['type' => 'string'],
            ]),
            ResponseContract::MemoryCandidate => StrictSchema::object([
                'content' => ['type' => 'string'],
                'memory_delta' => StrictSchema::object([
                    'facts_added' => self::strings(),
                    'decisions_added' => self::strings(),
                    'loops_opened' => self::strings(),
                    'loops_resolved' => self::strings(),
                    'requirements_covered' => self::strings(),
                    'requirements_violated' => self::strings(),
                ]),
            ]),
            ResponseContract::Judge => StrictSchema::object([
                'selected_candidate_id' => ['type' => 'string'],
                'score' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
            ]),
            ResponseContract::UnfoldUnits => StrictSchema::object([
                'units' => [
                    'type' => 'array',
                    'items' => StrictSchema::object([
                        'unit_id' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'source_order' => ['type' => 'integer'],
                        'content' => ['type' => 'string'],
                        'constraints' => self::strings(),
                        'must_preserve' => self::strings(),
                        'dependencies' => self::strings(),
                        'must_cover' => self::strings(),
                        'must_not_repeat' => self::strings(),
                        'memory_reads' => self::strings(),
                        'memory_writes' => self::strings(),
                    ]),
                ],
            ]),
            ResponseContract::DefinitionOfDone => StrictSchema::object([
                'criteria' => self::strings(),
            ]),
            ResponseContract::Json => throw new InvalidArgumentException(
                'The generic JSON response contract requires a response schema.',
            ),
            ResponseContract::Text => throw new InvalidArgumentException(
                'Text completions do not have a structured output schema.',
            ),
        };
    }

    /** @return array<string, mixed> */
    private static function strings(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }
}
