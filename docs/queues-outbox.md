# Queues and transactional outbox

Laravel Rick supports Laravel's database, Redis, and SQS queue connections.
Horizon may supervise Redis workers but is not required by the package.

Queue handoffs and domain events are stored in a single tenant-scoped outbox
inside the business transaction. A relay claims a short batch using optimistic
conditional updates, dispatches outside the transaction, and records delivery
in a second short transaction. It does not require `SKIP LOCKED`.

Delivery is at least once. A process can crash after the broker accepts a job
or a listener handles an event but before the row is marked delivered. Jobs
are protected by tenant-aware locks and business transitions are optimistic,
but event listeners must deduplicate by the deterministic event ID.

Queue job attempts are not provider attempts. Jobs carry identifiers only;
structured-response retry, route selection, budget checks, attempt persistence,
and quorum decisions stay inside the Execution pipeline. Increasing queue
`tries` therefore does not authorize another paid provider request.

```bash
php artisan rick:outbox:relay --all
php artisan rick:outbox:retry --all
```

Transient broker or listener failures use capped exponential retry. Expired
leases become claimable again. Structurally invalid payloads and exhausted
deliveries enter visible `failed` quarantine; retry them only after the cause
is understood.

Options are repeatable `--tenant=ID`, or `--all` through the tenant catalog.
The two modes are mutually exclusive. Without either option, commands use the
configured default tenant. A failure for one tenant does not stop the rest but
the command returns a failure status.

See [Showcase use cases](use-cases.md) for a parallel scheduled workflow and
the exact ordering, duplicate-delivery, and recovery guarantees covered by CI.

## SQLite with concurrent workers

SQLite queue execution must use a file-backed database. For a relay, control
worker, and one or more LLM workers, configure the host Laravel connection with
WAL and a busy timeout:

```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'foreign_key_constraints' => true,
    'journal_mode' => 'WAL',
    'busy_timeout' => 5000,
    'synchronous' => 'NORMAL',
    'transaction_mode' => 'IMMEDIATE',
],
```

`transaction_mode` is used by Laravel's SQLite connection on PHP 8.4 and
newer. On PHP 8.3, WAL plus the busy timeout remain required; avoid running
more concurrent SQLite writers than the application has tested under its real
load. MySQL, MariaDB, or PostgreSQL is the safer choice for sustained write
concurrency.

Check the active connection from every worker image before deployment:

```bash
php artisan rick:diagnose --strict
```

The command fails in strict mode when the active SQLite connection is not in
WAL mode, has a busy timeout below 5000 ms, or (on PHP 8.4+) does not request
`IMMEDIATE` transactions. Without `--strict`, it prints the same actionable
warnings and exits successfully. In-memory SQLite is appropriate for isolated
tests, not concurrent queue workers.
