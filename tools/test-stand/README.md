# Laravel Rick deterministic test stand

`tools/test-stand` is a standalone Laravel consumer project. It installs
`rickphp/laravel-rick` through Composer, boots it with Orchestra Testbench, and
replays only versioned cassettes through the package-owned `FakeGateway`. It
never calls a real LLM.

## Install and run

Run commands from the package repository root:

```bash
composer --working-dir=tools/test-stand install
tools/test-stand/bin/rick-stand list
tools/test-stand/bin/rick-stand run
tools/test-stand/bin/rick-stand run --scenario=offline-execution
tools/test-stand/bin/rick-stand run --lane=full
tools/test-stand/bin/rick-stand run --target=archive
tools/test-stand/bin/rick-stand mutate --lane=domain
```

When the standalone install cannot download development tools, the checked-in
bootstrap intentionally falls back to the package root's compatible Composer
autoload. The complete consumer self-test suite can then be run offline with:

```bash
cd tools/test-stand
../../vendor/bin/pest --configuration=phpunit.xml
```

The equivalent package commands are `make stand-fast`, `make stand-full`,
`make stand-archive`, `make stand-mutation`, and the aggregate `make stand`.
The aggregate runs fast, full, and archive lanes. Mutation remains an explicit
manual command and is temporarily excluded from aggregate and CI pipelines.
The fast lane is part of `composer qa`.

The source target uses the Composer path dependency. The archive target builds
the release tar, installs its extracted contents into a temporary consumer,
and runs the same normalized scenarios. Temporary consumers are removed after
the result is written.

## Fail-closed inventory and scenarios

[`scenarios.json`](scenarios.json) is schema v1. Every scenario declares a
stable `id`, `lane`, exact `covers` element IDs, cassette IDs, and an executable
`Class::test_method`. Inventory is discovered from the installed package and
configuration: public `Rick` methods, strategies, Execution use-case triples,
implemented capabilities, response/provider/lifecycle enums, codecs,
operations, prompts, recipes, quality mechanisms, commands, and the supported
platform matrix.

The union of `covers` must equal the discovered inventory exactly. Adding or
removing a public method, strategy, use case, enum case, codec, configured
extension, command, capability, or platform entry therefore fails before Pest
starts. Unknown fixtures and missing test methods fail the same way.

When adding package behavior:

1. Add the focused consumer scenario or package regression.
2. Add its exact element ID to one or more scenario `covers` lists.
3. Add only the cassettes used by that scenario.
4. Run `rick-stand list`, then the individual scenario, then the fast lane.

## Cassette format and import

Files in [`fixtures/`](fixtures) use strict schema v1. Allowed top-level fields
are `schema_version`, `id`, `kind`, `provenance`, `matcher`, `outcome`, and
`metrics`. `kind` is `live_sanitized` or `synthetic`; outcomes are a sanitized
response or a typed provider error. Matchers can select purpose, response
contract, prompt fragment, and safe request metadata.

Unknown fields, future versions, raw bodies/headers, authentication fields,
and values resembling credentials are rejected. The checked-in live fixtures
were manually imported from the 2026-08-01 paid OpenRouter/Gemini smoke report;
synthetic fixtures represent provider states that cannot be recorded safely.
No stand command records or refreshes live data. A refresh is a deliberate
manual import from the separately confirmed paid smoke suite, followed by
sanitization and source digest verification.

At bootstrap the stand removes common provider credentials, binds
`FakeGateway`, and enables `Http::preventStrayRequests()`. An unmatched cassette
or HTTP request is a test failure.

## Reports and diagnostics

Every `run` writes to `build/test-stand/latest/` even when manifest validation
or Pest fails:

- `report.json` (schema v1) with quality gates and canonical fingerprint;
- `junit.xml` and `coverage.xml`;
- `index.html`, an element → scenario → fixture/result view;
- `mutation/*.json` for mutation lane status.

Mutation findings are aggregated automatically into the safe,
machine-readable `mutation/backlog.json` report.

Timestamps and durations are excluded from the canonical fingerprint, so two
identical offline runs produce the same value. Reports redact credential-like
values and never include provider headers or raw provider bodies.

Typical failures are intentionally direct: “coverage is not exact” means a
discovered element needs a scenario, “unknown fixture” means the manifest link
is stale, “No cassette matched” means the matcher or scenario fixture list is
incomplete, and a skipped test means the selected lane is not runnable in the
current gate.
