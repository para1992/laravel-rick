# Contributing

Thank you for helping improve Laravel Rick. Open an issue before large design
changes and keep pull requests focused on one concern.

## Development

Requirements are PHP 8.3 or newer, Composer 2, and extensions required by
Laravel. From the standalone package repository:

```bash
composer install
composer style:fix
composer regression
composer qa
composer qa:coverage
composer qa:archive
```

Add deterministic tests for every behavior change. Do not add PHPStan
baselines or ignored errors. Architecture changes must preserve the rules in
`AGENTS.md` and update the capability/regression manifests when applicable.

By participating, you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).
