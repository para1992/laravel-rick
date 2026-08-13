<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Persistence\Json;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Compilation\Interface\StepCodecBase;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\Step\WaitForInputStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Infrastructure\Persistence\Json\WorkflowStepCodec;
use UnexpectedValueException;

final class WorkflowStepCodecTest extends TestCase
{
    public function test_raw_prompt_round_trips_through_the_versioned_codec(): void
    {
        $codec = new WorkflowStepCodec;
        $step = new RawPromptStep(
            StepId::fromString('001_raw_prompt'),
            '  Keep this exact prompt.  ',
            'quality',
        );

        $encoded = $codec->encode($step);

        self::assertSame([
            'schema_version' => 1,
            'id' => '001_raw_prompt',
            'type' => 'raw_prompt',
            'prompt' => '  Keep this exact prompt.  ',
            'model_policy_id' => 'quality',
        ], $encoded);
        self::assertEquals($step, $codec->decode($encoded));
    }

    public function test_decode_rejects_future_schema_and_empty_required_collections(): void
    {
        $codec = new WorkflowStepCodec;
        $payloads = [
            ['schema_version' => 2, 'id' => 'step', 'type' => 'raw_prompt'],
            [
                'schema_version' => 1,
                'id' => 'join',
                'type' => 'join',
                'sources' => [],
                'output_key' => 'joined',
                'separator' => "\n",
            ],
            [
                'schema_version' => 1,
                'id' => 'parallel',
                'type' => 'parallel',
                'calls' => [],
            ],
        ];

        foreach ($payloads as $payload) {
            try {
                $codec->decode($payload);
                self::fail('Invalid workflow step payload was accepted.');
            } catch (UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_decode_supports_structured_dod_and_nullable_input_schema(): void
    {
        $codec = new WorkflowStepCodec;
        $resolve = $codec->decode([
            'schema_version' => 1,
            'id' => 'resolve',
            'type' => 'resolve',
            'task' => 'Task',
            'dod_automatic' => false,
            'dod' => ['criteria' => ['safe']],
        ]);
        self::assertInstanceOf(ResolveStep::class, $resolve);
        self::assertSame(['criteria' => ['safe']], $resolve->dod->value());

        $input = $codec->decode([
            'schema_version' => 1,
            'id' => 'input',
            'type' => 'wait_for_input',
            'input_key' => 'approval',
            'prompt' => 'Approve?',
            'artifact_type' => 'approval',
            'schema' => null,
        ]);
        self::assertInstanceOf(WaitForInputStep::class, $input);
        self::assertNull($input->schema);
    }

    public function test_custom_codec_registration_and_decode_contract_fail_closed(): void
    {
        $type = StepType::fromString('custom');
        $invalidVersion = self::createStub(StepCodecBase::class);
        $invalidVersion->method('type')->willReturn($type);
        $invalidVersion->method('version')->willReturn(0);

        try {
            new WorkflowStepCodec([$invalidVersion]);
            self::fail('A non-positive custom codec version was accepted.');
        } catch (UnexpectedValueException) {
            self::addToAssertionCount(1);
        }

        $codec = self::createStub(StepCodecBase::class);
        $codec->method('type')->willReturn($type);
        $codec->method('version')->willReturn(1);
        $codec->method('decode')->willReturn(new RawPromptStep(
            StepId::fromString('custom'),
            'Prompt',
        ));

        try {
            new WorkflowStepCodec([$codec, $codec]);
            self::fail('A duplicate custom codec was accepted.');
        } catch (UnexpectedValueException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('decoded a different step type');
        (new WorkflowStepCodec([$codec]))->decode([
            'schema_version' => 1,
            'id' => 'custom',
            'type' => 'custom',
            'codec_version' => 1,
            'payload' => [],
        ]);
    }
}
