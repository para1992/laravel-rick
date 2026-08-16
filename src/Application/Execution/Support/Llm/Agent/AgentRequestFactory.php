<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Agent;

use BackedEnum;
use Illuminate\Container\Container;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Schemable;
use ReflectionClass;
use Rick\Laravel\Application\Execution\Exception\UnsupportedAgentCapabilityException;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;

final readonly class AgentRequestFactory
{
    /**
     * Re-encodes a Laravel AI agent class into exactly one auditable
     * CompletionRequest. The adapted agent is only ever read for metadata;
     * its prompt() transport is never invoked, so hidden multi-request
     * loops, tool calls, approvals, and multi-turn conversations cannot
     * bypass Rick's single-request accounting.
     *
     * @param  class-string  $agentClass
     * @param  array<string, mixed>  $metadata
     */
    public function create(
        string $agentClass,
        string $alias,
        string $userPrompt,
        string $modelPolicy = 'medium',
        array $metadata = [],
    ): CompletionRequest {
        foreach ([HasTools::class, Conversational::class] as $capability) {
            if (is_a($agentClass, $capability, true)) {
                throw new UnsupportedAgentCapabilityException(
                    sprintf(
                        'Agent class [%s] declares the unsupported capability [%s]; it can issue multiple provider requests that Rick cannot account for in one audited call.',
                        $agentClass,
                        $capability,
                    ),
                    $capability,
                );
            }
        }

        // Approvable exists only on Laravel AI >= 0.10; guard it by string so
        // the 0.9 lane (which lacks the interface) still compiles and runs.
        if (
            interface_exists('Laravel\\Ai\\Contracts\\Approvable')
            && is_a($agentClass, 'Laravel\\Ai\\Contracts\\Approvable', true)
        ) {
            throw new UnsupportedAgentCapabilityException(
                sprintf(
                    'Agent class [%s] declares the unsupported capability [%s]; it can issue multiple provider requests that Rick cannot account for in one audited call.',
                    $agentClass,
                    'Laravel\\Ai\\Contracts\\Approvable',
                ),
                'Laravel\\Ai\\Contracts\\Approvable',
            );
        }

        if (! is_a($agentClass, Agent::class, true)) {
            throw new UnsupportedAgentCapabilityException(
                sprintf(
                    'Class [%s] does not implement %s, so Rick cannot read its instructions.',
                    $agentClass,
                    Agent::class,
                ),
                Agent::class,
            );
        }

        /** @var Agent $agent */
        $agent = Container::getInstance()->make($agentClass);

        $responseContract = ResponseContract::Text;
        $responseSchema = null;
        if (is_a($agentClass, Schemable::class, true)) {
            /** @var Agent&Schemable $agent */
            $schema = $agent->toSchema();
            if ($schema === []) {
                throw new UnsupportedAgentCapabilityException(
                    sprintf(
                        'Agent class [%s] declares an empty schema; Rick requires a non-empty schema for structured adaptation.',
                        $agentClass,
                    ),
                    Schemable::class,
                );
            }
            $responseContract = ResponseContract::Json;
            $responseSchema = $schema;
        }

        return new CompletionRequest(
            [
                new Message('system', (string) $agent->instructions()),
                new Message('user', $userPrompt),
            ],
            $responseContract,
            'agent:'.$alias,
            $modelPolicy,
            options: $this->agentOptions($agentClass),
            metadata: [
                'agent_class' => $agentClass,
                'agent_alias' => $alias,
            ] + $metadata,
            responseSchema: $responseSchema,
        );
    }

    /**
     * @param  class-string  $agentClass
     * @return array<string, mixed>
     */
    private function agentOptions(string $agentClass): array
    {
        $reflection = new ReflectionClass($agentClass);

        $options = [];
        $provider = $reflection->getAttributes(Provider::class)[0] ?? null;
        if ($provider !== null) {
            $value = $provider->newInstance()->value;
            $options['provider'] = $value instanceof BackedEnum ? $value->value : $value;
        }
        $model = $reflection->getAttributes(Model::class)[0] ?? null;
        if ($model !== null) {
            $options['model'] = $model->newInstance()->value;
        }
        $maxTokens = $reflection->getAttributes(MaxTokens::class)[0] ?? null;
        if ($maxTokens !== null) {
            $options['max_tokens'] = $maxTokens->newInstance()->value;
        }
        $temperature = $reflection->getAttributes(Temperature::class)[0] ?? null;
        if ($temperature !== null) {
            $options['temperature'] = $temperature->newInstance()->value;
        }
        $topP = $reflection->getAttributes(TopP::class)[0] ?? null;
        if ($topP !== null) {
            $options['top_p'] = $topP->newInstance()->value;
        }

        return $options;
    }
}
