# Public API and compatibility

The application-facing layer lives at the top-level `Rick\Laravel` namespace so
consumers never import deep module namespaces for common workflows:

```php
use Rick\Laravel\Workflow;
use Rick\Laravel\WorkflowBuilder;
use Rick\Laravel\WorkflowState;
use Rick\Laravel\Run;
```

- `Workflow` — an abstract base class (`name()`, `version()`, `build()`, static
  `start()` and `definition()`).
- `WorkflowBuilder` — the public builder (`step()`, `agent()`, `awaitHuman()`,
  `output()`, `budget()`, and the existing primitives).
- `WorkflowState` — a mutable facade over a run's input and artifacts.
- `Run` — a read/action handle over a persisted run.
- `Testing\RickFake` — returned by `Rick::fake()` for high-level tests.

The supported entry points remain `Rick`, its Facade, workflow builders, Domain
inputs and outputs, and the extension interfaces listed in
[extensions.md](extensions.md).

`Rick` intentionally exposes only:

```text
workflow compile run schedule resume snapshot metrics runs timeline delivery
recover pendingInteraction pendingReview pendingInput submitInput
selectCandidate relayOutbox fake
```

`run()` and `schedule()` accept `WorkflowDefinition|CompiledWorkflow`.
`metrics()` returns Domain `RunMetrics`; `pendingReview()` and `pendingInput()`
return Domain values. `pendingInteraction()` reads the active barrier once and
returns a discriminated `PendingInteraction` with type `candidate_review`,
`external_input`, or `none`. `selectCandidate()` returns a `CandidateSelection`
receipt containing the new run version, status, full snapshot, and
`continuation_queued` flag.

Terminal recovery and an embedded relay pass are also available without
crossing package layers:

```php
$interaction = $rick->pendingInteraction($runId);
$recovery = $rick->recover($failedRunId, 'retry_failed', attempts: 3);
$relay = $rick->relayOutbox(limit: 100);
```

`recover()` returns a versioned `RunRecoveryReceipt` and remains idempotent per
parent, tenant, and action. Its optional bounded retry is safe for transient
database contention and the receipt exposes the actual `attempts` used.
`relayOutbox()` returns an `OutboxRelayReceipt` with
claimed, delivered, deferred, and failed counts. Long-running production relay
and queue workers should still use the package commands and normal process
supervision.

The tenant-scoped observability queries are:

```php
$page = $rick->runs(cursor: $cursor, status: RunStatus::Running, limit: 25);
$timeline = $rick->timeline($runId, afterVersion: $lastTimelineVersion);
$delivery = $rick->delivery($runId);
```

Run pages are ordered newest first, use an opaque cursor, and accept a bounded
limit from 1 through 100. Cursors cannot cross the active tenant boundary.
Timeline observation IDs are deterministic and deduplicated; their numeric
versions remain stable so `latest_version` can be supplied as the next
`afterVersion`. Delivery exposes outbox intent, attempts, lease/delivery state,
and safe error codes without exposing the stored envelope.

All public read values implement `JsonSerializable` and provide `toArray()`.
Containers keep their existing transport version. `RunMetrics`,
`MetricTotals`, `InvocationMetrics`, and `RunObservation` use
`schema_version: 2`; their version-two fields are additive. All representations
use snake-case stable keys, ISO-8601 timestamps, and string identifiers. A
Laravel controller can therefore return them directly:

```php
return response()->json($rick->timeline($runId));
```

Timeline and delivery representations omit prompts, provider bodies, request
payloads, response payloads, and encrypted envelopes. A run snapshot,
candidate, pending interaction, context, and artifact intentionally contain
application business content; controllers must still enforce their own tenant
authorization before returning those values.

Attempt observations use distinct
`invocation.attempt.succeeded`, `invocation.attempt.failed`, and
`invocation.attempt.indeterminate` event types. Their safe details distinguish
the Laravel AI gateway invocation ID from provider request/generation IDs and
include the original candidate number, provider route, response byte count and
fingerprint, decode category, schema path/keyword, finish reason, usage
completeness, retry decision, and terminal timestamp when available. Raw
provider text and prompts are never exposed. `metrics()` includes the same
per-attempt accounting in `attempt_details`, including failed paid responses
and late completions.

Failed terminal runs remain immutable. Operator-created recovery children are
visible additively through `snapshot().recovery` and `runs().runs[*].recovery`,
with `parent_run_id`, `action`, and `step_id`. Their timeline contains
`run.recovery.started`. Recovery-derived invocation metrics add `reused`,
`source_run_id`, and `source_invocation_id`; `reused` is true only for a
successful zero-attempt response copied from the parent. Ordinary invocation
payloads keep their previous shape. Create a child through public
`Rick::recover()` or the equivalent operator command. See
[recovery.md](recovery.md).

Internal continuation and invocation operations are available only through
Application requests.

Until `1.0`, minor releases may refine internal classes. Public-contract
breaking changes follow Semantic Versioning even during the `0.x` series when
practical and are called out in the changelog.
