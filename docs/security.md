# Security

Run and invocation payloads, completion requests and responses, metrics, and
domain-event envelopes are encrypted before database writes. Encryption does
not replace database access control, backups, key rotation, or tenant-aware
authorization in the host application.

Never persist raw provider or queue exception messages. Laravel Rick records
stable safe error codes and messages and sends full exceptions only to the
runtime reporter. Avoid logging prompts, provider payloads, decrypted rows,
API keys, or tenant identifiers unless the application's reviewed policy
requires it.

Structured-response diagnostics store only bounded metadata: presence, byte
count, SHA-256 fingerprint, decode category, schema path/keyword, finish
reason, usage completeness, and separated gateway/provider identifiers. The
diagnostic and per-attempt metrics payloads are encrypted at rest. Raw provider
text, JSON fragments, prompts, API keys, and application values are not stored
in diagnostics, logs, events, metrics, or timeline observations.

Remote JSON Schema references are rejected without network access. Queue lock
keys hash tenant and entity identifiers. Operational commands never scan Rick
tables without an explicit tenant context.

Event delivery is at least once. Listeners must deduplicate by deterministic
event ID and avoid non-idempotent external effects without their own durable
idempotency key.

See the source repository security policy for private reporting instructions.
