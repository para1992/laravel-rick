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

Laravel Rick routes through every text provider supported by the installed
Laravel AI SDK. With Laravel AI 0.10, that includes OpenAI, OpenAI Compatible,
Anthropic, Gemini, Azure OpenAI, Amazon Bedrock, Groq, xAI, DeepSeek, Mistral,
Ollama, and OpenRouter. Structured workflows also require a selected model
that supports structured output. OpenRouter is live-tested by this package;
Gemini structured schemas are covered by regression fixtures. Other providers
use the same adapter but are not yet live-tested by the package.

See [Installation and configuration](docs/installation.md) for all model tiers,
provider credentials, cost notes, and troubleshooting. Laravel AI maintains
the current [provider support matrix](https://laravel.com/docs/13.x/ai-sdk#provider-support).

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

## Typical tasks

The examples below assume `$rick = app(Rick::class)`.

### Generate content within a fixed budget

```php
$workflow = $rick->workflow('product-description')
    ->budget(maxCostUsd: '0.10')
    ->resolve('Write a product description.', 'A concise description is ready.')
    ->context('product')
    ->generate('description', outputKey: 'description')
    ->outputGlue('description')
    ->build();

$run = $rick->run($workflow, ['product' => $product]);
```

### Process a list through queues

```php
$workflow = $rick->workflow('ticket-summaries')
    ->resolve('Summarize every support ticket.', 'Every ticket has a summary.')
    ->context('tickets')
    ->map('tickets', 'items', 'rick.text', 'summaries', maxItems: 100)
    ->outputGlue('summaries')
    ->build();

$run = $rick->schedule($workflow, ['tickets' => ['items' => $tickets]]);
```

### Generate variants and let a person choose

```php
$workflow = $rick->workflow('campaign-copy')
    ->resolve('Write campaign copy.', 'Three distinct drafts are ready.')
    ->draft(candidates: 3)
    ->manualJudge()
    ->outputGlue('draft')
    ->build();

$run = $rick->run($workflow);
$review = $rick->pendingReview($run->id);
$rick->selectCandidate($run->id, $review->candidates[0]->id);
```

### Generate variants and let the judge choose

Use `judge()` instead of `manualJudge()` when a structured LLM invocation can
select the best candidate and the run should complete without a human review
barrier:

```php
$workflow = $rick->workflow('campaign-copy')
    ->resolve('Write campaign copy.', 'The strongest draft is selected.')
    ->draft(candidates: 3)
    ->judge(modelPolicy: 'quality')
    ->outputGlue('draft')
    ->build();

$run = $rick->run($workflow); // completes without pausing for review
```

### Verify an answer against supplied evidence

```php
$workflow = $rick->workflow('grounded-answer')
    ->resolve('Answer the question from the evidence.', 'Every claim is grounded.')
    ->context('question')
    ->context('evidence')
    ->generate('answer', outputKey: 'answer', reads: ['question', 'evidence'])
    ->groundedVerify('answer', ['evidence'], output: 'verified')
    ->outputGlue('verified')
    ->build();

$run = $rick->run($workflow, compact('question', 'evidence'));
```

### Humanize text with the built-in recipe

The `rick.humanizer` recipe rewrites text to remove clusters of AI-writing
patterns while preserving the source language and facts. It audits the draft,
revises it, grounds it against the source, and runs a final quality gate:

```php
use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeRegistry;

$workflow = app(RecipeRegistry::class)->build('rick.humanizer');
$run = $rick->run($workflow, ['source' => $text]);

$humanized = $run->artifact('humanizer.output')->content;
```

To calibrate voice, opt in and provide a separate sample:

```php
$workflow = app(RecipeRegistry::class)->build('rick.humanizer', [
    'use_voice_sample' => true,
]);

$run = $rick->run($workflow, ['source' => $text, 'voice_sample' => $authorSample]);
```

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
