<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm;

use InvalidArgumentException;

final readonly class ModelPolicy
{
    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $escalationTiers
     */
    public function __construct(
        public string $id,
        public string $tier = 'medium',
        public array $options = [],
        public array $escalationTiers = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1) {
            throw new InvalidArgumentException("Invalid model policy id [{$id}].");
        }
        if (trim($tier) === '') {
            throw new InvalidArgumentException('Model policy tier must not be empty.');
        }
    }

    public function tierForAttempt(int $attempt): string
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Model attempt must be at least 1.');
        }
        if ($attempt === 1 || $this->escalationTiers === []) {
            return $this->tier;
        }

        return $this->escalationTiers[min($attempt - 2, count($this->escalationTiers) - 1)];
    }

    /** @return non-empty-list<string> */
    public function tiers(): array
    {
        return [$this->tier, ...$this->escalationTiers];
    }
}
