<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Outbox;

use Illuminate\Database\Connection;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Application\Interface\TransactionBase;

final readonly class OutboxWriter
{
    public function __construct(
        private Connection $database,
        private TenantContextBase $tenant,
        private ClockBase $clock,
        private IdGeneratorBase $ids,
        private TransactionBase $transactions,
        private OutboxRelay $relay,
        private string $table = 'rick_outbox',
    ) {}

    public function record(
        string $kind,
        string $runId,
        ?string $invocationId,
        ?string $eventType,
        ?string $payload,
        string $deduplicationKey,
    ): void {
        $now = $this->clock->now();
        $this->database->table($this->table)->insertOrIgnore([
            'tenant_id' => $this->tenant->id(),
            'id' => $this->ids->generate(),
            'kind' => $kind,
            'run_id' => $runId,
            'invocation_id' => $invocationId,
            'event_type' => $eventType,
            'payload' => $payload,
            'deduplication_key' => $deduplicationKey,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'lease_token' => null,
            'lease_expires_at' => null,
            'delivered_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->transactions->afterCommit(function (): void {
            $this->relay->wake();
        });
    }
}
