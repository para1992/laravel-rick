<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Application\Interface\TransactionBase;

final readonly class RunPruner
{
    public function __construct(
        private Connection $database,
        private TransactionBase $transactions,
        private TenantContextBase $tenant,
        private string $runsTable,
        private string $outboxTable,
    ) {}

    public function prune(DateTimeImmutable $cutoff, int $batchSize, bool $dryRun): PruneResult
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Retention batch size must be positive.');
        }

        $ids = $this->eligible($cutoff, $batchSize);
        if ($dryRun || $ids === []) {
            return new PruneResult(count($ids), 0, $ids);
        }

        $deleted = $this->transactions->run(function () use ($cutoff, $ids): int {
            return $this->eligibleQuery($cutoff)
                ->whereIn($this->runsTable.'.id', $ids)
                ->delete();
        });

        return new PruneResult(count($ids), $deleted, $ids);
    }

    /** @return list<string> */
    private function eligible(DateTimeImmutable $cutoff, int $batchSize): array
    {
        return array_values($this->eligibleQuery($cutoff)
            ->orderBy($this->runsTable.'.updated_at')
            ->orderBy($this->runsTable.'.id')
            ->limit($batchSize)
            ->pluck($this->runsTable.'.id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all());
    }

    private function eligibleQuery(DateTimeImmutable $cutoff): Builder
    {
        $tenantId = $this->tenant->id();

        return $this->database->table($this->runsTable)
            ->where($this->runsTable.'.tenant_id', $tenantId)
            ->whereIn($this->runsTable.'.status', ['completed', 'failed', 'cancelled'])
            ->where($this->runsTable.'.updated_at', '<', $cutoff)
            ->whereNotExists(function (Builder $outbox) use ($tenantId): void {
                $outbox->selectRaw('1')
                    ->from($this->outboxTable)
                    ->whereColumn($this->outboxTable.'.run_id', $this->runsTable.'.id')
                    ->where($this->outboxTable.'.tenant_id', $tenantId)
                    ->where($this->outboxTable.'.status', '!=', 'delivered');
            });
    }
}
