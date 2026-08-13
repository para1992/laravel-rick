<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Rick\Laravel\Application\Execution\Exception\ConcurrentExecutionModificationException;
use Rick\Laravel\Application\Execution\Exception\ExecutionRecordNotFoundException;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonRunStateCodec;

final readonly class DatabaseRunRepository implements RunRepositoryBase
{
    public function __construct(
        private ConnectionInterface $database,
        private JsonRunStateCodec $codec,
        private PayloadProtectorBase $payloads,
        private TenantContextBase $tenant,
        private string $table = 'rick_runs',
    ) {}

    public function add(WorkflowRun $run): void
    {
        $this->database->table($this->table)->insert($this->row($run));
    }

    public function addRecovery(WorkflowRun $run): bool
    {
        if ($run->snapshot()->recovery === null) {
            throw new \InvalidArgumentException('A recovery insert requires recovery provenance.');
        }

        return $this->database->table($this->table)->insertOrIgnore($this->row($run)) === 1;
    }

    public function findRecovery(RunId $parentRunId, RunRecoveryAction $action): ?WorkflowRun
    {
        $row = $this->database->table($this->table)
            ->where('tenant_id', $this->tenant->id())
            ->where('parent_run_id', $parentRunId->toString())
            ->where('recovery_action', $action->value)
            ->first();

        return $row === null ? null : $this->decode($row);
    }

    /** @return array<string, mixed> */
    private function row(WorkflowRun $run): array
    {
        $now = new DateTimeImmutable;
        $recovery = $run->snapshot()->recovery;

        return [
            'id' => $run->id()->toString(),
            'tenant_id' => $this->tenant->id(),
            'status' => $run->snapshot()->status->value,
            'version' => $run->version(),
            'payload' => $this->payloads->protect($this->codec->encode($run)),
            'parent_run_id' => $recovery?->parentRunId->toString(),
            'recovery_action' => $recovery?->action->value,
            'recovery_step_id' => $recovery?->stepId->toString(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function get(RunId $id): WorkflowRun
    {
        $row = $this->database->table($this->table)
            ->where('tenant_id', $this->tenant->id())
            ->where('id', $id->toString())
            ->first();

        if ($row === null) {
            throw ExecutionRecordNotFoundException::for($id->toString());
        }

        return $this->decode($row);
    }

    public function save(WorkflowRun $run, int $expectedVersion): void
    {
        $updated = $this->database->table($this->table)
            ->where('tenant_id', $this->tenant->id())
            ->where('id', $run->id()->toString())
            ->where('version', $expectedVersion)
            ->update([
                'status' => $run->snapshot()->status->value,
                'version' => $run->version(),
                'payload' => $this->payloads->protect($this->codec->encode($run)),
                'updated_at' => new DateTimeImmutable,
            ]);

        if ($updated !== 1) {
            throw ConcurrentExecutionModificationException::for($run->id()->toString());
        }
    }

    private function decode(object $row): WorkflowRun
    {
        return $this->codec->decode(
            $this->payloads->reveal(DatabaseRow::from($row)->string('payload')),
        );
    }
}
