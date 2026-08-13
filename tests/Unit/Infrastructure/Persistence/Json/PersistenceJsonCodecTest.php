<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Persistence\Json;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Event\CandidateReviewRequested;
use Rick\Laravel\Domain\Event\ExternalInputRequested;
use Rick\Laravel\Domain\Event\InvocationRecoveryRequired;
use Rick\Laravel\Domain\Event\LlmCallReserved;
use Rick\Laravel\Domain\Event\MemoryCommitted;
use Rick\Laravel\Domain\Event\StepCompleted;
use Rick\Laravel\Domain\Event\StepContinued;
use Rick\Laravel\Domain\Event\StepDegraded;
use Rick\Laravel\Domain\Event\StepFailed;
use Rick\Laravel\Domain\Event\StepStarted;
use Rick\Laravel\Domain\Event\UsageRecorded;
use Rick\Laravel\Domain\Event\WorkflowCompleted;
use Rick\Laravel\Domain\Event\WorkflowCreated;
use Rick\Laravel\Domain\Event\WorkflowRecoveryStarted;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseStage;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Llm\ValueObject\TextResponsePolicy;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Infrastructure\Persistence\Json\AttemptMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionRequestCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionResponseCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\DomainEventCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Infrastructure\Persistence\Json\StructuredResponseDiagnosticCodec;
use UnexpectedValueException;

