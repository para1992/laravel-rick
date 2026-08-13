# Compiled and synchronous execution

`compile()` converts a workflow definition into a `CompiledWorkflow`. The
compiled value can be stored by the application and passed directly to either
execution method:

```php
$compiled = $rick->compile($definition);
$sync = $rick->run($compiled, ['subject' => 'Laravel']);
$queued = $rick->schedule($compiled, ['subject' => 'Laravel']);
```

`run()` owns the only synchronous loop. It advances one persisted transition
at a time and executes invocations inline until the run reaches a terminal
state or a manual interaction barrier. It deliberately creates no queue
intents.

`schedule()` persists the run and its first continuation intent atomically.
Subsequent jobs submit canonical Application requests; jobs never contain
business logic or repository access.

Use `snapshot()` to read the current typed state and `metrics()` for typed call,
token, latency, and cost data. Internal Application requests, pipes, results,
and all Infrastructure classes are not public extension contracts.
