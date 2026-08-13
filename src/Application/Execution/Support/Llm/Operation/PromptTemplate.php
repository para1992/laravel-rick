<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Operation;

use InvalidArgumentException;

final readonly class PromptTemplate
{
    /** @param array<string, mixed>|null $outputSchema */
    public function __construct(
        public string $system,
        public string $instruction,
        public ?array $outputSchema = null,
    ) {
        if (trim($system) === '' || trim($instruction) === '') {
            throw new InvalidArgumentException('Prompt template system and instruction must not be empty.');
        }
    }
}
