<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Operation;

use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Support\Llm\ModelPolicy;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;

final readonly class LlmOperationDefinition
{
    /** @param list<string> $validatorSetIds */
    public function __construct(
        public string $id,
        public string $version,
        public PromptTemplate $prompt,
        public ResponseContract $responseContract,
        public ArtifactType $outputType,
        public ModelPolicy $model,
        public array $validatorSetIds = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1) {
            throw new InvalidArgumentException("Invalid LLM operation id [{$id}].");
        }
        if (trim($version) === '') {
            throw new InvalidArgumentException('LLM operation version must not be empty.');
        }
        if ($responseContract === ResponseContract::Json && $prompt->outputSchema === null) {
            throw new InvalidArgumentException('JSON LLM operations require an output schema.');
        }
    }
}
