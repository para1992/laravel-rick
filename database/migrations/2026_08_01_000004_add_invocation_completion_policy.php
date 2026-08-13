<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = $this->executionsTable();
        if (! Schema::hasColumn($table, 'completion_policy')) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->string('completion_policy', 32)->default('all_required');
            });
        }
        if (! Schema::hasColumn($table, 'minimum_successful_invocations')) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->unsignedInteger('minimum_successful_invocations')->nullable();
            });
        }
    }

    public function down(): void
    {
        $table = $this->executionsTable();
        foreach (['minimum_successful_invocations', 'completion_policy'] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }
            Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropColumn($column);
            });
        }
    }

    private function executionsTable(): string
    {
        $configured = config('rick.tables.step_executions', 'rick_step_executions');
        if (
            ! is_string($configured)
            || strlen($configured) > 63
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $configured) !== 1
        ) {
            throw new InvalidArgumentException('Rick step executions table name is invalid.');
        }

        return $configured;
    }
};
