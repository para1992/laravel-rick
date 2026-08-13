<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Operation;

use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

final readonly class OperationContext
{
    /**
     * @param  array<string, Artifact>  $inputs
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function __construct(
        public WorkflowRunSnapshot $run,
        public array $inputs,
        public string $outputKey,
        public array $parameters = [],
        public int $attempt = 1,
        public ?array $responseSchema = null,
        public ?int $structuredResponseAttempts = null,
    ) {}

    /** @return array<string, mixed> */
    public function promptPayload(): array
    {
        return [
            'task' => $this->run->task,
            'definition_of_done' => $this->run->dod->value(),
            'inputs' => array_map(
                static fn (Artifact $artifact): array => $artifact->toArray(),
                $this->inputs,
            ),
            'parameters' => $this->parameters,
            'output_key' => $this->outputKey,
        ];
    }
}
