<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Builder;

use LogicException;
use Rick\Laravel\Domain\Workflow\OperationCall;

final class ParallelBuilder
{
    /** @var list<OperationCall> */
    private array $calls = [];

    /**
     * @param  list<string>  $reads
     * @param  array<string, mixed>  $parameters
     */
    public function operation(
        string $id,
        string $operation,
        string $output,
        array $reads = [],
        array $parameters = [],
        ?string $version = null,
    ): self {
        $this->calls[] = new OperationCall(
            $id,
            $operation,
            $version,
            $reads,
            $output,
            $parameters,
        );

        return $this;
    }

    /** @return non-empty-list<OperationCall> */
    public function calls(): array
    {
        if ($this->calls === []) {
            throw new LogicException('A parallel workflow group requires at least one operation.');
        }

        return $this->calls;
    }
}