final class PersistenceJsonCodecTest extends TestCase
{
    public function test_completion_request_round_trips_every_field_exactly(): void
    {
        $request = new CompletionRequest(
            [new Message('system', 'S/ü'), new Message('user', 'U')],
            ResponseContract::Json,
            'purpose',
            'quality',
            ['temperature' => 0.25],
            new TextResponsePolicy(7, ['No:', 'Stop'], true),
            ['candidate_index' => 2],
            ['type' => 'object', 'required' => ['answer']],
            2,
        );
        $codec = new CompletionRequestCodec;

        $encoded = $codec->encode($request);
        self::assertSame([
            'schema_version' => 1,
            'request' => [
                'messages' => [
                    ['role' => 'system', 'content' => 'S/ü'],
                    ['role' => 'user', 'content' => 'U'],
                ],
                'response_contract' => 'json',
                'purpose' => 'purpose',
                'model_tier' => 'quality',
                'options' => ['temperature' => 0.25],
                'text_response_policy' => [
                    'minimum_characters' => 7,
                    'rejected_prefixes' => ['No:', 'Stop'],
                    'allow_truncated' => true,
                ],
                'metadata' => ['candidate_index' => 2],
                'response_schema' => ['type' => 'object', 'required' => ['answer']],
                'structured_response_attempts' => 2,
            ],
        ], json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
        self::assertEquals($request, $codec->decode($encoded));

        $minimal = new CompletionRequest([], ResponseContract::Text, 'plain');
        self::assertEquals($minimal, $codec->decode($codec->encode($minimal)));
    }

    public function test_metric_response_and_diagnostic_codecs_round_trip_complete_and_null_shapes(): void
    {
        $tokens = new TokenUsage(11, 7, 25, 2, 3, 4);
        $cost = InvocationCost::fromUsd('0.012345');
        $metrics = new CompletionMetrics(
            $tokens,
            $cost,
            321,
            ['region' => 'eu/west', 'unicode' => 'ż'],
            3,
            false,
            true,
        );
        $metricsCodec = new CompletionMetricsCodec;
        self::assertEquals($metrics, $metricsCodec->decode($metricsCodec->encode($metrics)));
        self::assertSame([
            'schema_version' => 1,
            'metrics' => [
                'tokens' => $tokens->toArray(),
                'cost_usd' => '0.012345',
                'latency_milliseconds' => 321,
                'provider_details' => ['region' => 'eu/west', 'unicode' => 'ż'],
                'provider_requests' => 3,
                'usage_complete' => false,
                'usage_present' => true,
            ],
        ], json_decode($metricsCodec->encode($metrics), true, flags: JSON_THROW_ON_ERROR));

        $attempt = new AttemptMetrics(
            'provider',
            'model',
            'provider:model',
            'quality',
            $tokens,
            $cost,
            321,
            3,
            true,
            false,
            123,
            456,
        );
        $attemptCodec = new AttemptMetricsCodec;
        $attemptData = self::decodeMap(
            $attemptCodec->encode($attempt),
        );
        self::assertSame($attempt->toArray(), JsonInput::map($attemptData['metrics'] ?? null, 'metrics'));
        self::assertEquals($attempt, $attemptCodec->decode($attemptCodec->encode($attempt)));

        $diagnostic = new StructuredResponseDiagnostic(
            StructuredResponseStage::SchemaValidation,
            ResponseContract::Candidate,
            str_repeat('a', 64),
            true,
            99,
            str_repeat('b', 64),
            StructuredDecodeStatus::Object,
            'object',
            '$.content',
            'required',
            'stop',
            true,
            false,
            'retry_scheduled',
        );
        $diagnosticCodec = new StructuredResponseDiagnosticCodec;
        $diagnosticData = self::decodeMap(
            $diagnosticCodec->encode($diagnostic),
        );
        self::assertSame(
            $diagnostic->toArray(),
            JsonInput::map($diagnosticData['diagnostic'] ?? null, 'diagnostic'),
        );
        self::assertEquals($diagnostic, $diagnosticCodec->decode($diagnosticCodec->encode($diagnostic)));
        self::assertEquals($diagnostic, $diagnosticCodec->decodeArray($diagnostic->toArray()));

        $response = new CompletionResponse(
            'text/ü',
            ['answer' => ['nested' => true]],
            'provider',
            'model',
            ['request_id' => 'request-1'],
            $metrics,
            $diagnostic,
        );
        $responseCodec = new CompletionResponseCodec($diagnosticCodec);
        self::assertEquals($response, $responseCodec->decode($responseCodec->encode($response)));
        $responseData = self::decodeMap($responseCodec->encode($response));
        $responsePayload = JsonInput::map($responseData['response'] ?? null, 'response');
        $responseMetrics = JsonInput::map($responsePayload['metrics'] ?? null, 'response.metrics');
        self::assertSame('text/ü', $responsePayload['text']);
        self::assertSame(['answer' => ['nested' => true]], $responsePayload['structured']);
        self::assertSame('0.012345', $responseMetrics['cost_usd']);
        self::assertSame($diagnostic->toArray(), $responsePayload['diagnostic']);

        $emptyResponse = new CompletionResponse('only text');
        self::assertEquals($emptyResponse, $responseCodec->decode($responseCodec->encode($emptyResponse)));
    }

    public function test_domain_event_codec_round_trips_every_registered_event(): void
    {
        $runId = RunId::fromString('run-codec');
        $parentRunId = RunId::fromString('parent-codec');
        $stepId = StepId::fromString('step-codec');
        $candidateId = CandidateId::fromString('candidate-codec');
        $invocationId = InvocationId::fromString('invocation-codec');
        $time = new DateTimeImmutable('2026-08-08T12:34:56.123456+00:00');
        $events = [
            new WorkflowCreated($runId, 'Workflow ü', '1/2', $time),
            new WorkflowRecoveryStarted($runId, $parentRunId, RunRecoveryAction::ForkFailedStep, $stepId, $time),
            new StepStarted($runId, $stepId, StepType::fromString('generate'), $time),
            new LlmCallReserved($runId, 2, 9, 'generate_candidate', $time),
            new StepCompleted($runId, $stepId, ['result' => 'ok'], $time),
            new StepContinued($runId, $stepId, ['source' => 'manual'], $time),
            new StepDegraded($runId, $stepId, 4, 2, ['timeout', 'invalid'], $time),
            new StepFailed($runId, $stepId, 'safe_code', 'Safe message', $time),
            new WorkflowCompleted($runId, 'Output ü', $time),
            new CandidateReviewRequested($runId, $stepId, 'plan', [$candidateId], ['round' => 2], $time),
            new ExternalInputRequested($runId, $stepId, 'approval', 'Approve?', ['type' => 'boolean'], $time),
            new MemoryCommitted($runId, $stepId, $candidateId, 'unit-1', 7, str_repeat('c', 64), $time),
            new UsageRecorded(
                $runId,
                $stepId,
                $invocationId,
                'judge',
                'quality',
                'provider',
                'model',
                new TokenUsage(5, 6, 15, 1, 2, 1),
                InvocationCost::fromUsd('0.0042'),
                88,
                2,
                false,
                $time,
            ),
            new InvocationRecoveryRequired($runId, $invocationId, 'lease_expired', $time),
        ];
        $codec = new DomainEventCodec;

        foreach ($events as $event) {
            $type = $codec->type($event);
            $encoded = $codec->encode($event);
            $envelope = self::decodeMap($encoded);
            $eventEnvelope = JsonInput::map($envelope['event'] ?? null, 'event');
            $eventData = JsonInput::map($eventEnvelope['data'] ?? null, 'event.data');

            self::assertSame(1, $envelope['schema_version']);
            self::assertSame($event->eventId(), $eventEnvelope['id']);
            self::assertSame($type, $eventEnvelope['type']);
            self::assertSame($runId->toString(), $eventData['run_id']);
            self::assertSame('2026-08-08T12:34:56.123456+00:00', $eventData['occurred_at']);
            self::assertSame($runId, $codec->runId($event));
            self::assertEquals($event, $codec->decode($type, $encoded));
        }
    }

    public function test_codecs_reject_malformed_future_and_tampered_payloads(): void
    {
        $decoders = [
            static fn (string $payload) => (new CompletionRequestCodec)->decode($payload),
            static fn (string $payload) => (new CompletionMetricsCodec)->decode($payload),
            static fn (string $payload) => (new AttemptMetricsCodec)->decode($payload),
            static fn (string $payload) => (new StructuredResponseDiagnosticCodec)->decode($payload),
            static fn (string $payload) => (new CompletionResponseCodec(
                new StructuredResponseDiagnosticCodec,
            ))->decode($payload),
        ];
        foreach ($decoders as $decode) {
            foreach (['{', 'null', '{"schema_version":2}'] as $payload) {
                try {
                    $decode($payload);
                    self::fail('Invalid persisted payload was accepted.');
                } catch (UnexpectedValueException) {
                    self::addToAssertionCount(1);
                }
            }
        }

        $event = new WorkflowCreated(
            RunId::fromString('run-tampered'),
            'Workflow',
            '1',
            new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
        );
        $codec = new DomainEventCodec;
        $payload = self::decodeMap($codec->encode($event));
        $eventEnvelope = JsonInput::map($payload['event'] ?? null, 'event');
        $eventEnvelope['id'] = str_repeat('f', 64);
        $payload['event'] = $eventEnvelope;
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('deterministic ID');
        $codec->decode('workflow.created', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function decodeMap(string $payload): array
    {
        return JsonInput::map(
            json_decode($payload, true, flags: JSON_THROW_ON_ERROR),
            'payload',
        );
    }
}
