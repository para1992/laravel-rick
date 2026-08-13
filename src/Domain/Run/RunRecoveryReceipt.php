<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class RunRecoveryReceipt implements JsonSerializable
{
    public RunId $id;

    public RunStatus $status;

    public int $version;

    public function __construct(
        public WorkflowRunSnapshot $run,
        public int $reusedInvocations,
        public int $queuedInvocations,
        public int $copiedFailures,
        public bool $alreadyExists = false,
        public int $attempts = 1,
    ) {
        $this->id = $run->id;
        $this->status = $run->status;
        $this->version = $run->version;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'run_id' => $this->id->toString(),
            'run_version' => $this->version,
            'status' => $this->status->value,
            'reused_invocations' => $this->reusedInvocations,
            'queued_invocations' => $this->queuedInvocations,
            'copied_failures' => $this->copiedFailures,
            'already_exists' => $this->alreadyExists,
            'attempts' => $this->attempts,
            'run' => $this->run->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
