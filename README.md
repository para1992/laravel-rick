# Laravel Rick

Durable, tenant-aware AI workflows for Laravel.

Laravel Rick runs the durable business process around your AI calls — ordinary
application steps, Laravel AI agents, queues, approvals, budgets, retries, and
long waits — without repeating paid work merely because a worker crashed.

## Why not just call Laravel AI directly?

A single `Agent::prompt()` call is easy. A business process is not:

- a crash after the provider charged you should not charge you again on retry;
- a human approval may arrive hours later, in a different process;
- every tenant must see only its own runs;
- costs and token budgets must be enforced even under redelivery.

Rick wraps the same Laravel AI agents you already write in a persisted,
recoverable workflow. The agent stays an agent; Rick owns the durable process
around it.

## What normal code looks like

```php
<?php

namespace App\Workflows;

use App\Ai\Agents\ExtractClaimFacts;
use App\Ai\Agents\FlagRisk;
use App\WorkflowSteps\LoadClaim;
use App\WorkflowSteps\StoreDecision;
use Rick\Laravel\Workflow;
use Rick\Laravel\WorkflowBuilder;

final class ClaimDecisionWorkflow extends Workflow
{
    public function name(): string
    {
        return 'claim-decision';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function build(WorkflowBuilder $workflow): WorkflowBuilder
    {
        return $workflow
            ->budget(maxCostUsd: '0.25')
            ->step(LoadClaim::class, as: 'load-claim', label: 'Loading claim')
            ->agent(ExtractClaimFacts::class, as: 'facts', label: 'Extracting claim facts')
            ->agent(FlagRisk::class, as: 'risk', label: 'Flagging risk')
            ->awaitHuman('approve', schema: ['approved' => ['required', 'boolean']])
            ->step(StoreDecision::class, as: 'store-decision', label: 'Storing decision')
            ->output('decision');
    }
}
```

An application step is an ordinary invokable class:

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

An agent is an ordinary Laravel AI agent:

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class FlagRisk implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Assess the legal risk of the supplied claim facts and return a short verdict.';
    }
}
```

Start it and drive it:

```php
$run = ClaimDecisionWorkflow::start([
    'claim_id' => $claim->id,
]);

$progress = $run->progress();

if ($run->pendingInteraction()->exists()) {
    $run->resume();
}
```

When a run fails, retry it without mutating history or re-paying for work that
already succeeded:

```php
$child = $failedRun->retry();
```

## Stronger guarantees

- **Immutable recovery lineage** — a failed run is never rewritten; retry
  creates a child that points back to its parent.
- **Reusable successful invocations** — provider work that already succeeded is
  reused on recovery instead of being paid for again.
- **Tenant isolation** — every run, step, and observation is tenant-scoped.
- **Provider-attempt accounting** — queue redelivery never silently authorizes a
  duplicate paid provider attempt.
- **Budgets** — token and cost limits are enforced across recovery.
- **Transactional outbox** — domain events and queued jobs are delivered exactly
  once, after commit.
- **Versioned, encrypted persistence** — persisted business payloads are
  encrypted and versioned; a suspended run stays readable across deploys.

## Installation

Laravel Rick requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require rickphp/laravel-rick:^0.4
php artisan migrate
```

## Provider setup

For OpenRouter, add your key and model to `.env`:

```dotenv
OPENROUTER_API_KEY=your-key-here
RICK_LLM_PROVIDER=openrouter
RICK_LLM_MODEL=google/gemini-3.5-flash-lite
```

Publish the package configuration:

```bash
php artisan vendor:publish --tag=rick-config
```

Then route the `medium` tier in `config/rick.php`:

```php
'medium' => [
    'provider' => env('RICK_LLM_PROVIDER', 'openrouter'),
    'model' => env('RICK_LLM_MODEL'),
],
```

After changing `.env` or configuration, run `php artisan config:clear`.

## Quick start

Generate a workflow and run a minimal durable prompt:

```bash
php artisan make:rick-workflow ContractReview
```

The lowest-friction start is still a raw prompt as a durable workflow:

```php
use Rick\Laravel\Rick;

$rick = app(Rick::class);

$workflow = $rick->workflow('summary')
    ->rawPrompt('Summarize the latest customer feedback.')
    ->build();

$run = $rick->run($workflow);

echo $run->output();
```

## Testing without provider calls

```php
use Rick\Laravel\Rick;
use App\Workflows\ClaimDecisionWorkflow;

$fake = app(Rick::class)->fake();
$fake->agent('facts', 'The claimant was in a rear-end collision.');
$fake->agent('risk', 'Low risk.');

$run = ClaimDecisionWorkflow::start(['claim_id' => 42]);

$fake->assertStepRan($run, 'load-claim');
$fake->assertStepRan($run, 'risk');
$fake->assertAwaitingHuman($run);
$fake->assertProviderAttempts(2);
```

## Documentation

- [Installation and configuration](docs/installation.md)
- [Building workflows](docs/workflows.md)
- [Application steps](docs/application-steps.md)
- [Laravel AI agents](docs/laravel-ai-agents.md)
- [Workflow state](docs/workflow-state.md)
- [Runs and progress](docs/runs.md)
- [Public API](docs/public-api.md)
- [Testing without provider calls](docs/testing.md)
- [Queues and transactional outbox](docs/queues-outbox.md)
- [Recovery](docs/recovery.md)

## Project

- See [CHANGELOG.md](CHANGELOG.md) for release history.
- Laravel Rick is released under the [MIT License](LICENSE).
