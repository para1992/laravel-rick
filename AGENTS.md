# Laravel Rick Architecture

These rules apply to every file under `laravel-rick/`.

## Package boundary

- Composer package: `rickphp/laravel-rick`.
- Root namespace: `Rick\Laravel`.
- PHP: 8.3 or newer. Supported Laravel versions: 12 and 13.
- The package is self-contained. Production code must not import `Rick\...`
  types outside the `Rick\Laravel\...` namespace or depend on `packages/core`.
- `Domain` is the deterministic business core. It must not import
  `Application`, `Infrastructure`, Illuminate, the container, pipeline, queue,
  or database mechanisms.
- `Application` must not import `Infrastructure`.

## Application pipeline

The only canonical route is:

```text
Rick -> Application\Orchestration\EntryPoint\Handler
     -> Application\Orchestration\Pipe\DispatchPipe
     -> Application\Handler\Module
     -> Application\Gate\Pipe\GatePipe
     -> module pipes
```

- There is one Application module per business domain: `Compilation` and
  `Execution`.
- Each module has exactly one `Provider` and exactly one `GateContract`.
- `DispatchPipe` is generic. It may only work with `ModuleBase`; it must not
  name or branch on Compilation or Execution types.
- Compilation accepts exactly one `DefinitionBase` and guarantees exactly one
  `PlanBase`.
- Execution accepts exactly one `ExecutionRequestBase` and guarantees exactly
  one `ExecutionResultBase`.
- `GatePipe` validates every declared input and output through strict
  `Parcel::get()`, so missing and ambiguous values fail identically.
- Execution pipes are registered in explicit order in
  `Application\Execution\Provider\Provider`. Every pipe handles only its own
  request type and transparently forwards every other Execution request.

## Support layout

Module-local `Support` directories are the Laravel-style home for secondary
Application mechanisms. They do not mean unconstrained helpers or services:
every class remains grouped by its concrete role, and class names ending in
`Helper` or `Service` remain forbidden.

- The only direct Application module directories are `Compilation` and
  `Execution`. Shared pipeline directories are `Gate`, `Handler`,
  `Interface`, and `Orchestration`.
- `Exception`, `Strategy`, and DVO (`ValueObject`) are first-class
  architectural categories and must never be nested below `Support`.
- Direct Compilation directories are `Contract`, `Exception`, `Interface`,
  `Pipe`, `Provider`, `Strategy`, `Support`, and `ValueObject`. Only builders,
  compiler implementation, and recipes live below
  `Application\Compilation\Support`, grouped by their role.
- Direct Execution directories are `Contract`, `Exception`, `Interface`,
  `Pipe`, `Provider`, `Request`, `Result`, `Strategy`, `Support`, and
  `ValueObject`. Registries, metrics, events, schemas, guards, LLM operations,
  quality mechanisms, exact-quote grounding, planning, reduction, invocation
  creation, invocation dispatch, pending-interaction, invocation recovery, and
  working-memory mechanisms live below `Application\Execution\Support`,
  grouped by their role.
- `Parcel` remains package-owned because Laravel Pipeline has no strict,
  type-indexed equivalent. It is a framework-free immutable value object in
  `Domain\ValueObject`; `ParcelItemBase` lives in `Domain\Interface`, and its
  missing/ambiguous exceptions live in `Domain\Exception`.
- Domain owns `Parcel`, but business transitions do not accept or return it.
  `Rick`, Application pipeline mechanisms, and queue jobs are boundary
  consumers.
- Gate and orchestration exceptions live in their direct local `Exception`
  directories.
- Do not add a new direct Application or module directory when the type belongs
  under one of these `Support` role directories.

## Handlers and pipeline mechanisms

- The suffix `Handler` is reserved for empty Laravel Pipeline entry points.
- The only concrete handler is the empty final
  `Application\Orchestration\EntryPoint\Handler`.
- `Application\Handler\HandlerBase` is the only shared Laravel Pipeline
  mechanism. Its final `handle()` and `process()` methods must not be
  overridden.
- Business logic, persistence, compilation, LLM calls, and request branching
  are forbidden in handlers.
- Do not create step handlers such as `ResolveStepHandler`,
  `GenerateStepHandler`, or equivalents.

## Execution

- `Domain\Execution\Interface\StepStrategyBase` owns the domain
  `plan/reduce` contract selected by `StepType`.
