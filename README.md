# Laravel Rick

Durable AI workflows for Laravel.

Laravel Rick turns typed workflow definitions into recoverable, tenant-aware
runs. It supports synchronous and queued execution, Laravel AI calls, manual
review and input, encrypted persistence, budgets, metrics, recovery, and a
transactional outbox.

[![Laravel Rick durable workflow demo](https://github.com/para1992/laravel-rick/releases/download/v0.1.0/laravel-rick-demo.gif)](https://github.com/para1992/laravel-rick-demo)

## Demo

[Run the human-in-the-loop demo](https://github.com/para1992/laravel-rick-demo)
to generate five candidates, pause for a human decision, and continue with the
selected result. It is free and deterministic by default, with an optional
live Laravel AI provider mode.

## Installation

Laravel Rick requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require rickphp/laravel-rick:^0.1
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
See [Installation and configuration](docs/installation.md) for all model tiers,
other Laravel AI providers, cost notes, and troubleshooting.

## Quick start

After configuring a Laravel AI provider and model, run a prompt as a durable
workflow:

```php
use Rick\Laravel\Rick;

$rick = app(Rick::class);

$workflow = $rick->workflow('summary')
    ->rawPrompt('Summarize the latest customer feedback.')
    ->build();

$run = $rick->run($workflow);

echo $run->output();
```

Longer workflows can generate candidates, pause for human input, run in
parallel, enforce quality gates, verify grounding, and resume through queues.

## Documentation

- [Installation and configuration](docs/installation.md)
- [Building workflows](docs/workflows.md)
- [Use cases](docs/use-cases.md)
- [Manual review and external input](docs/manual-interactions.md)
- [Queues and transactional outbox](docs/queues-outbox.md)
- [Public API](docs/public-api.md)
- [Testing without provider calls](docs/testing.md)

## Testing

```bash
composer qa
```

## Project

- See [CHANGELOG.md](CHANGELOG.md) for release history.
- See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.
- Report vulnerabilities through GitHub private security advisories; see
  [SECURITY.md](SECURITY.md).
- Laravel Rick is released under the [MIT License](LICENSE).
