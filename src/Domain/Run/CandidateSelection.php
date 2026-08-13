<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class CandidateSelection implements JsonSerializable
{
    public RunId $id;

    public RunStatus $status;

    public int $version;

    public function __construct(
        public WorkflowRunSnapshot $run,
        public bool $continuationQueued,
    ) {
        $this->id = $run->id;
        $this->status = $run->status;
        $this->version = $run->version;
    }

    public function output(): string
    {
        return $this->run->output();
    }

    public function artifact(string $key): Artifact
    {
        return $this->run->artifact($key);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'run_id' => $this->id->toString(),
            'run_version' => $this->version,
            'status' => $this->status->value,
            'continuation_queued' => $this->continuationQueued,
            'run' => $this->run->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
