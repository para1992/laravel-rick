<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Compilation\Interface\StepCodecBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Infrastructure\Migration\LegacyPayloadConverter;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Infrastructure\Persistence\Json\WorkflowStepCodec;
use UnexpectedValueException;

final class LegacyPayloadConverterTest extends TestCase
{
    public function test_legacy_run_and_completion_payloads_are_wrapped_in_current_versioned_shapes(): void
    {
        $converter = new LegacyPayloadConverter;
        $legacy = json_encode([
            'schema_version' => 2,
            'id' => 'legacy-run',
            'workflow' => [
                'name' => 'legacy',
                'version' => '1.0.0',
                'steps' => [[
                    'id' => '001_resolve',
                    'type' => 'resolve',
                    'task' => 'Migrate',
                    'dod' => ['automatic' => false, 'value' => 'Migrated'],
                ]],
                'resource_budget' => null,
            ],
            'input' => [],
            'status' => 'running',
            'position' => 0,
            'version' => 1,
            'running_step' => '001_resolve',
            'task' => 'Migrate',
            'dod' => ['automatic' => false, 'value' => 'Migrated'],
            'contexts' => [],
            'current_candidates' => [],
            'accepted_candidates' => [],
            'decisions' => [],
            'calls_used' => 0,
            'call_limit' => 60,
        ], JSON_THROW_ON_ERROR);

        $run = self::decode($converter->run($legacy), 'run');
        $state = JsonInput::map($run['run'] ?? null, 'run.run');
        $workflow = JsonInput::map($state['workflow'] ?? null, 'run.run.workflow');
        $steps = JsonInput::list($workflow['steps'] ?? null, 'run.run.workflow.steps');
        $step = JsonInput::map($steps[0] ?? null, 'run.run.workflow.steps.0');
        self::assertSame(1, $run['schema_version']);
        self::assertSame('legacy-run', $state['id']);
        self::assertSame(1, $step['schema_version']);
        self::assertSame('Migrated', $step['dod']);

        $request = self::decode($converter->request('{"purpose":"legacy"}'), 'request');
        $response = self::decode(
            $converter->response('{"text":"done","metrics":{"cost_usd_nanodollars":9250000}}'),
            'response',
        );
        $requestBody = JsonInput::map($request['request'] ?? null, 'request.request');
        $responseBody = JsonInput::map($response['response'] ?? null, 'response.response');
        $metrics = JsonInput::map($responseBody['metrics'] ?? null, 'response.response.metrics');
        self::assertSame('legacy', $requestBody['purpose']);
        self::assertSame('done', $responseBody['text']);
        self::assertSame('0.00925', $metrics['cost_usd']);
        self::assertArrayNotHasKey(
            'cost_usd_nanodollars',
            $metrics,
        );
    }

    public function test_configured_custom_step_is_wrapped_with_its_explicit_codec_version(): void
    {
        $codec = new LegacyPublishCodec;
        $converter = new LegacyPayloadConverter([$codec]);
        $legacy = json_encode([
            'schema_version' => 2,
            'id' => 'custom-run',
            'workflow' => [
                'name' => 'custom',
                'version' => '1.0.0',
                'steps' => [[
                    'id' => '001_publish',
                    'type' => 'acme.publish',
                    'channel' => 'release',
                ]],
            ],
            'input' => [],
            'status' => 'created',
            'position' => 0,
            'version' => 0,
            'task' => '',
            'dod' => ['automatic' => true, 'value' => 'auto'],
        ], JSON_THROW_ON_ERROR);

        $converted = self::decode($converter->run($legacy), 'run');
        $state = JsonInput::map($converted['run'] ?? null, 'run.run');
        $workflow = JsonInput::map($state['workflow'] ?? null, 'run.run.workflow');
        $steps = JsonInput::list($workflow['steps'] ?? null, 'run.run.workflow.steps');
        $step = JsonInput::map($steps[0] ?? null, 'run.run.workflow.steps.0');

        self::assertSame(3, $step['codec_version']);
        self::assertSame(['channel' => 'release'], $step['payload']);
        $decoded = (new WorkflowStepCodec([$codec]))->decode($step);
        self::assertInstanceOf(LegacyPublishStep::class, $decoded);
        self::assertSame('release', $decoded->channel);
    }

    public function test_current_payloads_are_returned_byte_for_byte(): void
    {
        $converter = new LegacyPayloadConverter;
        $run = '{"schema_version":1,"run":[]}';
        $request = '{"schema_version":1,"request":[]}';
        $response = '{"schema_version":1,"response":[]}';
        self::assertSame($run, $converter->run($run));
        self::assertSame($request, $converter->request($request));
        self::assertSame($response, $converter->response($response));
    }

