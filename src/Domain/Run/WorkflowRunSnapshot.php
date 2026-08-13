<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use DateTimeImmutable;
use JsonSerializable;
use OutOfBoundsException;
use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;

final readonly class WorkflowRunSnapshot implements JsonSerializable
{
    /**
     * @param  list<ContextDocument>  $contexts
     * @param  list<Candidate>  $currentCandidates
     * @param  list<Candidate>  $acceptedCandidates
     * @param  list<CandidateDecision>  $decisions
     * @param  array<string, array<string, mixed>>  $stepStates
     * @param  array<string, Artifact>  $artifacts
     */
    public function __construct(
        public RunId $id,
        public RunStatus $status,
        public int $version,
        public RunInput $input,
        public string $task,
        public DefinitionOfDone $dod,
        public array $contexts,
        public array $currentCandidates,
        public array $acceptedCandidates,
        public array $decisions,
        public array $stepStates,
        public ?string $rawOutput,
        public ?string $aiOutput,
        public int $callsUsed,
        public int $callLimit,
        public array $artifacts = [],
        public ?ResourceBudget $resourceBudget = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?RunRecovery $recovery = null,
    ) {}

    public function output(): string
    {
        return $this->aiOutput ?? $this->rawOutput ?? '';
    }

    /** @return array<string, mixed> */
    public function stepState(string $stepId): array
    {
        return $this->stepStates[$stepId] ?? [];
    }

    public function artifact(string $key): Artifact
    {
        return $this->artifacts[$key]
            ?? throw new OutOfBoundsException("Artifact [{$key}] is not available.");
    }

    public function hasArtifact(string $key): bool
    {
        return isset($this->artifacts[$key]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'schema_version' => 1,
            'id' => $this->id->toString(),
            'status' => $this->status->value,
            'version' => $this->version,
            'input' => $this->input->toArray(),
            'task' => $this->task,
            'definition_of_done' => [
                'automatic' => $this->dod->isAutomatic(),
                'value' => $this->dod->value(),
            ],
            'contexts' => array_map(
                static fn (ContextDocument $context): array => $context->toArray(),
                $this->contexts,
            ),
            'current_candidates' => array_map(
                static fn (Candidate $candidate): array => $candidate->toArray(),
                $this->currentCandidates,
            ),
            'accepted_candidates' => array_map(
                static fn (Candidate $candidate): array => $candidate->toArray(),
                $this->acceptedCandidates,
            ),
            'decisions' => array_map(
                static fn (CandidateDecision $decision): array => $decision->toArray(),
                $this->decisions,
            ),
            'step_states' => $this->stepStates,
            'raw_output' => $this->rawOutput,
            'ai_output' => $this->aiOutput,
            'output' => $this->output(),
            'calls_used' => $this->callsUsed,
            'call_limit' => $this->callLimit,
            'artifacts' => array_map(
                static fn (Artifact $artifact): array => $artifact->toArray(),
                $this->artifacts,
            ),
            'resource_budget' => $this->resourceBudget?->toArray(),
            'started_at' => $this->startedAt?->format(DATE_ATOM),
        ];

        if ($this->recovery !== null) {
            $data['recovery'] = $this->recovery->toArray();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
