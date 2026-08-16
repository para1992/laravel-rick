# Workflow state

`WorkflowState` is the mutable view an application step receives. It layers
over the run's input and current artifacts.

```php
$state->input('claim_id');        // read run input (returns default on missing)
$state->get('claim');             // read an artifact
$state->get('risk.score', 0);     // read a nested key with a default
$state->put('customer', $data);   // write an artifact
$state->put('risk.score', 8);     // write a nested key
$state->has('risk');              // existence check
$state->forget('temporary');      // remove a key from the in-step view
```

## Nested keys

Keys are dot-separated. The first segment is the artifact key; the rest is the
nested path within its value.

```php
$state->put('risk.score', 8);
$state->get('risk');          // ['score' => 8]
$state->get('risk.score');    // 8
```

`get()` returns its default when the key (or any intermediate segment) is
missing. `input()` returns its default when the input key is absent instead of
throwing.

## Persistence

Mutations are flushed back through the canonical artifact model at the end of
the step. Only keys touched by `put()`/`forget()` produce artifacts, and only
`put()` keys that are still present after mutations are persisted. Historical
snapshots are immutable: mutating the state in one step never rewrites a
previous step's artifacts.

## Typed subclasses

`WorkflowState` is designed to be extended with typed accessors:

```php
final class ClaimState extends WorkflowState
{
    public function riskScore(): int
    {
        return (int) $this->get('risk.score', 0);
    }
}
```

Typed state is optional. Rick does not require it.
