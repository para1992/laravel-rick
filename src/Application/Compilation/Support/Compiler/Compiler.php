<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Compiler;

use Rick\Laravel\Application\Compilation\Exception\StrategyAmbiguousException;
use Rick\Laravel\Application\Compilation\Exception\StrategyNotFoundException;
use Rick\Laravel\Application\Compilation\Interface\CompilerBase;
use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;
use Rick\Laravel\Application\Compilation\Interface\PlanBase;
use Rick\Laravel\Application\Compilation\Interface\StrategyBase;

final readonly class Compiler implements CompilerBase
{
    /** @var list<StrategyBase> */
    private array $strategies;

    /** @param iterable<StrategyBase> $strategies */
    public function __construct(iterable $strategies)
    {
        $materialized = [];

        foreach ($strategies as $strategy) {
            $materialized[] = $strategy;
        }

        $this->strategies = $materialized;
    }

    public function compile(DefinitionBase $definition): PlanBase
    {
        $matches = [];

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($definition)) {
                $matches[] = $strategy;
            }
        }

        if ($matches === []) {
            throw StrategyNotFoundException::for($definition);
        }

        if (count($matches) > 1) {
            throw StrategyAmbiguousException::for($definition, count($matches));
        }

        return $matches[0]->compile($definition);
    }
}
