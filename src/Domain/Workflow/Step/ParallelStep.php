<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class ParallelStep implements ArtifactStepBase, StepBase
{
    /** @param list<OperationCall> $calls */
    public function __construct(private StepId $id, public array $calls)
    {
        if ($calls === []) {
            throw new InvalidArgumentException('A parallel step requires at least one operation call.');
        }
        $outputs = [];
        foreach ($calls as $call) {
            if (isset($outputs[$call->outputKey])) {
                throw new InvalidArgumentException("Parallel output [{$call->outputKey}] is duplicated.");
            }
            $outputs[$call->outputKey] = true;
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::parallel();
    }

    public function artifactReads(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            static fn (OperationCall $call): array => $call->inputKeys,
            $this->calls,
        ))));
    }

    public function artifactWrites(): array
    {
        return array_map(static fn (OperationCall $call): string => $call->outputKey, $this->calls);
    }
}
