# Extension contracts

The semver-stable extension interfaces for the `0.1` line are:

- `Application\Compilation\Interface\StepCodecBase`
- `Application\Compilation\Support\Recipe\Interface\WorkflowRecipeBase`
- `Application\Execution\Support\Llm\Interface\GatewayBase`
- `Application\Execution\Support\Llm\Interface\LlmOperationBase`
- `Application\Execution\Support\Llm\Interface\PricingBase`
- `Application\Execution\Support\Quality\Interface\ArtifactRuleBase`
- `Application\Execution\Support\Quality\Interface\RepairPolicyBase`
- `Application\Interface\ClockBase`
- `Application\Interface\IdGeneratorBase`
- `Application\Interface\PayloadProtectorBase`
- `Application\Interface\TenantCatalogBase`
- `Application\Interface\TenantContextBase`
- Domain execution capability and step strategy interfaces.

Custom strategies are mapped explicitly from a `StepType` value in
`rick.execution.strategies`; discovery and silent overrides are not used. A
custom persisted step also needs a matching versioned `StepCodecBase` entry in
`rick.persistence.step_codecs`.

Custom gateways should classify failures with `ProviderRequestException` and
`ProviderRequestOutcome`. Use `NotAccepted` only when the provider did not
accept a request, `ResponseReceived` after a response, and `Indeterminate`
when another paid call cannot safely be inferred.

Application requests, results, pipes, concrete strategies, configuration
implementations, persistence adapters, jobs, and outbox classes are internal.
