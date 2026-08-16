<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $table = $this->attemptsTable();

        DB::table($table)
            ->whereNull('provider_id_source')
            ->whereNotNull('provider_request_id')
            ->chunkById(100, static function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $id = is_string($row->id ?? null) ? $row->id : null;
                    $tenantId = is_string($row->tenant_id ?? null) ? $row->tenant_id : null;
                    $legacy = is_string($row->provider_request_id ?? null)
                        ? $row->provider_request_id
                        : null;
                    if ($id === null || $tenantId === null || $legacy === null) {
                        continue;
                    }
                    $gatewayId = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                        $legacy,
                    ) === 1;
                    DB::table($table)
                        ->where('tenant_id', $tenantId)
                        ->where('id', $id)
                        ->update($gatewayId
                        ? [
                            'gateway_invocation_id' => $legacy,
                            'provider_request_id' => null,
                            'provider_id_source' => 'unavailable',
                        ]
                        : ['provider_id_source' => 'header']);
                }
            }, 'id', 'id');
    }

    public function down(): void
    {
        // Data-only repair of the 000003 backfill. The affected columns are
        // added and dropped by 000003 itself; there is no safe inverse here.
    }

    private function attemptsTable(): string
    {
        $configured = config('rick.tables.invocation_attempts', 'rick_invocation_attempts');
        if (
            ! is_string($configured)
            || strlen($configured) > 63
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $configured) !== 1
        ) {
            throw new InvalidArgumentException('Rick invocation attempts table name is invalid.');
        }

        return $configured;
    }
};
