<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;

final readonly class StepOutcome
{
    /**
     * @param  list<Candidate>  $candidates
     * @param  list<ContextDocument>  $contexts
     * @param  list<Artifact>  $artifacts
     * @param  array<string, mixed>|null  $stepState
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public ?string $task = null,
        public array $candidates = [],
        public array $contexts = [],
        public ?CandidateDecision $decision = null,
        public ?DefinitionOfDone $dod = null,
        public ?string $rawOutput = null,
        public ?string $aiOutput = null,
        public ?array $stepState = null,
        public bool $continuesStep = false,
        public array $metadata = [],
        public array $artifacts = [],
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function resolved(string $task, DefinitionOfDone $dod, array $metadata = []): self
    {
        return new self(task: $task, dod: $dod, metadata: $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function dodDefined(DefinitionOfDone $dod, array $metadata = []): self
    {
        return new self(dod: $dod, metadata: $metadata);
    }

    /**
     * @param  list<ContextDocument>  $contexts
     * @param  array<string, mixed>  $metadata
     * @param  list<Artifact>  $artifacts
     */
    public static function contextsAdded(array $contexts, array $metadata = [], array $artifacts = []): self
    {
        return new self(contexts: $contexts, metadata: $metadata, artifacts: $artifacts);
    }

    /**
     * @param  list<Candidate>  $candidates
     * @param  array<string, mixed>  $metadata
     */
    public static function generated(array $candidates, array $metadata = []): self
    {
        return new self(candidates: $candidates, metadata: $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function judged(CandidateDecision $decision, array $metadata = []): self
    {
        return new self(decision: $decision, metadata: $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function outputProduced(string $rawOutput, array $metadata = []): self
    {
        return new self(rawOutput: $rawOutput, metadata: $metadata);
    }

    /**
     * @param  list<Artifact>  $artifacts
     * @param  array<string, mixed>  $metadata
     */
    public static function artifactsProduced(array $artifacts, array $metadata = []): self
    {
        return new self(artifacts: $artifacts, metadata: $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function edited(string $rawOutput, string $aiOutput, array $metadata = []): self
    {
        return new self(rawOutput: $rawOutput, aiOutput: $aiOutput, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $stepState
     * @param  list<Candidate>  $candidates
     * @param  array<string, mixed>  $metadata
     * @param  list<Artifact>  $artifacts
     */
    public static function continuation(
        array $stepState,
        array $candidates = [],
        ?CandidateDecision $decision = null,
        ?string $rawOutput = null,
        array $metadata = [],
        array $artifacts = [],
    ): self {
        return new self(
            candidates: $candidates,
            decision: $decision,
            rawOutput: $rawOutput,
            stepState: $stepState,
            continuesStep: true,
            metadata: $metadata,
            artifacts: $artifacts,
        );
    }

    /**
     * @param  array<string, mixed>  $stepState
     * @param  list<Candidate>  $candidates
     * @param  array<string, mixed>  $metadata
     * @param  list<Artifact>  $artifacts
     */
    public static function completion(
        array $stepState,
        array $candidates = [],
        ?CandidateDecision $decision = null,
        ?string $rawOutput = null,
        array $metadata = [],
        array $artifacts = [],
    ): self {
        return new self(
            candidates: $candidates,
            decision: $decision,
            rawOutput: $rawOutput,
            stepState: $stepState,
            metadata: $metadata,
            artifacts: $artifacts,
        );
    }
}