    public function test_step_aliases_structured_dod_and_versioned_custom_payload_are_preserved(): void
    {
        $converter = new LegacyPayloadConverter([new LegacyPublishCodec]);
        $legacy = json_encode([
            'id' => 'legacy-run',
            'workflow' => [
                'name' => 'legacy',
                'version' => '1.0.0',
                'steps' => [
                    [
                        'id' => 'generate',
                        'type' => 'generate',
                        'artifact' => 'draft',
                        'candidate_count' => 1,
                        'model_policy' => 'quality',
                    ],
                    [
                        'id' => 'publish',
                        'type' => 'acme.publish',
                        'codec_version' => 3,
                        'payload' => ['channel' => 'release'],
                    ],
                ],
            ],
            'input' => [],
            'dod' => ['automatic' => false, 'value' => ['criteria' => ['done']]],
        ], JSON_THROW_ON_ERROR);

        $converted = self::decode($converter->run($legacy), 'run');
        $state = JsonInput::map($converted['run'], 'run.run');
        $workflow = JsonInput::map($state['workflow'], 'run.run.workflow');
        $steps = JsonInput::list($workflow['steps'], 'run.run.workflow.steps');
        $generate = JsonInput::map($steps[0] ?? null, 'run.run.workflow.steps.0');
        $publish = JsonInput::map($steps[1] ?? null, 'run.run.workflow.steps.1');
        self::assertSame('quality', $generate['model_policy_id']);
        self::assertArrayNotHasKey('model_policy', $generate);
        self::assertSame(['channel' => 'release'], $publish['payload']);
        self::assertSame(['criteria' => ['done']], $state['dod']);
    }

    public function test_converter_rejects_invalid_json_costs_codecs_and_custom_payloads(): void
    {
        $codec = new LegacyPublishCodec;
        $unversioned = self::createStub(StepCodecBase::class);
        $unversioned->method('type')->willReturn(StepType::fromString('acme.invalid'));
        $unversioned->method('version')->willReturn(0);
        $operations = [
            static fn () => new LegacyPayloadConverter([$unversioned]),
            static fn () => new LegacyPayloadConverter([$codec, $codec]),
            static fn () => (new LegacyPayloadConverter)->request('{'),
            static fn () => (new LegacyPayloadConverter)->response(
                '{"metrics":{"cost_usd_nanodollars":-1}}',
            ),
            static fn () => (new LegacyPayloadConverter)->response(
                '{"metrics":{"cost_usd_nanodollars":"1"}}',
            ),
            static fn () => (new LegacyPayloadConverter)->run(self::legacyRunWithStep([
                'id' => 'custom',
                'type' => 'acme.missing',
            ])),
            static fn () => (new LegacyPayloadConverter([$codec]))->run(self::legacyRunWithStep([
                'id' => 'custom',
                'type' => 'acme.publish',
                'codec_version' => 2,
                'payload' => [],
            ])),
            static fn () => (new LegacyPayloadConverter([$codec]))->run(self::legacyRunWithStep([
                'id' => 'custom',
                'type' => 'acme.publish',
                'codec_version' => 3,
                'payload' => 'invalid',
            ])),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Invalid legacy payload was accepted.');
            } catch (UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed> $step */
    private static function legacyRunWithStep(array $step): string
    {
        return json_encode([
            'id' => 'legacy-run',
            'workflow' => [
                'name' => 'legacy',
                'version' => '1.0.0',
                'steps' => [$step],
            ],
            'input' => [],
            'dod' => ['automatic' => true, 'value' => 'auto'],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function decode(string $payload, string $path): array
    {
        return JsonInput::map(
            json_decode($payload, true, flags: JSON_THROW_ON_ERROR),
            $path,
        );
    }
}

final readonly class LegacyPublishCodec implements StepCodecBase
{
    public function type(): StepType
    {
        return StepType::fromString('acme.publish');
    }

    public function version(): int
    {
        return 3;
    }

    public function encode(StepBase $step): array
    {
        return $step instanceof LegacyPublishStep
            ? ['channel' => $step->channel]
            : throw new UnexpectedValueException('Unexpected custom step.');
    }

    public function decode(StepId $id, array $payload): StepBase
    {
        return new LegacyPublishStep(
            $id,
            JsonInput::string($payload['channel'] ?? null, 'legacy_step.channel'),
        );
    }
}

final readonly class LegacyPublishStep implements StepBase
{
    public function __construct(
        private StepId $id,
        public string $channel,
    ) {}

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::fromString('acme.publish');
    }
}
