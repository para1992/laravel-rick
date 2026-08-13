# Pricing and budgets

The package ships with an empty pricing catalog because model prices change.
Applications provide decimal strings, their source URL, and checked date:

```php
'llm' => [
    'pricing' => [
        'models' => [
            'provider:model' => [
                'input_per_million' => '1.25',
                'output_per_million' => '5.00',
                'cached_input_per_million' => '0.25',
            ],
        ],
        'tiers' => ['quality' => 'provider:model'],
        'source_url' => 'https://provider.example/pricing',
        'checked_at' => '2026-07-31',
    ],
],
```

Costs use exact integer nanodollars internally. Resource budgets reserve input
and maximum output tokens before dispatch. When `requireKnownPricing` is true,
an unknown price fails before any provider request.

`metrics()` returns `RunMetrics`; its totals and invocation entries are typed
objects. `RunMetrics`, `MetricTotals`, and `InvocationMetrics` implement
`JsonSerializable` and expose `toArray()` for transport boundaries.

Metrics schema version 2 contains an `attempt_details` ledger for every
logical invocation. Each attempt contributes its available tokens, cost,
latency, provider request count, and prompt/response character counts,
including failed paid responses. Missing usage is represented by
`usage_present: false` and `usage_complete: false`; zeroes do not imply that a
failed provider request was free.

Invalid structured output is not retried by default. When the application
explicitly increases `llm.structured_responses.attempts`, every retry reserves
another call and rechecks both call and resource budgets before transport.
