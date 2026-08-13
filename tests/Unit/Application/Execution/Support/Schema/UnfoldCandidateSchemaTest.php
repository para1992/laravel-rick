<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Schema;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Schema\UnfoldCandidateSchema;
use Rick\Laravel\Domain\Exception\JsonSchemaViolationException;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Infrastructure\Schema\JsonSchemaValidator;

final class UnfoldCandidateSchemaTest extends TestCase
{
    public function test_it_requires_the_declared_child_artifact_type(): void
    {
        $schema = (new UnfoldCandidateSchema)->for(ArtifactType::fromString('scene'));
        $candidate = [
            'artifact_type' => 'scene',
            'title' => 'A scene',
            'summary' => 'A concise continuity summary.',
            'content' => 'Narrative prose for the current beat.',
            'memory_delta' => [
                'facts_added' => [],
                'decisions_added' => [],
                'loops_opened' => [],
                'loops_resolved' => [],
                'requirements_covered' => [],
                'requirements_violated' => [],
            ],
        ];
        $validator = new JsonSchemaValidator;
        $validator->assert($schema, $candidate);

        $this->expectException(JsonSchemaViolationException::class);
        $validator->assert($schema, [...$candidate, 'artifact_type' => 'outline']);
    }
}
