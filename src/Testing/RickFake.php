<?php

declare(strict_types=1);

namespace Rick\Laravel\Testing;

use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Run;
use RuntimeException;

final class RickFake
{
    /** @var array<string, array{0: string, 1: ?array<string, mixed>}> */
    private array $agents = [];

    public function __construct(private FakeGateway $gateway)
    {
        $gateway->respondUsing(function (CompletionRequest $request): CompletionResponse {
            $alias = $this->agentAlias($request);
            if ($alias !== null && isset($this->agents[$alias])) {
                [$text, $structured] = $this->agents[$alias];

                return new CompletionResponse(
                    text: $structured === null ? $text : '',
                    structured: $structured,
                    provider: 'fake',
                    model: 'fake-model',
                );
            }

            throw new RuntimeException('No fake response configured for purpose ['.$request->purpose.'].');
        });
    }

    public function gateway(): FakeGateway
    {
        return $this->gateway;
    }

    /** @param array<string, mixed>|null $structured */
    public function agent(string $alias, string $text, ?array $structured = null): self
    {
        $this->agents[$alias] = [$text, $structured];

        return $this;
    }

    public function assertStepRan(Run $run, string $alias): void
    {
        foreach ($run->timeline()->observations as $observation) {
            if ($observation->type === 'domain.step.completed'
                && $observation->stepId?->toString() === $alias) {
                return;
            }
        }

        throw new RuntimeException(
            "Expected step [{$alias}] to have completed, but no matching observation was found.",
        );
    }

    public function assertAwaitingHuman(Run $run): void
    {
        if ($run->snapshot()->status !== RunStatus::AwaitingInput) {
            throw new RuntimeException(
                'Expected the run to be awaiting human input, but it is ['
                .$run->snapshot()->status->value.'] instead.',
            );
        }
    }

    public function assertProviderAttempts(int $count): void
    {
        $this->gateway->assertRequested(times: $count);
    }

    public function assertRunRecoveredFrom(Run $child, Run $parent): void
    {
        $recovery = $child->snapshot()->recovery;
        if ($recovery === null || $recovery->parentRunId->toString() !== $parent->id()) {
            throw new RuntimeException(
                'Expected the run to be a recovery child of ['.$parent->id().'], but it is not.',
            );
        }
    }

    private function agentAlias(CompletionRequest $request): ?string
    {
        if (str_starts_with($request->purpose, 'agent:')) {
            return substr($request->purpose, 6);
        }

        return null;
    }
}