- `StepPlanBase` and its concrete plans live in
  `Domain\Execution\Interface` and `Domain\Execution\Plan`. Application
  planning mechanisms consume those domain types; Application must not define
  parallel plan contracts or plan implementations.
- `InvocationReductionBase`, `CandidateSelectionBase`, and
  `ExternalInputSubmissionBase` are the only strategy capability interfaces.
  They live in `Domain\Execution\Interface` and identify strategies that may
  perform those three operations; the calling pipe or focused Application
  mechanism must check the capability before invoking it.
- Built-in strategies live in `Application\Execution\Strategy` and use the
  canonical names:
  `ResolveStrategy`, `RawPromptStrategy`, `DefineDodStrategy`, `ContextStrategy`,
  `GenerateStrategy`, `JudgeStrategy`, `EditStrategy`,
  `OutputGlueStrategy`, `UnfoldStrategy`, `OperationStrategy`,
  `QualityGateStrategy`, `GroundedVerifyStrategy`, `ParallelStrategy`,
  `MapStrategy`, `JoinStrategy`, `BranchStrategy`, and
  `WaitForInputStrategy`.
- `StepStrategyRegistry` resolves strategies through the explicit
  `rick.execution.strategies` config map (`StepType` value to class). The
  Laravel container creates the selected class. Tags, tag ordering, implicit
  discovery, and silent overrides are forbidden.
- `ContinueRunPipe` owns exactly one persisted run transition. Its public
  `advance()` method exists only so `RunWorkflowPipe` can reuse that same
  transition without bypassing the Execution use case.
- `RunWorkflowPipe` owns the only synchronous execution loop. It advances the
  run through `ContinueRunPipe` and executes dispatched invocations through
  `ExecuteInvocationPipe` until a terminal state or interaction barrier.
- `ContinueRunPipe` never loops and never performs an LLM call.
- Step planning, invocation reduction, pending interactions, invocation
  creation, invocation dispatch windows, invocation recovery, working-memory
  merging, and resource-budget checks remain focused mechanisms below
  `Application\Execution\Support`. They do not own transactions, repositories,
  event publication, or jobs.
- Jobs submit Application requests only. They must not inject repositories,
  strategies, pipes, focused Support mechanisms, or an LLM gateway.

Canonical Execution use cases:

```text
RunWorkflowRequest       -> RunWorkflowPipe       -> RunWorkflowResult
ScheduleRunRequest       -> ScheduleRunPipe       -> ScheduleRunResult
ContinueRunRequest       -> ContinueRunPipe       -> ContinueRunResult
ExecuteInvocationRequest -> ExecuteInvocationPipe -> ExecuteInvocationResult
FailInvocationRequest    -> FailInvocationPipe    -> FailInvocationResult
RecoverRunRequest        -> RecoverRunPipe        -> RecoverRunResult
ResumeRunRequest         -> ResumeRunPipe         -> ResumeRunResult
GetRunSnapshotRequest    -> GetRunSnapshotPipe    -> GetRunSnapshotResult
GetRunMetricsRequest     -> GetRunMetricsPipe     -> GetRunMetricsResult
ListRunsRequest          -> ListRunsPipe          -> ListRunsResult
GetRunTimelineRequest    -> GetRunTimelinePipe    -> GetRunTimelineResult
GetDeliverySnapshotRequest -> GetDeliverySnapshotPipe -> GetDeliverySnapshotResult
GetPendingInteractionRequest -> GetPendingInteractionPipe -> GetPendingInteractionResult
GetPendingReviewRequest  -> GetPendingReviewPipe  -> GetPendingReviewResult
GetPendingInputRequest   -> GetPendingInputPipe   -> GetPendingInputResult
SubmitInputRequest       -> SubmitInputPipe       -> SubmitInputResult
SelectCandidateRequest   -> SelectCandidatePipe   -> SelectCandidateResult
```

## Interfaces and names

- Every PHP interface is located in an `Interface` directory and ends in
  `Base`.
- Namespace and directory must match exactly.
- Requests end in `Request`, pipes in `Pipe`, results in `Result`, policies in
  `Policy`, repositories in `Repository`, gateways in `Gateway`, exceptions in
  `Exception`, and actual queued jobs in `Job`.
- Do not introduce names ending in `Manager`, `Service`, `Helper`, `Util`,
  `Processor`, `Executor`, or `Coordinator`.
- Do not append redundant technical suffixes such as `Entity`, `Aggregate`,
  `DTO`, or `ValueObject` to local class names.

## Persistence and infrastructure

