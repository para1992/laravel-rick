<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Interface;

interface ArtifactStepBase extends StepBase
{
    /** @return list<string> */
    public function artifactReads(): array;

    /** @return list<string> */
    public function artifactWrites(): array;
}
