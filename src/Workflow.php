<?php

declare(strict_types=1);

namespace Rick\Laravel;

use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

/**
 * A Laravel-native workflow class. The author declares a stable name, an
 * explicit version, and a build() method that produces a deterministic,
 * persisted, versioned graph of steps. start() compiles that graph and runs it
 * through the canonical Rick engine — there is no second runtime.
 *
 * Subclasses must remain constructible with no constructor arguments so that
 * start() and definition() can instantiate them through new static().
 *
 * @phpstan-consistent-constructor
 */
abstract class Workflow
{
    abstract public function name(): string;

    public function version(): string
    {
        return '1.0.0';
    }

    abstract public function build(WorkflowBuilder $workflow): WorkflowBuilder;

    public static function definition(): WorkflowDefinition
    {
        $workflow = new static;

        return $workflow->build(new WorkflowBuilder($workflow->name()))
            ->version($workflow->version())
            ->build();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function start(array $input = [], int $callLimit = 60): Run
    {
        $rick = app(Rick::class);
        $snapshot = $rick->run(static::definition(), $input, $callLimit);

        return Run::of($rick, $snapshot);
    }
}
