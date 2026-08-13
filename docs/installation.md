# Installation and configuration

Laravel Rick requires PHP 8.3 or newer and Laravel 12 or 13. The supported and
continuously tested runtime matrix is PHP 8.3, 8.4, and 8.5 with Laravel 12 or
13 and Laravel AI 0.9 (`^0.9.1`) or 0.10. SQLite, MySQL, MariaDB, and
PostgreSQL are supported. SQL Server is not currently claimed.

```bash
composer require rickphp/laravel-rick:^0.1
php artisan migrate
```

Laravel package discovery registers the provider automatically. Publish the
configuration only when the application needs to override it:

```bash
php artisan vendor:publish --tag=rick-config
```

## Configure an AI provider

Laravel Rick dispatches model calls through Laravel AI. The package does not
guess a paid provider or model: applications must configure an explicit route
before running an LLM step.

The quickest OpenRouter setup is:

```dotenv
OPENROUTER_API_KEY=your-key-here
RICK_LLM_PROVIDER=openrouter
RICK_LLM_MODEL=google/gemini-3.5-flash-lite
```

Create the key in the [OpenRouter dashboard](https://openrouter.ai/settings/keys),
publish `config/rick.php`, and replace its `llm.models` section with:

```php
'models' => [
    'cheap' => [
        'provider' => env('RICK_LLM_PROVIDER', 'openrouter'),
        'model' => env('RICK_LLM_MODEL'),
    ],
    'medium' => [
        'provider' => env('RICK_LLM_PROVIDER', 'openrouter'),
        'model' => env('RICK_LLM_MODEL'),
    ],
    'quality' => [
        'provider' => env('RICK_LLM_PROVIDER', 'openrouter'),
        'model' => env('RICK_LLM_MODEL'),
    ],
],
```

Using the same route for all three tiers is a straightforward starting point.
Applications can later assign cheaper and stronger models independently. The
default policy resolves to `medium`; `cheap` and `quality` are used by explicit
policies and escalation paths.

Clear cached configuration after editing `.env` or `config/rick.php`:

```bash
php artisan config:clear
```

Now make one real provider call from a route, command, or Tinker:

```php
use Rick\Laravel\Rick;

$rick = app(Rick::class);
$workflow = $rick->workflow('first-call')
    ->rawPrompt('Reply with exactly: Laravel Rick is running.')
    ->build();

$run = $rick->run($workflow);

dump($run->status->value, $run->output(), $rick->metrics($run->id));
```

`rawPrompt()` makes one provider request. Candidate generation makes one
request per candidate, so `plan(candidates: 5)` makes five billable requests.
Structured steps require a model route that supports structured JSON output.
The public [demo application](https://github.com/para1992/laravel-rick-demo)
uses a deterministic gateway by default and documents the same optional live
OpenRouter configuration.

For another provider, change `RICK_LLM_PROVIDER`, `RICK_LLM_MODEL`, and add the
credential expected by the
[Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk). Laravel AI owns provider
transport configuration; Laravel Rick owns workflow routing, persistence,
budgets, metrics, and recovery.

Laravel Rick does not maintain a separate provider allowlist: it passes the
configured route to Laravel AI. It therefore supports every text provider
available in the installed Laravel AI SDK. With Laravel AI 0.10, the text
providers are OpenAI, OpenAI Compatible, Anthropic, Gemini, Azure OpenAI,
Amazon Bedrock, Groq, xAI, DeepSeek, Mistral, Ollama, and OpenRouter. See the
current Laravel AI [provider support matrix](https://laravel.com/docs/13.x/ai-sdk#provider-support).

This compatibility applies to text generation. Cohere, Jina, and VoyageAI
features such as embeddings and reranking, and ElevenLabs audio features, are
not exposed by Laravel Rick. Structured workflow steps additionally require a
selected model that can satisfy the requested structured output schema.
OpenRouter is exercised by the package's live smoke test, while Gemini schema
translation is covered by regression fixtures. The remaining text providers
route through the same Laravel AI adapter but are not yet live-tested by this
package.

### Common setup failures

- An authentication failure usually means the provider credential is missing
  from the process environment or stale configuration is cached.
- A model-not-found failure means `RICK_LLM_MODEL` is not a valid model slug for
  the selected provider.
- A structured-response failure can mean the selected model does not support
  the strict JSON Schema required by candidate and JSON steps.
- A run that remains queued needs a Laravel queue worker, scheduler, and outbox
  relay. Use `run()` for the first synchronous smoke test; configure workers
  before using `schedule()`.
- Never commit `.env` or provider keys. Laravel's standard `.gitignore` should
  exclude `.env`; verify the staged diff before every push.

Queue names are string values, not nested objects:

```php
'queue' => [
    'connection' => env('RICK_QUEUE_CONNECTION'),
    'control' => 'rick-control',
    'llm' => 'rick-llm',
    // Per-job tries, timeout, and backoff follow below.
],
```

Configuration is validated when the package boots. Unknown package-owned
keys, invalid ranges, malformed table or queue names, missing strategy or
codec classes, broken cross-references, invalid decimal prices, and invalid
JSON Schemas fail fast. Provider option objects and user JSON Schemas remain
extensible.

Structured responses are validated against a portable strict-provider
capability envelope before Laravel AI dispatches a request. Every object must
declare at least one property, list every property in `required`, and set
`additionalProperties` to `false`; nested objects and array items follow the
same rule. Invalid custom `output_schema` values fail as
`provider_request_preflight_failed` before a paid request. Package-owned
candidate schemas contain exactly one required string property, `content`.
CI fixes the exact OpenAI-compatible `response_format` and Gemini
`generationConfig` for Laravel AI 0.9 and 0.10. The same package resolver owns
the outbound schema, inbound validation, and schema fingerprint.

Invalid structured output is not retried by default. Applications that accept
the cost of another provider request may opt in explicitly:

```php
'llm' => [
    'structured_responses' => [
        'attempts' => 2,
        'strategy' => 'same_route_then_fallback',
    ],
],
```

Attempt two uses the same route. Later attempts require a configured
escalation tier whose resolved provider/model route is actually different.
Each retry reserves another call and rechecks resource budgets. Laravel queue
job tries remain transport recovery and never increase this provider-attempt
limit.

The public CI runs every PHP/Laravel/Laravel-AI combination. The lowest lanes
resolve Laravel AI 0.9.1 with the lowest compatible dependency set; the highest
lanes resolve the latest Laravel AI 0.10 release and current compatible
dependencies. `make test-matrix` repeats the Laravel and Laravel AI lanes on
the PHP runtime that invokes it.

Upgrades use additive package migrations. The attempt-diagnostics migration
reclassifies UUIDv7 legacy `provider_request_id` values as Laravel AI
`gateway_invocation_id` values; it does not claim they are provider IDs. Run
`php artisan migrate` normally and never use `migrate:fresh` as an upgrade
procedure for production data.

For queued execution, configure a Laravel queue connection and run workers for
the configured `rick.queue.control` and `rick.queue.llm` queues. Also ensure the
Laravel scheduler is running so outbox relay and lease recovery continue after
process or broker failures.

For a file-backed SQLite queue deployment, apply the WAL/busy-timeout worker
profile and run `php artisan rick:diagnose --strict`; see
[Queues and transactional outbox](queues-outbox.md#sqlite-with-concurrent-workers).
