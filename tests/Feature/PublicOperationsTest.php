<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\PendingInteractionType;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class PublicOperationsTest extends TestCase
{
    public function test_pending_interaction_discriminates_external_input_without_probing_two_endpoints(): void
    {
        $rick = $this->application()->make(Rick::class);
        $waiting = $rick->run($rick->workflow('public-pending-interaction')
            ->resolve('Ask for approval', 'Approval is captured')
            ->waitForInput('approval', 'Approve?', ['type' => 'boolean'], 'approval')
            ->build());

        $interaction = $rick->pendingInteraction($waiting->id);

        self::assertTrue($interaction->exists());
        self::assertSame(PendingInteractionType::ExternalInput, $interaction->type);
        self::assertNull($interaction->review);
        self::assertSame('approval', $interaction->input?->key);
        self::assertSame('external_input', $interaction->toArray()['type']);
    }

    public function test_recover_returns_a_public_receipt_and_remains_idempotent(): void
    {
        $gateway = new class implements GatewayBase
        {
            public string $phase = 'parent';

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                );

                return new CompletionResponse(
                    structured: $this->phase === 'parent' && $index === 1
                        ? ['wrong' => 'failed-second-slot']
                        : ['content' => 'Candidate '.($index + 1).' '.$this->phase],
                    provider: 'fake',
                    model: 'fake-model',
                    metrics: new CompletionMetrics(new TokenUsage(2, 3)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $parent = $rick->run($rick->workflow('public-recovery-receipt')
            ->resolve('Generate two candidates', 'Both candidates are available')
            ->plan(candidates: 2)
            ->manualJudge()
            ->build());
        self::assertSame(RunStatus::Failed, $parent->status);

        $gateway->phase = 'recovery';
        $receipt = $rick->recover($parent->id, RunRecoveryAction::RetryFailed);
        $again = $rick->recover($parent->id, 'retry_failed');

        self::assertSame(1, $receipt->reusedInvocations);
        self::assertSame(1, $receipt->queuedInvocations);
        self::assertFalse($receipt->alreadyExists);
        self::assertSame(1, $receipt->attempts);
        self::assertTrue($again->alreadyExists);
        self::assertSame($receipt->id->toString(), $again->id->toString());
        self::assertSame($receipt->id->toString(), $receipt->toArray()['run_id']);
        self::assertSame(1, $receipt->toArray()['attempts']);
    }

    public function test_relay_outbox_returns_a_public_receipt(): void
    {
        $receipt = $this->application()->make(Rick::class)->relayOutbox();

        self::assertSame([
            'schema_version' => 1,
            'claimed' => 0,
            'delivered' => 0,
            'deferred' => 0,
            'failed' => 0,
        ], $receipt->toArray());
        self::assertSame($receipt->toArray(), $receipt->jsonSerialize());
    }
}
