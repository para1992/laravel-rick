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
