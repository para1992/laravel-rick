# Showcase use cases

These two flows demonstrate the package boundary: human control over expensive
generation and durable parallel execution through Laravel queues.

## Generate five plans and let a person choose

One task produces five independent plan candidates. `run()` executes the
generation synchronously, persists every response and metric, then stops at the
manual barrier instead of choosing on the user's behalf.

```php
use Rick\Laravel\Rick;
use Rick\Laravel\Domain\Run\RunStatus;

$rick = app(Rick::class);
$workflow = $rick->workflow('choose-a-plan')
    ->resolve(
        'Create a migration plan for a large Laravel application.',
        'Five distinct implementation plans are available for review.',
    )
    ->plan(candidates: 5)
    ->manualJudge()
    ->outputGlue('plan')
    ->build();

$run = $rick->run($workflow);

assert($run->status === RunStatus::AwaitingInput);

$review = $rick->pendingReview($run->id);
foreach ($review->candidates as $candidate) {
    // Render $candidate->id, title, summary, content, and payload in the UI.
}

$chosen = $review->candidates[3];
$selection = $rick->selectCandidate($run->id, $chosen->id);

assert($selection->continuationQueued);
assert($selection->version > $run->version);
```

Selection is an optimistic tenant-scoped transition. It accepts exactly one of
the persisted candidates and records a durable continuation in the
transactional outbox. A queue worker completes `outputGlue()`; the application
can poll `snapshot()`, incrementally read `timeline()`, or react to its own
projected run events.

```php
$completed = $rick->snapshot($run->id);

if ($completed->status === RunStatus::Completed) {
    echo $completed->output(); // The selected plan only.
}
```

The regression suite proves that five provider calls produce five distinct
candidate IDs, the run pauses, the fourth candidate can be selected, and only
that candidate becomes the final output.

## Execute independent work safely in parallel queues

`parallel()` creates one persisted invocation per operation. `schedule()` puts
the first continuation into the outbox; control and LLM workers then advance
the run without keeping PHP process memory between transitions.

```php
use Rick\Laravel\Application\Compilation\Support\Builder\ParallelBuilder;
use Rick\Laravel\Rick;

$rick = app(Rick::class);
$brief = 'Assess the migration from a monolith to bounded Laravel modules.';

$workflow = $rick->workflow('parallel-assessment')
    ->resolve($brief, 'Independent assessments are joined in declaration order.')
    ->context('brief')
    ->parallel(static fn (ParallelBuilder $parallel): ParallelBuilder => $parallel
        ->operation('architecture', 'rick.text', 'architecture', ['brief'])
        ->operation('delivery', 'rick.text', 'delivery', ['brief'])
        ->operation('risk', 'rick.text', 'risk', ['brief']))
    ->join(['architecture', 'delivery', 'risk'], 'assessment')
    ->outputGlue('assessment')
    ->build();

$run = $rick->schedule($workflow, ['brief' => $brief]);
```

Run the configured control and LLM queues and keep Laravel's scheduler active:

```bash
# Both package queues use Laravel's "default" queue unless reconfigured.
php artisan queue:work --queue=default
php artisan schedule:work
```

For separate worker pools, set the string values `rick.queue.control` and
`rick.queue.llm`, then pass those names to `--queue`.

The runtime provides the following guarantees:

- results are reduced in declared invocation order even when workers finish in
  another order;
- an already completed invocation ignores duplicate job delivery without
  making another provider call;
- each successful invocation emits a deduplicated continuation handoff;
- the in-flight window is bounded by
  `rick.execution.max_in_flight_invocations`;
- broker handoffs use an at-least-once transactional outbox;
- an expired or ambiguous paid request becomes `indeterminate` and requires
  reconciliation instead of an automatic second charge.

CI executes the parallel scheduled flow through real database, Redis, and SQS
queue drivers. Deterministic regression additionally completes invocations in
reverse order and redelivers one invocation job to prove stable reduction and
idempotency.

The SQLite CI smoke uses the documented WAL profile, three LLM completions,
separate control/LLM queue names, and a manual selection made while another
process owns a write lock.
