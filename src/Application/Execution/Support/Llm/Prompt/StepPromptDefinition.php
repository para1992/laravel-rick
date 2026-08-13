<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Prompt;

use InvalidArgumentException;

final readonly class StepPromptDefinition
{
    public function __construct(
        public string $id,
        public string $version,
        public string $system,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1) {
            throw new InvalidArgumentException('Step prompt ID must be a stable identifier.');
        }
        if (trim($version) === '') {
            throw new InvalidArgumentException('Step prompt version must not be empty.');
        }
        if (trim($system) === '') {
            throw new InvalidArgumentException('Step system prompt must not be empty.');
        }
    }

    public function hash(): string
    {
        return hash('sha256', $this->id."\0".$this->version."\0".$this->system);
    }
}
