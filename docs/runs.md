# Runs

A `Run` handle wraps a persisted run and exposes observation and lifecycle
methods without re-supplying the run ID each time.

```php
$run = ClaimDecisionWorkflow::start(['claim_id' => $claim->id]);

$run->id();               // string
$run->snapshot();         // WorkflowRunSnapshot
$run->metrics();          // RunMetrics
$run->timeline();         // RunTimeline
$run->delivery();         // DeliverySnapshot
$run->progress();         // RunProgress
$run->pendingInteraction(); // PendingInteraction
$run->resume();           // WorkflowRunSnapshot
$run->retry();            // RunRecoveryReceipt
```

## Progress

`progress()` returns a safe, stable projection:

```json
{
  "status": "running",
  "step_id": "risk",
  "label": "Flagging risk",
  "current": 3,
  "total": 7
}
```

It never exposes prompts or provider bodies.

## Retry

`retry()` uses the immutable recovery-child model. A failed run is never
rewritten; `retry()` creates a child run pointing back to its parent, and
successful reusable provider work is reused where recovery rules allow.

```php
$child = $failedRun->retry();

// child.parent_run_id === failedRun.id
```

There is no `cancel()` on `Run`; no complete cancel transition exists today.
