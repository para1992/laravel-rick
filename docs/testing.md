# Testing workflows without provider transport

For the complete fail-closed consumer harness, use the standalone
[`tools/test-stand`](../tools/test-stand/README.md) project. Its fast lane is
part of `composer qa`; full infrastructure, compatibility, and archive lanes
are merge/release gates. It inventories the installed package,
uses strict sanitized/synthetic cassettes, blocks stray HTTP, and always writes
safe reports to `build/test-stand/latest/`.

```bash
make stand-fast
make stand-full
make stand-archive
```

Mutation lanes remain available through `make stand-mutation`, but are
temporarily manual-only and are not part of aggregate or CI pipelines.

`Rick\Laravel\Testing\FakeGateway` scripts package-level LLM responses while
leaving compilation, persistence, metrics, manual barriers, and queues real.
Bind it only in tests or an explicitly local environment; Laravel Rick never
selects it automatically.

```php
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;

$fake = (new FakeGateway)->respondUsing(
    static function (CompletionRequest $request): CompletionResponse {
        $index = (int) $request->metadata['candidate_index'];

        return new CompletionResponse(
            structured: ['content' => 'Plan body '.($index + 1)],
            provider: 'fake',
            model: 'fixture-model',
            metrics: new CompletionMetrics(new TokenUsage(10, 5)),
        );
    },
);

$this->app->instance(GatewayBase::class, $fake);

$run = app(Rick::class)->run(
    app(Rick::class)->workflow('five-plans')
        ->resolve('Create plans', 'Five plans are available')
        ->plan(candidates: 5, minimumSuccessful: 4)
        ->manualJudge()
        ->outputGlue('plan')
        ->build(),
);

$review = app(Rick::class)->pendingReview($run->id);
$selection = app(Rick::class)->selectCandidate($run->id, $review->candidates[2]->id);

$fake->assertRequested(times: 5);
```

Queued `respond()` calls are consumed in order. `respondUsing()` supplies a
callable fallback, `respondMeasured()` creates a response with deterministic
usage, and `reject()` scripts a typed provider outcome. `requests()`,
`requestCount()`, `assertRequested()`, and `assertNothingRequested()` inspect
calls without coupling the test to Laravel AI's HTTP protocol.
