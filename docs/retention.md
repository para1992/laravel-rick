# Retention

Automatic retention is disabled by default. Pruning only deletes terminal
`completed`, `failed`, or `cancelled` runs older than an explicit cutoff. Runs
with any undelivered outbox row are retained.

```bash
php artisan rick:prune --all --before="2026-01-01T00:00:00Z" --dry-run
php artisan rick:prune --all --before="2026-01-01T00:00:00Z" --batch=100
```

Each tenant and batch is handled in a short transaction. Database foreign-key
cascades remove step executions, invocations, attempts, and delivered outbox
rows. Configure `retention.cutoff_days` only when a fixed policy is intended;
configure `retention.schedule_enabled` separately to opt into daily pruning.

Take backups appropriate to the application's compliance and recovery policy.
Pruning is irreversible once the database transaction commits.