- PHP `serialize()` and `unserialize()` are forbidden.
- Persisted payloads use strict, versioned JSON codecs. Every payload has a
  schema version and rejects unsupported future versions.
- Run and invocation payloads are encrypted through `PayloadProtectorBase`
  before database writes.
- Every database operation is scoped by `TenantContextBase`.
- `RunRepositoryBase` and `ExecutionRepositoryBase` remain separate ports;
  Query Builder adapters are `DatabaseRunRepository` and
  `DatabaseExecutionRepository`.
- Transactions are short. Domain events and queued jobs are published only
  after commit.
- Queue jobs carry tenant ID and use `WithoutOverlapping`, configured
  queues/backoff/timeout, and after-commit dispatch.

## Public API

`Rick` is a thin Parcel adapter and exposes only:

```text
workflow()
compile()
run()
schedule()
resume()
recover()
snapshot()
metrics()
runs()
timeline()
delivery()
pendingInteraction()
pendingReview()
pendingInput()
submitInput()
selectCandidate()
relayOutbox()
```

Internal continuation and invocation operations are available only through
Application requests. The Facade mirrors the public API and contains no logic.

## Automated enforcement

Architecture tests must verify:

- namespace/path parity;
- all interface location and `Base` suffix rules;
- forbidden type suffixes;
- absence of legacy `Rick\...` imports;
- no Application, Infrastructure, or Illuminate imports in Domain;
- no Infrastructure imports in Application;
- exactly one concrete empty handler;
- exactly one provider and gate per Application module;
- absence of PHP serialization and obsolete step handlers;
- explicit, complete strategy configuration.
- the exact direct Application/module directory allowlists and their `Support`
  role directories.
- Domain ownership of the framework-free `Parcel`, `ParcelItemBase`, and Parcel
  exceptions, with no legacy Application namespaces or `Collection`.
- absence of `Exception`, `Strategy`, and `ValueObject` directories anywhere
  below `Support`.
- absence of loose PHP classes in the roots of the `Compilation` and
  `Execution` modules.
- domain ownership of Execution policy contracts and step plans.

Do not retain empty directories or `.gitkeep` files without an architectural
role.

## Local CI startup and recovery

Run every command in this section from the standalone package repository, never
from the parent monorepo:

```bash
cd /path/to/laravel-rick
```

If the Docker runtime is already running, start or recover the complete local
Gitea Actions stack with:

```bash
make ci-up
```

`ci-up` is idempotent. It starts rootless Gitea and the rootless DinD runner,
creates the local user and repository when necessary, and preserves existing
repositories, pipeline history, runner state, and credentials.

If Docker itself is stopped, start the runtime first. Use the command matching
the installed macOS runtime:

```bash
# Colima
colima start --cpu 4 --memory 8

# Docker Desktop
open -a Docker

# OrbStack
open -a OrbStack
```

Wait until the Docker daemon responds, then start the CI stack:

```bash
docker info
make ci-up
```

Verify that Gitea is healthy and both containers are running:

```bash
docker ps --filter name=laravel-rick-ci
curl --fail http://localhost:3000/api/healthz
```

Inspect complete service logs when startup or runner registration fails:

```bash
docker logs --tail 200 laravel-rick-ci-gitea-1
docker logs --tail 200 laravel-rick-ci-runner-1

# Follow logs until interrupted with Ctrl-C.
docker logs --follow laravel-rick-ci-gitea-1
docker logs --follow laravel-rick-ci-runner-1
```

Push the current committed package state to local Gitea and start a pipeline:

```bash
git status --short
make ci-push
```

`ci-push` intentionally refuses to push an uncommitted or dirty working tree.
Commit only the intended package changes before retrying it. Open the Actions
interface with:

```bash
make ci-open
```

The direct pipeline URL is:

```text
http://localhost:3000/local-ci/laravel-rick/actions
```

Run the same checks without Gitea when only local package verification is
needed:

```bash
composer install
composer qa
```

Stop Gitea and the runner without deleting data:

```bash
make ci-down
```

For Colima, the VM may then be stopped separately:

```bash
colima stop
```

To recover later, run `colima start --cpu 4 --memory 8` followed by
`make ci-up`. Never add `--volumes` or `-v` to the Compose down command during
normal recovery: the named volumes contain Gitea repositories, pipeline
history, configuration, and runner state. Local credentials remain in the
Git-ignored `.local-ci/` directory and must never be committed or printed in
logs.
