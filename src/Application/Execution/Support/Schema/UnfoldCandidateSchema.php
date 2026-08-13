<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Schema;

use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;

final readonly class UnfoldCandidateSchema
{
    /** @return array<string, mixed> */
    public function for(ArtifactType $artifact): array
    {
        return self::object([
            'artifact_type' => [
                'type' => 'string',
                'enum' => [$artifact->toString()],
            ],
            'title' => ['type' => 'string', 'maxLength' => 160],
            'summary' => ['type' => 'string', 'maxLength' => 500],
            'content' => ['type' => 'string'],
            'memory_delta' => self::object([
                'facts_added' => self::strings(),
                'decisions_added' => self::strings(),
                'loops_opened' => self::strings(),
                'loops_resolved' => self::strings(),
                'requirements_covered' => self::strings(),
                'requirements_violated' => self::strings(),
            ]),
        ]);
    }

    /** @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /** @return array{type: string, items: array{type: string}} */
    private static function strings(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }
}
