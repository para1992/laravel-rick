<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality;

use OutOfBoundsException;

final class RuleSetRegistry
{
    /** @var array<string, RuleSet> */
    private array $sets = [];

    /** @param iterable<RuleSet> $sets */
    public function __construct(iterable $sets = [])
    {
        foreach ($sets as $set) {
            $this->register($set);
        }
    }

    public function register(RuleSet $set): void
    {
        $this->sets[$set->id] = $set;
    }

    public function get(string $id): RuleSet
    {
        return $this->sets[$id]
            ?? throw new OutOfBoundsException("Quality rule set [{$id}] is not registered.");
    }
}
