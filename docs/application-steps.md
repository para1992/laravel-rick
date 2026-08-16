# Application steps

An application step is an ordinary invokable PHP class. It runs synchronously
inside the workflow and receives a `WorkflowState` to read input and write
artifacts.

```php
<?php

namespace App\WorkflowSteps;

use App\Models\Claim;
use Rick\Laravel\WorkflowState;

final class LoadClaim
{
    public function __invoke(WorkflowState $state): void
    {
        $claim = Claim::query()->findOrFail($state->input('claim_id'));

        $state->put('claim', [
            'id' => $claim->id,
            'body' => $claim->body,
        ]);
    }
}
```

Use it with a stable alias:

```php
->step(LoadClaim::class, as: 'load-claim', label: 'Loading claim')
```

## Identity and persistence

The alias (`as:`) is the step's stable logical identifier. Rick persists the
handler reference (class name plus a version, default `1`) through the versioned
`WorkflowStepCodec`. You do not write a codec, a strategy, or a `StepBase` for
the normal case.

The handler resolves through the Laravel container, so it may declare
constructor dependencies.

## Execution semantics

- **Success** — the handler returns normally; its `WorkflowState` mutations are
  flushed as artifacts and the step completes.
- **Throw** — the exception is wrapped in `ApplicationStepException` and the run
  fails cleanly with a `step.failed` timeline event. The raw exception never
  escapes the transaction.
- **At-least-once** — the body runs synchronously inside the run's transition.
  If the worker dies after the body runs but before the transition commits, the
  step re-runs on redelivery. External side effects (HTTP calls, emails,
  non-idempotent writes) must therefore be made idempotent.

Rick never implies exactly-once external side effects. Make side effects
idempotent, for example by keying them on the run ID:

```php
final class ChargeCard
{
    public function __invoke(WorkflowState $state): void
    {
        $runId = $state->runId();

        if ($runId !== null && Payment::where('run_id', $runId->toString())->exists()) {
            return;
        }

        // perform the charge, keyed by run id
    }
}
```
