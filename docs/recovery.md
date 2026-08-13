# Recovery

Each provider request has an encrypted invocation record and a separate
attempt ledger. Safe failures may retry. An expired lease or unknown transport
outcome becomes `indeterminate` and is never charged again automatically.

Malformed, empty, scalar, or schema-invalid structured responses are known
paid outcomes. They receive an automatic provider retry only when
`llm.structured_responses.attempts` explicitly permits it. This retry happens
inside the Execution use case, not through queue job tries, and every attempt
has an independent budget reservation and ledger row.

Grounded verification specializes its configured JSON schema for every batch:
the claims count, allowed `unit_id` values, and evidence artifact keys are
request-scoped. A malformed verifier response may use its own bounded
structured-response attempts even when the package-wide default is one.
Remaining verifier protocol violations retry verification and increment
`verification_retries_used`; they never increment `repairs_used` or rewrite the
target artifact. Only a contract-valid `unsupported` verdict may enter content
repair.

```bash
php artisan rick:recover --all
php artisan rick:invocation:resolve INVOCATION_ID retry --tenant=TENANT
php artisan rick:invocation:resolve INVOCATION_ID fail \
  --tenant=TENANT --message="Provider confirmed no recoverable result"
```

Before resolving an indeterminate request, reconcile it with the provider by
request ID and application logs. Choose `retry` only when another paid request
is safe. Recovery is tenant-scoped, processes a configured bounded batch, and
ignores optimistic races won by another worker.

Provider failures persist only stable safe codes and messages. Raw exceptions
are reported at runtime and are never written to database payloads or domain
events.

`rick:invocation:resolve` is only for an `indeterminate` invocation. It must not
be used to rewrite a terminal failed run or to retry a known invalid response.

Terminal histories remain immutable. Recover a failed workflow by creating a
tenant-scoped child run:

```php
$receipt = $rick->recover($runId, 'retry_failed', attempts: 3);

echo $receipt->id->toString();
echo $receipt->queuedInvocations;
echo $receipt->attempts;
```

The same operation is available to operators through the package command:

```bash
php artisan rick:run:recover RUN_ID retry_failed --tenant=TENANT
php artisan rick:run:recover RUN_ID continue_successful --tenant=TENANT
php artisan rick:run:recover RUN_ID fork_failed_step --tenant=TENANT
```

`retry_failed` reuses successful responses at their original indices and
queues failed or previously undispatched slots from the persisted request set.
When the workflow strategy itself failed after successful provider responses
(for example, a grounded verification exhausted its repair budget),
`retry_failed` clears that failed step state and repeats every slot. This keeps
the normal retry action from reusing verdicts that already produced a terminal
strategy failure.
`continue_successful` reuses successes, records failed and undispatched slots
as unavailable in the child audit trail, and reduces with the available responses.
`fork_failed_step` repeats every slot in the failed step. Each action is
idempotent per parent and tenant; a repeated command returns the same child.
Use `--call-limit=N` to override the separate child call budget. Otherwise the
parent limit is copied as a fresh budget.

Recovery provenance is available in `snapshot()` and `runs()`, the
`run.recovery.started` timeline observation, and invocation metrics (`reused`,
`source_run_id`, and `source_invocation_id`). Reused responses consume no
child call-budget reservations, provider requests, tokens, or cost. Copied
failed or undispatched slots retain source IDs with `reused: false`.
