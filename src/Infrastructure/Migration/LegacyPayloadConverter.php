<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Migration;

use JsonException;
use Rick\Laravel\Application\Compilation\Interface\StepCodecBase;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use UnexpectedValueException;

final readonly class LegacyPayloadConverter
{
    private const array BUILT_IN_STEP_TYPES = [
        'resolve',
        'define_dod',
        'context',
        'generate',
        'unfold',
        'judge',
        'edit',
        'output_glue',
        'operation',
        'quality_gate',
        'grounded_verify',
        'parallel',
        'map',
        'join',
        'branch',
        'wait_for_input',
    ];

    /** @var array<string, StepCodecBase> */
    private array $customCodecs;

    /** @param iterable<StepCodecBase> $customCodecs */
    public function __construct(iterable $customCodecs = [])
    {
        $indexed = [];
        foreach ($customCodecs as $codec) {
            $type = $codec->type()->toString();
            if (isset($indexed[$type]) || $codec->version() < 1) {
                throw new UnexpectedValueException(
                    "Legacy custom step codec [{$type}] is duplicate or unversioned.",
                );
            }
            $indexed[$type] = $codec;
        }
        $this->customCodecs = $indexed;
    }

    public function run(string $payload): string
    {
        $data = $this->decode($payload);
        if (isset($data['run']) && ($data['schema_version'] ?? null) === 1) {
            return $payload;
        }

        $workflow = JsonInput::map($data['workflow'] ?? null, 'legacy workflow');
        $workflow['steps'] = array_map(
            fn (mixed $step): array => $this->step(JsonInput::map($step, 'legacy workflow step')),
            JsonInput::list($workflow['steps'] ?? null, 'legacy workflow steps'),
        );
        $dod = JsonInput::map($data['dod'] ?? [], 'legacy definition of done');
        $runningStep = JsonInput::nullableString($data['running_step'] ?? null, 'legacy run.running_step');
        $rawOutput = JsonInput::nullableString($data['raw_output'] ?? null, 'legacy run.raw_output');
        $aiOutput = JsonInput::nullableString($data['ai_output'] ?? null, 'legacy run.ai_output');
        $startedAt = JsonInput::nullableString($data['started_at'] ?? null, 'legacy run.started_at');

        return $this->encode([
            'schema_version' => 1,
            'run' => [
                'id' => JsonInput::string($data['id'] ?? null, 'legacy run.id'),
                'workflow' => $workflow,
                'input' => JsonInput::map($data['input'] ?? [], 'legacy run.input'),
                'status' => JsonInput::string($data['status'] ?? 'created', 'legacy run.status'),
                'position' => JsonInput::integer($data['position'] ?? 0, 'legacy run.position'),
                'version' => JsonInput::integer($data['version'] ?? 0, 'legacy run.version'),
                'running_step' => $runningStep,
                'task' => JsonInput::string($data['task'] ?? '', 'legacy run.task'),
                'dod' => self::dodValue($dod['value'] ?? 'auto'),
                'dod_automatic' => JsonInput::boolean($dod['automatic'] ?? false, 'legacy run.dod.automatic'),
                'contexts' => JsonInput::list($data['contexts'] ?? [], 'legacy run.contexts'),
                'current_candidates' => JsonInput::list($data['current_candidates'] ?? [], 'legacy run.current_candidates'),
                'accepted_candidates' => JsonInput::list($data['accepted_candidates'] ?? [], 'legacy run.accepted_candidates'),
                'decisions' => JsonInput::list($data['decisions'] ?? [], 'legacy run.decisions'),
                'step_states' => JsonInput::map($data['step_states'] ?? [], 'legacy run.step_states'),
                'raw_output' => $rawOutput,
                'ai_output' => $aiOutput,
                'calls_used' => JsonInput::integer($data['calls_used'] ?? 0, 'legacy run.calls_used'),
                'call_limit' => JsonInput::integer($data['call_limit'] ?? 60, 'legacy run.call_limit'),
                'artifacts' => JsonInput::map($data['artifacts'] ?? [], 'legacy run.artifacts'),
                'started_at' => $startedAt,
            ],
        ]);
    }

    public function request(string $payload): string
    {
        $data = $this->decode($payload);

        return isset($data['request']) && ($data['schema_version'] ?? null) === 1
            ? $payload
            : $this->encode(['schema_version' => 1, 'request' => $data]);
    }

    public function response(string $payload): string
    {
        $data = $this->decode($payload);

        if (isset($data['response']) && ($data['schema_version'] ?? null) === 1) {
            return $payload;
        }

        if (is_array($data['metrics'] ?? null)) {
            $metrics = JsonInput::map($data['metrics'], 'legacy response.metrics');
            if (array_key_exists('cost_usd_nanodollars', $metrics)) {
                $nanodollars = $metrics['cost_usd_nanodollars'];
                if (! is_int($nanodollars) || $nanodollars < 0) {
                    throw new UnexpectedValueException(
                        'Legacy completion cost must be non-negative nanodollars.',
                    );
                }
                $metrics['cost_usd'] = (new InvocationCost($nanodollars))->toUsdDecimal();
                unset($metrics['cost_usd_nanodollars']);
                $data['metrics'] = $metrics;
            }
        }

        return $this->encode(['schema_version' => 1, 'response' => $data]);
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function step(array $step): array
    {
        $step['schema_version'] = 1;
        if (isset($step['model_policy']) && ! isset($step['model_policy_id'])) {
            $step['model_policy_id'] = $step['model_policy'];
            unset($step['model_policy']);
        }
        if (($step['type'] ?? null) === 'resolve' && is_array($step['dod'] ?? null)) {
            $dod = JsonInput::map($step['dod'], 'legacy workflow step.dod');
            $step['dod'] = self::dodValue($dod['value'] ?? 'auto');
            $step['dod_automatic'] = JsonInput::boolean(
                $dod['automatic'] ?? false,
                'legacy workflow step.dod.automatic',
            );
        }

        $type = JsonInput::string($step['type'] ?? null, 'legacy workflow step.type');
        if (! in_array($type, self::BUILT_IN_STEP_TYPES, true)) {
            return $this->customStep($type, $step);
        }

        return $step;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function customStep(string $type, array $step): array
    {
        $codec = $this->customCodecs[$type] ?? throw new UnexpectedValueException(
            "No versioned codec is configured for legacy custom step [{$type}].",
        );
        if (isset($step['codec_version']) || isset($step['payload'])) {
            if (($step['codec_version'] ?? null) !== $codec->version()
                || ! is_array($step['payload'] ?? null)) {
                throw new UnexpectedValueException(
                    "Legacy custom step [{$type}] does not match its configured codec version.",
                );
            }

            $step['payload'] = JsonInput::map(
                $step['payload'],
                "legacy custom step.{$type}.payload",
            );

            return $step;
        }

        $payload = $step;
        unset($payload['schema_version'], $payload['id'], $payload['type']);

        return [
            'schema_version' => 1,
            'id' => JsonInput::string($step['id'] ?? null, 'legacy custom step.id'),
            'type' => $type,
            'codec_version' => $codec->version(),
            'payload' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    private function decode(string $payload): array
    {
        try {
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Legacy payload is not valid JSON.', previous: $error);
        }

        return JsonInput::map($data, 'legacy payload');
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return string|array<string, mixed> */
    private static function dodValue(mixed $value): string|array
    {
        return is_array($value)
            ? JsonInput::map($value, 'legacy definition of done.value')
            : JsonInput::string($value, 'legacy definition of done.value');
    }
}
