<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use InvalidArgumentException;
use JsonSerializable;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;

final readonly class Artifact implements JsonSerializable
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $key,
        public ArtifactType $type,
        public string $content,
        public array $payload = [],
        public array $metadata = [],
        public int $version = 1,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid artifact key [{$key}].");
        }

        if ($version < 1) {
            throw new InvalidArgumentException('Artifact version must be at least 1.');
        }
    }

    public static function fromCandidate(Candidate $candidate, ?string $key = null): self
    {
        $resolvedKey = $key
            ?? (is_string($candidate->metadata['output_key'] ?? null)
                ? $candidate->metadata['output_key']
                : $candidate->artifact->toString());

        return new self(
            $resolvedKey,
            $candidate->artifact,
            $candidate->content,
            $candidate->payload,
            [
                'candidate_id' => $candidate->id->toString(),
                'step_id' => $candidate->stepId->toString(),
                'title' => $candidate->title,
                'summary' => $candidate->summary,
            ] + $candidate->metadata,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'key' => $this->key,
            'type' => $this->type->toString(),
            'content' => $this->content,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'version' => $this->version,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
