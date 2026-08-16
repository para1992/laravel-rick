# Changelog

All notable changes to this project are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [0.4.1] - 2026-08-17

- Fixed the README transactional-outbox guarantee: delivery is at least once,
  not exactly once (a worker can crash after the broker accepts a job or a
  listener handles an event but before the row is marked delivered).
- Fixed the README human-approval example: an `awaitHuman` input gate is
  resolved through `pendingInput()` + `submitInput(..., ['approved' => true])`,
  not through `resume()`, which does not carry the human payload.

## [0.4.0] - 2026-08-17

Application-first / Laravel-native.

- Added first-class `Workflow` classes: extend `Rick\Laravel\Workflow`, declare
  `name()`, `version()`, and `build(WorkflowBuilder)`, then start with
  `Workflow::start([...])`. The public builder is `Rick\Laravel\WorkflowBuilder`.
- Added first-class plain PHP application steps: `->step(LoadClaim::class, as: 'load-claim')`
  runs an ordinary invokable that receives a `WorkflowState` and may mutate it.
  No per-step codec, `StepBase`, or custom strategy is required. Bodies are
  at-least-once; external side effects must be idempotent by the application.
- Added first-class Laravel AI agent steps: `->agent(FlagRisk::class, as: 'risk')`
  adapts a `Laravel\Ai\Contracts\Agent` into exactly one audited provider call
  through Rick's canonical accounting. Tools, approvals, and multi-turn
  conversation are rejected loudly because they can issue multiple
  unobservable provider requests.
- Added `WorkflowState` (`Rick\Laravel\WorkflowState`) with `input()`, dot-notation
  `get()/put()/has()/forget()`, and typed subclassing, layered over Rick's
  existing artifact model.
- Added the `Run` handle (`Rick\Laravel\Run`) with `id()`, `snapshot()`,
  `metrics()`, `timeline()`, `delivery()`, `progress()`, `pendingInteraction()`,
  `resume()`, and `retry()`. `retry()` uses the immutable recovery-child model.
- Added `->output()` as the happy-path alias for `->outputGlue()` (the latter
  remains supported). `resolve()`, `context()`, and `outputGlue()` are no longer
  required for a normal workflow.
- Added stable step aliases (`as:`) and human labels (`label:`) plus a safe
  `progress()` projection exposing only `{status, step_id, label, current, total}`.
- Added `Rick::fake()` with high-level assertions (`assertStepRan`,
  `assertAwaitingHuman`, `assertProviderAttempts`, `assertRunRecoveredFrom`)
  that reuse the existing `FakeGateway` and read the real snapshot/timeline.
- Added the `make:rick-workflow` generator that emits a workflow using only the
  public namespaces.

### Compatibility

- All existing `Rick` methods (`workflow`, `compile`, `run`, `schedule`,
  `resume`, `recover`, `snapshot`, `metrics`, `runs`, `timeline`, `delivery`,
  `pendingInteraction`, `pendingReview`, `pendingInput`, `submitInput`,
  `selectCandidate`, `relayOutbox`) and builder methods remain available.
- `outputGlue()` is a backward-compatible alias; `output()` is preferred.
- Persisted run and step payloads remain schema version 1; the new
  `application` and `agent` step types are additive. Pre-0.4 releases cannot
  read them, so follow the documented upgrade path before mixing workers.

### Laravel AI capability matrix

| Capability | Status |
|------------|--------|
| Plain text agent | supported |
| Structured output agent (`Schemable`) | supported |
| Custom instructions | supported |
| Provider/model attributes | supported |
| Tools (`HasTools`) | rejected with an exception |
| Approval flow (`Approvable`) | rejected with an exception |
| Multi-turn conversation (`Conversational`) | rejected with an exception |

### Known limitations

- Application-step bodies are at-least-once; exactly-once external side effects
  are not guaranteed. Use the documented idempotency pattern.
- There is no `cancel()` on `Run`; no complete cancel transition exists today.

## [0.3.0] - 2026-08-16

- Added automatic judge (`judge()`): one structured LLM invocation selects the best candidate and the run completes without a human review barrier.
- Added `await_human` step: a schema-validated human approval barrier.
- Added `rick.humanizer` recipe: language-neutral text humanization with pattern, taste, and fidelity audits plus factual grounding against the source.
- Prompt overflows now fail the step cleanly through `PromptLimitExceededException` (a `StepFailureBase`).
- Repaired invocation-diagnostic backfill rows skipped by the 0.1.0/0.2.0 migration.

## [0.2.0] - 2026-08-14

- Added `StrictSchema` for consumer-defined portable strict structured-output schemas.
- Added configuration-time validation with operation and property-level diagnostics.
- Documented nullable fields for strict structured output.

## [0.1.0] - 2026-08-13

Initial release.

- Added the canonical compilation and execution pipeline for Laravel 12–13.
- Added encrypted, tenant-scoped persistence with versioned JSON codecs.
- Added transactional queue and domain-event outbox delivery with recovery.
- Added synchronous, scheduled, manual-review, and external-input workflows.
- Added typed metrics, budgets, pricing, retention, and operational commands.

[0.1.0]: https://github.com/para1992/laravel-rick/releases/tag/v0.1.0
[0.2.0]: https://github.com/para1992/laravel-rick/releases/tag/v0.2.0
[0.3.0]: https://github.com/para1992/laravel-rick/releases/tag/v0.3.0
[0.4.0]: https://github.com/para1992/laravel-rick/releases/tag/v0.4.0
[0.4.1]: https://github.com/para1992/laravel-rick/releases/tag/v0.4.1
