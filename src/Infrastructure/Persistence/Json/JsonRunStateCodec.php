<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use DateTimeImmutable;
use JsonException;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\CandidateDecision;
use Rick\Laravel\Domain\Run\ContextDocument;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunRecovery;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Run\WorkflowRunState;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use UnexpectedValueException;

final readonly class JsonRunStateCodec
{
    private const int VERSION = 1;

    public function __construct(private WorkflowStepCodec $steps) {}

    public function encode(WorkflowRun $run): string
    {
        $state = $run->state();

        return json_encode([
            'schema_version' => self::VERSION,
            'run' => [
                'id' => $state->id->toString(),
                'workflow' => [
                    'name' => $state->workflow->name,
                    'version' => $state->workflow->version,
                    'steps' => array_map($this->steps->encode(...), $state->workflow->steps),
                    'resource_budget' => $state->workflow->resourceBudget?->toArray(),
                ],
                'input' => $state->input->toArray(),
                'status' => $state->status->value,
                'position' => $state->position,
                'version' => $state->version,
                'running_step' => $state->runningStep?->toString(),
                'task' => $state->task,
                'dod' => $state->dod->value(),
                'dod_automatic' => $state->dod->isAutomatic(),
                'contexts' => array_map(static fn (ContextDocument $item): array => $item->toArray(), $state->contexts),
                'current_candidates' => array_map(static fn (Candidate $item): array => $item->toArray(), $state->currentCandidates),
                'accepted_candidates' => array_map(static fn (Candidate $item): array => $item->toArray(), $state->acceptedCandidates),
                'decisions' => array_map(static fn (CandidateDecision $item): array => [
                    'step_id' => $item->stepId->toString(),
                    'selected_candidate_id' => $item->selectedCandidateId->toString(),
                    'score' => $item->score,
                    'reason' => $item->reason,
                    'policy' => $item->policy,
                    'selection_seed' => $item->selectionSeed,
                ], $state->decisions),
                'step_states' => $state->stepStates,
                'raw_output' => $state->rawOutput,
                'ai_output' => $state->aiOutput,
                'calls_used' => $state->callsUsed,
                'call_limit' => $state->callLimit,
                'artifacts' => array_map(static fn (Artifact $item): array => $item->toArray(), $state->artifacts),
                'started_at' => $state->startedAt?->format(DATE_ATOM),
                'recovery' => $state->recovery?->toArray(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $payload): WorkflowRun
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted run payload is not valid JSON.', previous: $error);
        }
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Persisted run payload must be an object.');
        }
        $envelope = JsonInput::map($decoded, 'run envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported run payload schema version.');
        }
        $data = JsonInput::map($envelope['run'] ?? null, 'run');
        $workflow = JsonInput::map($data['workflow'] ?? null, 'run.workflow');
        $steps = array_map(
            fn (mixed $step) => $this->steps->decode(JsonInput::map(
                $step,
                'run.workflow.step',
            )),
            JsonInput::list($workflow['steps'] ?? null, 'run.workflow.steps'),
        );
        $resourceBudget = $workflow['resource_budget'] ?? null;
        $runningStep = JsonInput::nullableString($data['running_step'] ?? null, 'run.running_step');
        $startedAt = JsonInput::nullableString($data['started_at'] ?? null, 'run.started_at');
        $recovery = $data['recovery'] ?? null;

        return WorkflowRun::restore(new WorkflowRunState(
            RunId::fromString(JsonInput::string($data['id'] ?? null, 'run.id')),
            new CompiledWorkflow(
                JsonInput::string($workflow['name'] ?? null, 'run.workflow.name'),
                JsonInput::string($workflow['version'] ?? null, 'run.workflow.version'),
                $steps,
                $resourceBudget === null
                    ? null
                    : ResourceBudget::fromArray(JsonInput::map(
                        $resourceBudget,
                        'run.workflow.resource_budget',
                    )),
            ),
            new RunInput(JsonInput::map($data['input'] ?? null, 'run.input')),
            RunStatus::from(JsonInput::string($data['status'] ?? null, 'run.status')),
            JsonInput::integer($data['position'] ?? null, 'run.position'),
            JsonInput::integer($data['version'] ?? null, 'run.version'),
            $runningStep === null ? null : StepId::fromString($runningStep),
            JsonInput::string($data['task'] ?? null, 'run.task'),
            $this->dod($data),
            array_map(self::context(...), JsonInput::list($data['contexts'] ?? null, 'run.contexts')),
            array_map(self::candidate(...), JsonInput::list($data['current_candidates'] ?? null, 'run.current_candidates')),
            array_map(self::candidate(...), JsonInput::list($data['accepted_candidates'] ?? null, 'run.accepted_candidates')),
            array_map(self::decision(...), JsonInput::list($data['decisions'] ?? null, 'run.decisions')),
            self::stepStates($data['step_states'] ?? null),
            JsonInput::nullableString($data['raw_output'] ?? null, 'run.raw_output'),
            JsonInput::nullableString($data['ai_output'] ?? null, 'run.ai_output'),
            JsonInput::integer($data['calls_used'] ?? null, 'run.calls_used'),
            JsonInput::integer($data['call_limit'] ?? null, 'run.call_limit'),
            self::artifacts($data['artifacts'] ?? null),
            $startedAt === null ? null : self::date($startedAt, 'run.started_at'),
            $recovery === null ? null : self::recovery($recovery),
        ));
    }

    /** @param array<string, mixed> $data */
    private function dod(array $data): DefinitionOfDone
    {
        if (JsonInput::boolean($data['dod_automatic'] ?? null, 'run.dod_automatic')) {
            return DefinitionOfDone::automatic();
        }

        return is_array($data['dod'] ?? null)
            ? DefinitionOfDone::structured(JsonInput::map($data['dod'], 'run.dod'))
            : DefinitionOfDone::fromString(JsonInput::string($data['dod'] ?? null, 'run.dod'));
    }

    private static function context(mixed $data): ContextDocument
    {
        $data = JsonInput::map($data, 'run.context');

        return new ContextDocument(
            JsonInput::string($data['key'] ?? null, 'run.context.key'),
            JsonInput::string($data['content'] ?? null, 'run.context.content'),
            JsonInput::integer($data['original_characters'] ?? null, 'run.context.original_characters'),
            JsonInput::integer($data['included_characters'] ?? null, 'run.context.included_characters'),
            JsonInput::boolean($data['truncated'] ?? null, 'run.context.truncated'),
        );
    }

    private static function candidate(mixed $data): Candidate
    {
        $data = JsonInput::map($data, 'run.candidate');
        $seed = JsonInput::map($data['seed'] ?? null, 'run.candidate.seed');

        return new Candidate(
            CandidateId::fromString(JsonInput::string($data['id'] ?? null, 'run.candidate.id')),
            StepId::fromString(JsonInput::string($data['step_id'] ?? null, 'run.candidate.step_id')),
            ArtifactType::fromString(JsonInput::string($data['artifact'] ?? null, 'run.candidate.artifact')),
            JsonInput::string($data['title'] ?? null, 'run.candidate.title'),
            JsonInput::string($data['summary'] ?? null, 'run.candidate.summary'),
            JsonInput::map($data['payload'] ?? null, 'run.candidate.payload'),
            JsonInput::string($data['content'] ?? null, 'run.candidate.content'),
            JsonInput::string($seed['random_string'] ?? null, 'run.candidate.seed.random_string'),
            JsonInput::string($seed['interpretation'] ?? null, 'run.candidate.seed.interpretation'),
            JsonInput::map($data['metadata'] ?? null, 'run.candidate.metadata'),
        );
    }

    private static function decision(mixed $data): CandidateDecision
    {
        $data = JsonInput::map($data, 'run.decision');
        $score = $data['score'] ?? null;
        $selectionSeed = JsonInput::nullableString(
            $data['selection_seed'] ?? null,
            'run.decision.selection_seed',
        );

        return new CandidateDecision(
            StepId::fromString(JsonInput::string($data['step_id'] ?? null, 'run.decision.step_id')),
            CandidateId::fromString(JsonInput::string($data['selected_candidate_id'] ?? null, 'run.decision.selected_candidate_id')),
            $score === null ? null : JsonInput::number($score, 'run.decision.score'),
            JsonInput::string($data['reason'] ?? null, 'run.decision.reason'),
            JsonInput::string($data['policy'] ?? null, 'run.decision.policy'),
            $selectionSeed,
        );
    }

    private static function artifact(mixed $data): Artifact
    {
        $data = JsonInput::map($data, 'run.artifact');

        return new Artifact(
            JsonInput::string($data['key'] ?? null, 'run.artifact.key'),
            ArtifactType::fromString(JsonInput::string($data['type'] ?? null, 'run.artifact.type')),
            JsonInput::string($data['content'] ?? null, 'run.artifact.content'),
            JsonInput::valueArray($data['payload'] ?? null, 'run.artifact.payload'),
            JsonInput::map($data['metadata'] ?? null, 'run.artifact.metadata'),
            JsonInput::integer($data['version'] ?? null, 'run.artifact.version'),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private static function stepStates(mixed $value): array
    {
        $states = [];
        foreach (JsonInput::map($value, 'run.step_states') as $stepId => $state) {
            $states[$stepId] = JsonInput::map($state, "run.step_states.{$stepId}");
        }

        return $states;
    }

    /** @return array<string, Artifact> */
    private static function artifacts(mixed $value): array
    {
        $artifacts = [];
        foreach (JsonInput::map($value, 'run.artifacts') as $key => $artifact) {
            $artifacts[$key] = self::artifact($artifact);
        }

        return $artifacts;
    }

    private static function date(string $value, string $path): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $error) {
            throw new UnexpectedValueException("Persisted [{$path}] is not a valid timestamp.", previous: $error);
        }
    }

    private static function recovery(mixed $value): RunRecovery
    {
        $data = JsonInput::map($value, 'run.recovery');

        return new RunRecovery(
            RunId::fromString(JsonInput::string($data['parent_run_id'] ?? null, 'run.recovery.parent_run_id')),
            RunRecoveryAction::from(JsonInput::string($data['action'] ?? null, 'run.recovery.action')),
            StepId::fromString(JsonInput::string($data['step_id'] ?? null, 'run.recovery.step_id')),
        );
    }
}
