# Changelog

All notable changes to this project are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [0.3.0] - 2026-08-16

- Added automatic judge (`judge()`): one structured LLM invocation selects the best candidate and the run completes without a human review barrier.
- Added `await_human` step: a schema-validated human approval barrier.
- Added `rick.humanizer` recipe: language-neutral text humanization with pattern, taste, and fidelity audits plus factual grounding against the source.
- Prompt overflows now fail the step cleanly through `PromptLimitExceededException` (a `StepFailureBase`).
- Repaired invocation-diagnostic backfill rows skipped by the 0.1.0/0.2.0 migration.

## [0.2.0] - 2026-08-14

- Added `StrictSchema` for consumer-defined portable strict structured-output schemas.
- Added configuration-time validation with operation and property-level diagnostics.
- Documented nullable fields for strict structured output.

## [0.1.0] - 2026-08-13

Initial release.

- Added the canonical compilation and execution pipeline for Laravel 12–13.
- Added encrypted, tenant-scoped persistence with versioned JSON codecs.
- Added transactional queue and domain-event outbox delivery with recovery.
- Added synchronous, scheduled, manual-review, and external-input workflows.
- Added typed metrics, budgets, pricing, retention, and operational commands.

[0.1.0]: https://github.com/para1992/laravel-rick/releases/tag/v0.1.0
[0.2.0]: https://github.com/para1992/laravel-rick/releases/tag/v0.2.0
[0.3.0]: https://github.com/para1992/laravel-rick/releases/tag/v0.3.0
