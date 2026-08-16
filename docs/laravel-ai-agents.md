# Laravel AI agents

An agent step adapts an ordinary Laravel AI agent class into a durable Rick
step:

```php
->agent(FlagRisk::class, as: 'risk', label: 'Flagging risk')
```

The agent is a class implementing `Laravel\Ai\Contracts\Agent`:

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class FlagRisk implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Assess the legal risk of the supplied claim facts and return a short verdict.';
    }
}
```

Rick reads the agent's `instructions()` and adapts it into exactly one audited
provider request. The result is stored as an artifact under the step alias
(`as:`).

## Accounting is not bypassed

Rick never calls the agent's `prompt()` transport directly. Instead it re-encodes
the agent into a single `CompletionRequest` that flows through the canonical
invocation path, so all of the following still apply:

- invocation creation and provider-attempt accounting;
- token and cost metrics;
- budget enforcement;
- tenant scoping;
- recovery and indeterminate-outcome handling.

## Structured output

For structured output, the agent also implements `Laravel\Ai\Contracts\Schemable`
and returns its schema from `toSchema()`. Rick uses that schema for the JSON
response contract and stores the decoded payload as the artifact.

## Capability matrix

| Capability | Status |
|------------|--------|
| Plain text agent | supported |
| Structured output (`Schemable`) | supported |
| Custom instructions | supported |
| Provider/model attributes | supported |
| Tools (`HasTools`) | rejected with `UnsupportedAgentCapabilityException` |
| Approval flow (`Approvable`) | rejected with `UnsupportedAgentCapabilityException` |
| Multi-turn conversation (`Conversational`) | rejected with `UnsupportedAgentCapabilityException` |

Tools, approvals, and multi-turn conversation are rejected because they can
issue multiple provider requests internally that Rick cannot observe and
account for as a single audited call. A smaller correct `agent()` is preferred
over a broad dishonest one.
