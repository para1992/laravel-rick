# Manual review and external input

A manual judge pauses a run in `awaiting_input`. Read the typed pending review,
select one candidate, and let the durable continuation resume the run:

```php
use Rick\Laravel\Domain\Run\ValueObject\RunId;

$runId = RunId::fromString((string) $request->route('run'));
$review = $rick->pendingReview($runId);

$candidateId = null;
foreach ($review->candidates as $candidate) {
    if ($candidate->id->toString() === (string) $request->input('candidate_id')) {
        $candidateId = $candidate->id;
        break;
    }
}

abort_if($candidateId === null, 422, 'Candidate is no longer pending.');
$selection = $rick->selectCandidate($runId, $candidateId);

return response()->json($selection);
```

`RunId::toString()` is the durable HTTP/storage representation;
`RunId::fromString()` restores the typed ID on the next request. Re-reading
`pendingReview()` and matching the submitted string against its persisted
typed IDs prevents a client from selecting a stale or foreign candidate.
`CandidateSelection` reports `version`, `status`, and `continuationQueued`, so
the UI can update immediately without guessing whether a continuation was
recorded.

`waitForInput()` declares a key, prompt, JSON Schema, and output artifact. Read
and submit it through the public API:

```php
$pending = $rick->pendingInput($runId);
$rick->submitInput($runId, $pending->key, ['approved' => true]);
```

Input schemas use Draft 2020-12 through Opis JSON Schema. Standard keywords,
compositions, and local `$ref` values are supported. Remote references are
rejected without a network request. Validation errors include a JSON path and
keyword but do not echo the submitted value.

Submissions and candidate choices are tenant-scoped optimistic transitions.
Clients should treat a stale-state error as a signal to refresh the current
snapshot instead of blindly retrying an old choice.

```php
use Rick\Laravel\Application\Execution\Exception\ConcurrentExecutionModificationException;

try {
    $selection = $rick->selectCandidate($runId, $candidateId);
} catch (ConcurrentExecutionModificationException) {
    return response()->json([
        'message' => 'Run changed; refresh before choosing again.',
        'run' => $rick->snapshot($runId),
        'review' => $rick->pendingReview($runId),
    ], 409);
}
```

See [Showcase use cases](use-cases.md) for a complete five-plan selection flow.
