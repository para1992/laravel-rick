<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = $this->attemptsTable();
        $columns = [
            'gateway_invocation_id' => static fn (Blueprint $blueprint) => $blueprint->string('gateway_invocation_id', 128)->nullable(),
            'provider_generation_id' => static fn (Blueprint $blueprint) => $blueprint->string('provider_generation_id', 128)->nullable(),
            'provider_id_source' => static fn (Blueprint $blueprint) => $blueprint->string('provider_id_source', 16)->nullable(),
            'provider' => static fn (Blueprint $blueprint) => $blueprint->string('provider', 128)->nullable(),
            'model' => static fn (Blueprint $blueprint) => $blueprint->string('model', 255)->nullable(),
            'resolved_route' => static fn (Blueprint $blueprint) => $blueprint->string('resolved_route', 512)->nullable(),
            'model_tier' => static fn (Blueprint $blueprint) => $blueprint->string('model_tier', 128)->nullable(),
            'metrics_payload' => static fn (Blueprint $blueprint) => $blueprint->longText('metrics_payload')->nullable(),
            'diagnostic_payload' => static fn (Blueprint $blueprint) => $blueprint->longText('diagnostic_payload')->nullable(),
            'provider_request_outcome' => static fn (Blueprint $blueprint) => $blueprint->string('provider_request_outcome', 32)->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (Schema::hasColumn($table, $name)) {
                continue;
            }
            Schema::table($table, static function (Blueprint $blueprint) use ($definition): void {
                $definition($blueprint);
            });
        }

        DB::table($table)
            ->whereNotNull('provider_request_id')
            ->orderBy('tenant_id')
            ->orderBy('id')
            ->chunk(100, static function ($rows) use ($table): void {
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
            });
    }

    public function down(): void
    {
        $table = $this->attemptsTable();
        foreach ([
            'gateway_invocation_id',
            'provider_generation_id',
            'provider_id_source',
            'provider',
            'model',
            'resolved_route',
            'model_tier',
            'metrics_payload',
            'diagnostic_payload',
            'provider_request_outcome',
        ] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }
            Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropColumn($column);
            });
        }
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
