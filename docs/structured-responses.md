# Structured responses, retries, and quorum

Rick owns one canonical schema for each response contract. The same
`ResponseSchemaResolver` supplies the JSON Schema sent through Laravel AI, the
local post-response validator, and the SHA-256 schema fingerprint used for
diagnostics. Package-owned candidate output is exactly an object with one
required string field, `content`, and no additional properties.

## Consumer-defined strict schemas

Custom `llm.operations.*.output_schema` values use the same portable strict
envelope. Every declared object property must be present in `required`, and
every object must set `additionalProperties` to `false`. Rick validates this
while loading configuration and again before dispatching a provider request.

Use `StrictSchema` to avoid maintaining `required` by hand:

```php
use Rick\Laravel\Domain\Llm\ValueObject\StrictSchema;

$schema = StrictSchema::object([
    'winner' => ['type' => 'integer'],
    'winner_confidence' => ['type' => 'number'],
    'reason' => StrictSchema::nullable(['type' => 'string']),
    'evidence' => StrictSchema::object([
        'quote' => ['type' => 'string'],
    ]),
]);
```

`object()` adds every supplied property to `required` and forbids additional
properties. `nullable()` means that a field is always present but may contain
`null`; it does not make a field omittable. Strict structured output has no
portable `notRequired()` equivalent. Raw JSON Schema arrays remain supported
when they already follow these rules.

Rick decodes the provider's raw response text itself with throwing JSON
semantics. Empty text, invalid JSON, scalar JSON, arrays, objects, and fenced
JSON remain distinguishable. A failure stores an encrypted safe diagnostic
containing sizes, hashes, types, schema path/keyword, finish reason, and usage
state; it never stores or exposes raw text or prompt content.

## Paid retry policy

The default is one structured-response attempt. Opt in to a second paid
request only when the application accepts that cost:

```php
'llm' => [
    'structured_responses' => [
        'attempts' => 2,
        'strategy' => 'same_route_then_fallback',
    ],
],
```

Attempt two repeats the same route. Attempt three and later require the model
policy to resolve a genuinely different escalation provider/model pair.
Duplicate fallback routes are rejected. Before every retry Rick checks the
call limit and resource budget again, then stores a separate encrypted attempt
with its own route, outcome, metrics, and diagnostic. Queue retries do not
grant additional provider attempts.

An indeterminate transport outcome is never retried automatically. Reconcile
it with the provider and use `rick:invocation:resolve`. That command does not
apply to known invalid responses or terminal runs.

## Candidate quorum

Generation remains all-or-nothing by default. A workflow may declare a
minimum-success threshold explicitly:

```php
$workflow = $rick->workflow('plans')
    ->resolve('Create migration plans', 'Four plans can be reviewed')
    ->generate('plan', candidates: 5, minimumSuccessful: 4)
    ->manualJudge()
    ->outputGlue('plan')
    ->build();
```

Rick waits for every dispatched invocation to become terminal. Once no paid
invocation remains active, it can fail without dispatching untouched slots when
successful and undispatched invocations can no longer reach the threshold.
When four of five succeed, reduction consumes only those four outcomes, emits
`step.degraded`, and opens manual review. Candidate numbers preserve original
invocation indices, so they remain 1, 2, 4, and 5 if slot 3 failed.

Use `metrics()` and `timeline()` for operations and dashboards. Version-two
attempt details include failed and late completions, distinct gateway and
provider IDs, usage completeness, retry decisions, and safe validation data.
They deliberately omit raw provider bodies and prompts.
