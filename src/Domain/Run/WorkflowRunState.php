<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use DateTimeImmutable;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class WorkflowRunState
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
        public CompiledWorkflow $workflow,
        public RunInput $input,
        public RunStatus $status,
        public int $position,
        public int $version,
        public ?StepId $runningStep,
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
        public ?DateTimeImmutable $startedAt = null,
        public ?RunRecovery $recovery = null,
    ) {}
}
