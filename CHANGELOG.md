# Changelog

All notable changes to this project are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

- Added verified compatibility lanes for PHP 8.3–8.5, Laravel 12–13, and
  Laravel AI 0.9–0.10; the development lock now uses Laravel AI 0.10.2.
- Added executable showcase coverage for five-plan manual selection and
  order-stable, idempotent parallel queue execution.
- Added strict portable structured-output schemas with exact OpenAI-compatible
  and Gemini request fixtures, plus safe deterministic HTTP 4xx outcomes.
- Added tenant-scoped `runs()`, `timeline()`, and `delivery()` read models with
  stable incremental observation versions and redacted delivery metadata,
  including an additive migration for existing 0.1 installations.
- Added versioned JSON transport for public read values and a typed candidate
  selection receipt containing run version, status, and continuation intent.
- Added the scriptable `Testing\FakeGateway`, published-config snapshot
  coverage, and corrected the public control/LLM queue key documentation.
- Added SQLite WAL/busy-timeout diagnostics and a concurrent queued manual
  selection smoke test using three LLM completions.
- Added strict raw-text structured-response decoding with encrypted safe
  diagnostics, separated gateway/provider identifiers, and per-attempt metrics
  that include failed paid responses and missing-usage state.
- Added opt-in budget-checked structured-response retry, typed candidate quorum
  policies, degraded-step events, and original candidate provenance.
- Expanded public metrics and timeline observations with version-two safe
  attempt details and distinct attempt-level event types.

## [0.1.0] - 2026-07-31

First public pre-release.

- Added the canonical compilation and execution pipeline for Laravel 12–13.
- Added encrypted, tenant-scoped persistence with versioned JSON codecs.
- Added transactional queue and domain-event outbox delivery with recovery.
- Added synchronous, scheduled, manual-review, and external-input workflows.
- Added typed metrics, budgets, pricing, retention, and operational commands.

[Unreleased]: https://github.com/para1992/laravel-rick/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/para1992/laravel-rick/releases/tag/v0.1.0
