<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Testing\FakeGateway;
use RuntimeException;

final class FakeGatewayTest extends TestCase
{
    public function test_fake_gateway_is_scriptable_and_records_requests(): void
    {
        $gateway = (new FakeGateway)
            ->respond(new CompletionResponse(text: 'first'))
            ->respond(new CompletionResponse(text: 'second'));
        $request = new CompletionRequest([], ResponseContract::Text, 'test');

        $gateway->assertNothingRequested();
        self::assertSame('first', $gateway->complete($request)->text);
        self::assertSame('second', $gateway->complete($request)->text);
        self::assertSame([$request, $request], $gateway->requests());
        self::assertSame(2, $gateway->requestCount());
        $gateway->assertRequested(times: 2);
        $gateway->assertRequested(
            static fn (CompletionRequest $captured): bool => $captured->purpose === 'test',
            times: 2,
        );
    }

    public function test_callable_and_measured_responses_are_deterministic(): void
    {
        $metrics = new CompletionMetrics(
            new TokenUsage(12, 8),
            InvocationCost::fromUsd('0.001'),
            25,
        );
        $gateway = (new FakeGateway)
            ->respondMeasured(
                'measured',
                ['content' => 'measured'],
                $metrics,
                provider: 'fixture-provider',
                model: 'fixture-model',
                metadata: ['request_id' => 'fixture-request'],
            )
            ->respondUsing(static fn (CompletionRequest $request): CompletionResponse => new CompletionResponse(
                text: 'callable-'.$request->purpose,
            ));
        $first = new CompletionRequest([], ResponseContract::Candidate, 'first');
        $second = new CompletionRequest([], ResponseContract::Text, 'second');

        $measured = $gateway->complete($first);
        self::assertSame('measured', $measured->text);
        self::assertSame($metrics, $measured->metrics);
        self::assertSame('fixture-provider', $measured->provider);
        self::assertSame('fixture-model', $measured->model);
        self::assertSame('fixture-request', $measured->metadata['request_id']);
        self::assertSame('callable-second', $gateway->complete($second)->text);
    }

    public function test_provider_outcomes_can_be_scripted_without_transport_fakes(): void
    {
        $gateway = (new FakeGateway)->reject(
            retryable: false,
            outcome: ProviderRequestOutcome::NotAccepted,
            requestId: 'fixture-request',
            httpStatusClass: '4xx',
        );

        try {
            $gateway->complete(new CompletionRequest([], ResponseContract::Text, 'rejected'));
            self::fail('The scripted provider rejection should be thrown.');
        } catch (ProviderRequestException $error) {
            self::assertSame(ProviderRequestOutcome::NotAccepted, $error->outcome);
            self::assertSame('fixture-request', $error->requestId);
            self::assertSame('4xx', $error->httpStatusClass);
        }
    }

    public function test_non_closure_responder_is_supported(): void
    {
        $callable = new class
        {
            public function answer(CompletionRequest $request): CompletionResponse
            {
                return new CompletionResponse(text: 'answer-'.$request->purpose);
            }
        };
        $request = new CompletionRequest([], ResponseContract::Text, 'purpose');
        $queued = (new FakeGateway)->respondUsing([$callable, 'answer']);
        self::assertSame('answer-purpose', $queued->complete($request)->text);
    }

    public function test_assertions_and_empty_queue_fail_closed(): void
    {
        $request = new CompletionRequest([], ResponseContract::Text, 'purpose');
        $gateway = new FakeGateway;
        $operations = [
            static fn () => $gateway->complete($request),
            static fn () => $gateway->assertRequested(times: 0),
            static fn () => $gateway->assertRequested(times: 1),
        ];

        foreach ($operations as $index => $operation) {
            try {
                $operation();
                self::fail('A fake gateway invariant was not enforced.');
            } catch (InvalidArgumentException|RuntimeException) {
                self::addToAssertionCount(1);
            }
            if ($index === 0) {
                self::assertSame(1, $gateway->requestCount());
            }
        }

        try {
            $gateway->assertNothingRequested();
            self::fail('Recorded requests were ignored.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('observed 1', $error->getMessage());
        }
    }
}
