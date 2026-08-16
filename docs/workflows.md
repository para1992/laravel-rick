# Workflows

Create definitions through `Rick::workflow()`. A workflow begins with
`resolve()` unless it is the special single-step `rawPrompt()` form.

```php
$workflow = $rick->workflow('article')
    ->budget(maxCostUsd: '0.02', requireKnownPricing: true)
    ->resolve('Write an article', 'A publishable draft is selected')
    ->context('brief')
    ->generate(
        'draft',
        candidates: 5,
        outputKey: 'draft',
        minimumSuccessful: 4,
    )
    ->manualJudge()
    ->outputGlue('draft')
    ->build();
```

Built-in steps are resolve, raw prompt, definition of done, context,
generation, unfold, automatic judge, manual judge, edit, output glue, configured operation,
quality gate, grounded verification, parallel, map, join, branch, and wait for
input. `manualJudge()` and `unfoldManualJudge()` are the only builder methods
that create manual selection barriers.

Use `judge()` after candidate generation to ask one structured LLM invocation
to select the candidate that best satisfies the task and definition of done.
The selected candidate ID, score, reason, judge invocation, and original
candidate provenance remain persisted. `manualJudge()` keeps the existing
human-selection barrier.

```php
$workflow = $rick->workflow('automatic-selection')
    ->resolve('Write a launch announcement', 'The strongest accurate draft is selected')
    ->draft(candidates: 3, minimumSuccessful: 2)
    ->judge(modelPolicy: 'quality')
    ->outputGlue('draft')
    ->build();
```

Generation uses `AllRequired` by default. Set `minimumSuccessful` when a
candidate batch may continue after a bounded number of failures. Rick waits
until every dispatched invocation is terminal, emits `step.degraded`, and
passes only successful outcomes to reduction. Candidate titles and provenance
keep the original invocation number, so a failed third slot produces
Candidates 1, 2, 4, and 5 rather than renumbering them.

`rawPrompt()` is a terminal one-call workflow. It sends exactly one user
message and adds no wrapper instruction. Routing, budgets, persistence,
outbox delivery, attempts, and metrics still apply.

LLM work should use configured operation IDs. Persisted workflow steps contain
stable logical types and codec versions, never implementation class names.

## Humanizer recipe

The built-in `rick.humanizer` recipe uses the English prompt corpus from
[`blader/humanizer` v2.9.1](https://github.com/blader/humanizer/tree/v2.9.1)
under its MIT license. The prompt stays language-neutral at runtime: it keeps
the source language, does not translate, and treats the upstream English
examples as illustrations rather than language-specific requirements.

It also runs a separate prose-taste audit adapted from the
[`Leonxlnx/taste-skill`](https://github.com/Leonxlnx/taste-skill) MIT-licensed
guidance. This audit looks for generic or templated prose while protecting
appropriate neutral registers and human signals such as specific details,
genuine asides, and uneven sentence rhythm.

```php
use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeRegistry;
use Rick\Laravel\Rick;

$workflow = app(RecipeRegistry::class)->build('rick.humanizer');
$run = app(Rick::class)->run($workflow, [
    'source' => $text,
]);

$humanized = $run->artifact('humanizer.output')->content;
```

The chain performs a draft rewrite, structured AI-pattern and fidelity audit,
a prose-taste audit, targeted revision, factual grounding against the source,
one bounded grounding repair, and a final quality gate. To calibrate voice, opt
in explicitly and provide a separate sample:

```php
$workflow = app(RecipeRegistry::class)->build('rick.humanizer', [
    'use_voice_sample' => true,
]);

$run = app(Rick::class)->run($workflow, [
    'source' => $text,
    'voice_sample' => $authorSample,
]);
```

The voice sample affects style only. Grounding always uses `source` as factual
evidence. Operation IDs and input keys can be replaced through recipe
configuration when an application needs different routing or artifact names.
