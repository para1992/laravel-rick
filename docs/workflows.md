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
generation, unfold, manual judge, edit, output glue, configured operation,
quality gate, grounded verification, parallel, map, join, branch, and wait for
input. `manualJudge()` and `unfoldManualJudge()` are the only builder methods
that create manual selection barriers.

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
