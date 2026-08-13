# Laravel Rick

Durable AI workflows for Laravel.

Laravel Rick turns typed workflow definitions into recoverable, tenant-aware
runs. It supports synchronous and queued execution, Laravel AI calls, manual
review and input, encrypted persistence, budgets, metrics, recovery, and a
transactional outbox.

![Laravel Rick durable workflow demo](https://github.com/para1992/laravel-rick/releases/download/v0.1.0/laravel-rick-demo.gif)

## Installation

Laravel Rick requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require rickphp/laravel-rick:^0.1
php artisan migrate
```

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
- [Queues and transactional outbox](docs/queues-outbox.md)
- [Public API](docs/public-api.md)

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
