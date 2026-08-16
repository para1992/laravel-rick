<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use Rick\Laravel\Application\Compilation\Interface\StepCodecBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\Step\AwaitHumanStep;
use Rick\Laravel\Domain\Workflow\Step\BranchStep;
use Rick\Laravel\Domain\Workflow\Step\ContextStep;
use Rick\Laravel\Domain\Workflow\Step\DefineDodStep;
use Rick\Laravel\Domain\Workflow\Step\EditStep;
use Rick\Laravel\Domain\Workflow\Step\GenerateStep;
use Rick\Laravel\Domain\Workflow\Step\GroundedVerifyStep;
use Rick\Laravel\Domain\Workflow\Step\JoinStep;
use Rick\Laravel\Domain\Workflow\Step\JudgeStep;
use Rick\Laravel\Domain\Workflow\Step\LlmOperationStep;
use Rick\Laravel\Domain\Workflow\Step\MapStep;
use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\Step\ParallelStep;
use Rick\Laravel\Domain\Workflow\Step\QualityGateStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\Step\UnfoldStep;
use Rick\Laravel\Domain\Workflow\Step\WaitForInputStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use UnexpectedValueException;

final readonly class WorkflowStepCodec
{
    private const int VERSION = 1;

    /** @var array<string, StepCodecBase> */
    private array $customCodecs;

    /** @param iterable<StepCodecBase> $customCodecs */
    public function __construct(iterable $customCodecs = [])
    {
        $indexed = [];
        foreach ($customCodecs as $codec) {
            $type = $codec->type()->toString();
            if (isset($indexed[$type])) {
                throw new UnexpectedValueException("Duplicate custom step codec [{$type}].");
            }
            if ($codec->version() < 1) {
                throw new UnexpectedValueException("Custom step codec [{$type}] must have a positive version.");
            }
            $indexed[$type] = $codec;
        }
        $this->customCodecs = $indexed;
    }

    /** @return array<string, mixed> */
    public function encode(StepBase $step): array
    {
        $base = [
            'schema_version' => self::VERSION,
            'id' => $step->id()->toString(),
            'type' => $step->type()->toString(),
        ];

        return $base + match (true) {
            $step instanceof ResolveStep => [
                'task' => $step->task,
                'dod' => $step->dod->value(),
                'dod_automatic' => $step->dod->isAutomatic(),
            ],
            $step instanceof RawPromptStep => [
                'prompt' => $step->prompt,
                'model_policy_id' => $step->modelPolicyId,
            ],
            $step instanceof DefineDodStep => ['model_policy_id' => $step->modelPolicyId],
            $step instanceof ContextStep => ['input_key' => $step->inputKey],
            $step instanceof GenerateStep => [
                'artifact' => $step->artifact->toString(),
                'candidate_count' => $step->candidateCount,
                'output_key' => $step->outputKey,
                'read_artifacts' => $step->readArtifacts,
                'model_policy_id' => $step->modelPolicyId,
                'minimum_successful' => $step->minimumSuccessful,
            ],
            $step instanceof JudgeStep => [
                'automatic' => $step->automatic,
                'model_policy_id' => $step->modelPolicyId,
            ],
            $step instanceof EditStep => ['mode' => $step->mode, 'model_policy_id' => $step->modelPolicyId],
            $step instanceof OutputGlueStep => ['artifact_key' => $step->artifactKey],
            $step instanceof UnfoldStep => [
                'source_artifact' => $step->sourceArtifact->toString(),
                'child_artifact' => $step->childArtifact->toString(),
                'candidate_count' => $step->candidateCount,
                'judge' => $step->judge,
                'max_units' => $step->maxUnits,
                'model_policy_id' => $step->modelPolicyId,
            ],
            $step instanceof LlmOperationStep => [
                'operation_id' => $step->operationId,
                'operation_version' => $step->operationVersion,
                'input_keys' => $step->inputKeys,
                'output_key' => $step->outputKey,
                'parameters' => $step->parameters,
            ],
            $step instanceof QualityGateStep => [
                'artifact_key' => $step->artifactKey,
                'rule_set_id' => $step->ruleSetId,
                'repair_policy_id' => $step->repairPolicyId,
                'repair_operation_id' => $step->repairOperationId,
                'repair_operation_version' => $step->repairOperationVersion,
                'max_repairs' => $step->maxRepairs,
                'output_key' => $step->outputKey,
            ],
            $step instanceof GroundedVerifyStep => [
                'artifact_key' => $step->artifactKey,
                'evidence_keys' => $step->evidenceKeys,
                'verification_operation_id' => $step->verificationOperationId,
                'verification_operation_version' => $step->verificationOperationVersion,
                'repair_operation_id' => $step->repairOperationId,
                'repair_operation_version' => $step->repairOperationVersion,
                'max_repairs' => $step->maxRepairs,
                'output_key' => $step->outputKey,
                'minimum_quote_characters' => $step->minimumQuoteCharacters,
            ],
            $step instanceof ParallelStep => [
                'calls' => array_map(static fn (OperationCall $call): array => $call->toArray(), $step->calls),
            ],
            $step instanceof MapStep => [
                'source_artifact' => $step->sourceArtifact,
                'source_path' => $step->sourcePath,
                'operation_id' => $step->operationId,
                'operation_version' => $step->operationVersion,
                'output_key' => $step->outputKey,
                'parameters' => $step->parameters,
                'max_items' => $step->maxItems,
                'include_source_artifact' => $step->includeSourceArtifact,
            ],
            $step instanceof JoinStep => [
                'input_keys' => $step->inputKeys,
                'output_key' => $step->outputKey,
                'mode' => $step->mode,
                'separator' => $step->separator,
            ],
            $step instanceof BranchStep => [
                'condition_artifact' => $step->conditionArtifact,
                'path' => $step->path,
                'operator' => $step->operator,
                'expected' => $step->expected,
                'when_true' => $step->whenTrue,
                'when_false' => $step->whenFalse,
                'output_key' => $step->outputKey,
            ],
            $step instanceof WaitForInputStep => [
                'input_key' => $step->inputKey,
                'prompt' => $step->prompt,
                'artifact_type' => $step->artifactType->toString(),
                'schema' => $step->schema,
            ],
            $step instanceof AwaitHumanStep => [
                'input_key' => $step->inputKey,
                'prompt' => $step->prompt,
                'artifact_type' => $step->artifactType->toString(),
                'schema' => $step->schema,
            ],
            default => $this->encodeCustom($step),
        };
    }

    /** @param array<string, mixed> $data */
    public function decode(array $data): StepBase
    {
        if (($data['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported workflow step schema version.');
        }
        $id = StepId::fromString(JsonInput::string($data['id'] ?? null, 'workflow step.id'));
        $type = JsonInput::string($data['type'] ?? null, 'workflow step.type');

        return match ($type) {
            'resolve' => new ResolveStep(
                $id,
                JsonInput::string($data['task'] ?? null, 'workflow step.task'),
                $this->dod($data),
            ),
            'raw_prompt' => new RawPromptStep(
                $id,
                JsonInput::string($data['prompt'] ?? null, 'workflow step.prompt'),
                JsonInput::string($data['model_policy_id'] ?? null, 'workflow step.model_policy_id'),
            ),
            'define_dod' => new DefineDodStep(
                $id,
                JsonInput::string($data['model_policy_id'] ?? null, 'workflow step.model_policy_id'),
            ),
            'context' => new ContextStep(
                $id,
                JsonInput::string($data['input_key'] ?? null, 'workflow step.input_key'),
            ),
            'generate' => new GenerateStep(
                $id,
                ArtifactType::fromString(JsonInput::string($data['artifact'] ?? null, 'workflow step.artifact')),
                JsonInput::integer($data['candidate_count'] ?? null, 'workflow step.candidate_count'),
                JsonInput::nullableString($data['output_key'] ?? null, 'workflow step.output_key'),
                JsonInput::strings($data['read_artifacts'] ?? null, 'workflow step.read_artifacts'),
                JsonInput::string($data['model_policy_id'] ?? null, 'workflow step.model_policy_id'),
                JsonInput::nullableInteger(
                    $data['minimum_successful'] ?? null,
                    'workflow step.minimum_successful',
                ),
            ),
            'judge' => new JudgeStep(
                $id,
                array_key_exists('automatic', $data)
                    ? JsonInput::boolean($data['automatic'], 'workflow step.automatic')
                    : false,
                array_key_exists('model_policy_id', $data)
                    ? JsonInput::string($data['model_policy_id'], 'workflow step.model_policy_id')
                    : 'quality',
            ),
            'edit' => new EditStep(
                $id,
                JsonInput::string($data['mode'] ?? null, 'workflow step.mode'),
                JsonInput::string($data['model_policy_id'] ?? null, 'workflow step.model_policy_id'),
            ),
            'output_glue' => new OutputGlueStep(
                $id,
                JsonInput::nullableString($data['artifact_key'] ?? null, 'workflow step.artifact_key'),
            ),
            'unfold' => new UnfoldStep(
                $id,
                ArtifactType::fromString(JsonInput::string($data['source_artifact'] ?? null, 'workflow step.source_artifact')),
                ArtifactType::fromString(JsonInput::string($data['child_artifact'] ?? null, 'workflow step.child_artifact')),
                JsonInput::integer($data['candidate_count'] ?? null, 'workflow step.candidate_count'),
                JsonInput::boolean($data['judge'] ?? null, 'workflow step.judge'),
                JsonInput::integer($data['max_units'] ?? null, 'workflow step.max_units'),
                JsonInput::string($data['model_policy_id'] ?? null, 'workflow step.model_policy_id'),
            ),
            'operation' => new LlmOperationStep(
                $id,
                JsonInput::string($data['operation_id'] ?? null, 'workflow step.operation_id'),
                JsonInput::nullableString($data['operation_version'] ?? null, 'workflow step.operation_version'),
                JsonInput::strings($data['input_keys'] ?? null, 'workflow step.input_keys'),
                JsonInput::string($data['output_key'] ?? null, 'workflow step.output_key'),
                JsonInput::map($data['parameters'] ?? null, 'workflow step.parameters'),
            ),
            'quality_gate' => new QualityGateStep(
                $id,
                JsonInput::string($data['artifact_key'] ?? null, 'workflow step.artifact_key'),
                JsonInput::string($data['rule_set_id'] ?? null, 'workflow step.rule_set_id'),
                JsonInput::string($data['repair_policy_id'] ?? null, 'workflow step.repair_policy_id'),
                JsonInput::nullableString($data['repair_operation_id'] ?? null, 'workflow step.repair_operation_id'),
                JsonInput::nullableString($data['repair_operation_version'] ?? null, 'workflow step.repair_operation_version'),
                JsonInput::integer($data['max_repairs'] ?? null, 'workflow step.max_repairs'),
                JsonInput::nullableString($data['output_key'] ?? null, 'workflow step.output_key'),
            ),
            'grounded_verify' => new GroundedVerifyStep(
                $id,
                JsonInput::string($data['artifact_key'] ?? null, 'workflow step.artifact_key'),
                $this->nonEmptyStrings(
                    $data['evidence_keys'] ?? null,
                    'Grounded verification requires evidence keys.',
                ),
                JsonInput::string($data['verification_operation_id'] ?? null, 'workflow step.verification_operation_id'),
                JsonInput::nullableString($data['verification_operation_version'] ?? null, 'workflow step.verification_operation_version'),
                JsonInput::nullableString($data['repair_operation_id'] ?? null, 'workflow step.repair_operation_id'),
                JsonInput::nullableString($data['repair_operation_version'] ?? null, 'workflow step.repair_operation_version'),
                JsonInput::integer($data['max_repairs'] ?? null, 'workflow step.max_repairs'),
                JsonInput::nullableString($data['output_key'] ?? null, 'workflow step.output_key'),
                JsonInput::integer($data['minimum_quote_characters'] ?? null, 'workflow step.minimum_quote_characters'),
            ),
            'parallel' => new ParallelStep($id, $this->operationCalls($data['calls'] ?? [])),
            'map' => new MapStep(
                $id,
                JsonInput::string($data['source_artifact'] ?? null, 'workflow step.source_artifact'),
                JsonInput::string($data['source_path'] ?? null, 'workflow step.source_path'),
                JsonInput::string($data['operation_id'] ?? null, 'workflow step.operation_id'),
                JsonInput::nullableString($data['operation_version'] ?? null, 'workflow step.operation_version'),
                JsonInput::string($data['output_key'] ?? null, 'workflow step.output_key'),
                JsonInput::map($data['parameters'] ?? null, 'workflow step.parameters'),
                JsonInput::integer($data['max_items'] ?? null, 'workflow step.max_items'),
                JsonInput::boolean($data['include_source_artifact'] ?? null, 'workflow step.include_source_artifact'),
            ),
            'join' => new JoinStep(
                $id,
                $this->nonEmptyStrings($data['input_keys'] ?? null, 'Join requires input keys.'),
                JsonInput::string($data['output_key'] ?? null, 'workflow step.output_key'),
                JsonInput::string($data['mode'] ?? null, 'workflow step.mode'),
                JsonInput::string($data['separator'] ?? null, 'workflow step.separator'),
            ),
            'branch' => new BranchStep(
                $id,
                JsonInput::string($data['condition_artifact'] ?? null, 'workflow step.condition_artifact'),
                JsonInput::string($data['path'] ?? null, 'workflow step.path'),
                JsonInput::string($data['operator'] ?? null, 'workflow step.operator'),
                $data['expected'] ?? null,
                JsonInput::string($data['when_true'] ?? null, 'workflow step.when_true'),
                JsonInput::string($data['when_false'] ?? null, 'workflow step.when_false'),
                JsonInput::string($data['output_key'] ?? null, 'workflow step.output_key'),
            ),
            'wait_for_input' => new WaitForInputStep(
                $id,
                JsonInput::string($data['input_key'] ?? null, 'workflow step.input_key'),
                JsonInput::string($data['prompt'] ?? null, 'workflow step.prompt'),
                ArtifactType::fromString(JsonInput::string($data['artifact_type'] ?? null, 'workflow step.artifact_type')),
                ($data['schema'] ?? null) === null
                    ? null
                    : JsonInput::map($data['schema'], 'workflow step.schema'),
            ),
            'await_human' => new AwaitHumanStep(
                $id,
                JsonInput::string($data['input_key'] ?? null, 'workflow step.input_key'),
                JsonInput::string($data['prompt'] ?? null, 'workflow step.prompt'),
                ArtifactType::fromString(JsonInput::string($data['artifact_type'] ?? null, 'workflow step.artifact_type')),
                ($data['schema'] ?? null) === null
                    ? null
                    : JsonInput::map($data['schema'], 'workflow step.schema'),
            ),
            default => $this->decodeCustom($id, $data),
        };
    }

    /** @return array<string, mixed> */
    private function encodeCustom(StepBase $step): array
    {
        $type = $step->type()->toString();
        $codec = $this->customCodecs[$type] ?? throw new UnexpectedValueException(
            "No codec is configured for custom workflow step [{$type}].",
        );

        return [
            'codec_version' => $codec->version(),
            'payload' => $codec->encode($step),
        ];
    }

    /** @param array<string, mixed> $data */
    private function decodeCustom(StepId $id, array $data): StepBase
    {
        $type = JsonInput::string($data['type'] ?? null, 'custom workflow step.type');
        $codec = $this->customCodecs[$type] ?? throw new UnexpectedValueException(
            "No codec is configured for persisted custom workflow step [{$type}].",
        );
        if (($data['codec_version'] ?? null) !== $codec->version()) {
            throw new UnexpectedValueException("Unsupported codec version for custom step [{$type}].");
        }
        $payload = JsonInput::map($data['payload'] ?? null, "custom workflow step.{$type}.payload");
        $step = $codec->decode($id, $payload);
        if ($step->type()->toString() !== $type) {
            throw new UnexpectedValueException("Custom step codec [{$type}] decoded a different step type.");
        }

        return $step;
    }

    /** @param array<string, mixed> $data */
    private function dod(array $data): DefinitionOfDone
    {
        if (JsonInput::boolean($data['dod_automatic'] ?? null, 'workflow step.dod_automatic')) {
            return DefinitionOfDone::automatic();
        }

        $value = $data['dod'] ?? null;

        return is_array($value)
            ? DefinitionOfDone::structured(JsonInput::map($value, 'workflow step.dod'))
            : DefinitionOfDone::fromString(JsonInput::string($value, 'workflow step.dod'));
    }

    /** @return non-empty-list<string> */
    private function nonEmptyStrings(mixed $value, string $message): array
    {
        $items = JsonInput::strings($value, 'workflow step string list');
        if ($items === []) {
            throw new UnexpectedValueException($message);
        }

        return $items;
    }

    /** @return non-empty-list<OperationCall> */
    private function operationCalls(mixed $value): array
    {
        $values = JsonInput::list($value, 'parallel step calls');
        if ($values === []) {
            throw new UnexpectedValueException('Parallel step requires operation calls.');
        }

        return array_map(
            static fn (mixed $call): OperationCall => OperationCall::fromArray(
                JsonInput::map($call, 'parallel step call'),
            ),
            $values,
        );
    }
}
