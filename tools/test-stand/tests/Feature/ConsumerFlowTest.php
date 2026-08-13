<?php

declare(strict_types=1);

namespace Rick\Stand\Tests\Feature;

use Illuminate\Support\Facades\Facade;
use Rick\Laravel\Application\Execution\Request\GetRunSnapshotRequest;
use Rick\Laravel\Application\Execution\Result\GetRunSnapshotResult;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\PendingInteractionType;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Rick\Laravel\Facade\Rick as RickFacade;
use Rick\Laravel\Rick;
use Rick\Stand\Tests\TestCase;

final class ConsumerFlowTest extends TestCase
{
    public function test_public_api_compiles_through_rick_and_facade(): void
    {
        $rick = $this->application()->make(Rick::class);
        $definition = $rick->workflow('consumer-compile')
            ->resolve('Compile offline', 'One deterministic plan exists')
            ->outputGlue()
            ->build();

        self::assertSame(2, $rick->compile($definition)->count());
        Facade::setFacadeApplication($this->application());
        self::assertSame('consumer-compile', RickFacade::compile($definition)->name);
        self::assertSame(get_class($rick), get_class(RickFacade::getFacadeRoot()));
    }

    public function test_offline_run_exposes_read_apis_and_internal_requests_use_handler(): void
    {
        $this->useCassettes(['openrouter-gemini-20260801-raw']);
        $rick = $this->application()->make(Rick::class);
        $definition = $rick->workflow('consumer-offline-run')
            ->rawPrompt('Return the literal marker RICK_LIVE_OK and no other words.')
            ->build();

        $run = $rick->run($definition, callLimit: 1);
        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('RICK_LIVE_OK', $run->output());
        self::assertSame($run->id->toString(), $rick->snapshot($run->id)->id->toString());
        self::assertSame(1, $rick->metrics($run->id)->totals->calls);
        self::assertNotEmpty($rick->runs(limit: 10)->runs);
        self::assertNotEmpty($rick->timeline($run->id)->observations);
        self::assertSame($run->id->toString(), $rick->delivery($run->id)->runId->toString());

        $parcel = $this->application()->make(Handler::class)->handle(
            Parcel::fromArray([new GetRunSnapshotRequest($run->id)]),
        );
        self::assertSame($run->id->toString(), $parcel->get(GetRunSnapshotResult::class)->run->id->toString());
    }

    public function test_public_interaction_paths_are_offline_and_validated(): void
    {
        $this->useCassettes(['openrouter-gemini-20260801-candidate']);
        $rick = $this->application()->make(Rick::class);
        $reviewRun = $rick->run(
            $rick->workflow('consumer-review')
                ->resolve('Write a candidate', 'One candidate is selected')
                ->generate('draft', outputKey: 'draft')
                ->manualJudge()
                ->outputGlue('draft')
                ->build(),
        );
        $review = $rick->pendingReview($reviewRun->id);
        self::assertCount(1, $review->candidates);
        self::assertSame('RICK_STRUCTURED_OK', $review->candidates[0]->content);
        self::assertSame('RICK_STRUCTURED_OK', $rick->selectCandidate($reviewRun->id, $review->candidates[0]->id)->artifact('draft')->content);

        $inputRun = $rick->run(
            $rick->workflow('consumer-input')
                ->resolve('Ask for approval', 'An approval artifact exists')
                ->waitForInput('approval', 'Approve?', ['type' => 'object', 'required' => ['approved']], 'approval')
                ->build(),
        );
        $interaction = $rick->pendingInteraction($inputRun->id);
        self::assertSame(PendingInteractionType::ExternalInput, $interaction->type);
        self::assertSame('approval', $interaction->input?->key);
        self::assertSame('approval', $rick->pendingInput($inputRun->id)->key);
        try {
            $rick->submitInput($inputRun->id, 'wrong-key', ['approved' => true]);
            self::fail('Wrong interaction key must fail.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('key', strtolower($error->getMessage()));
        }
        $submitted = $rick->submitInput($inputRun->id, 'approval', ['approved' => true]);
        self::assertContains($submitted->status, [RunStatus::Running, RunStatus::Completed]);
        self::assertSame('{"approved":true}', $rick->snapshot($inputRun->id)->artifact('approval')->content);
    }

    public function test_public_operator_operations_are_offline_and_idempotent(): void
    {
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public string $phase = 'parent';

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $index = $request->metadata['candidate_index'] ?? null;
                if (! is_int($index)) {
                    throw new \LogicException('Candidate index metadata must be an integer.');
                }

                return new CompletionResponse(
                    structured: $this->phase === 'parent' && $index === 1
                        ? ['wrong' => 'failed-second-slot']
                        : ['content' => 'Consumer candidate '.($index + 1)],
                    provider: 'fake',
                    model: 'consumer-fixture',
                    metrics: new CompletionMetrics(new TokenUsage(2, 3)),
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $failed = $rick->run(
            $rick->workflow('consumer-recovery')
                ->resolve('Generate two candidates', 'Both candidates are available')
                ->plan(candidates: 2)
                ->manualJudge()
                ->build(),
        );

        self::assertSame(RunStatus::Failed, $failed->status);
        $receipt = $rick->recover($failed->id);
        $again = $rick->recover($failed->id);
        self::assertSame(1, $receipt->queuedInvocations);
        self::assertFalse($receipt->alreadyExists);
        self::assertTrue($again->alreadyExists);
        self::assertSame($receipt->id->toString(), $again->id->toString());

        self::assertSame([
            'schema_version' => 1,
            'claimed' => 0,
            'delivered' => 0,
            'deferred' => 0,
            'failed' => 0,
        ], $rick->relayOutbox()->toArray());
    }
}
