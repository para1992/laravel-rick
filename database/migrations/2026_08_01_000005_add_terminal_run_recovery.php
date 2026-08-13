<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $runs = $this->table('runs', 'rick_runs');
        foreach ([
            'parent_run_id' => static fn (Blueprint $table) => $table->string('parent_run_id', 128)->nullable(),
            'recovery_action' => static fn (Blueprint $table) => $table->string('recovery_action', 32)->nullable(),
            'recovery_step_id' => static fn (Blueprint $table) => $table->string('recovery_step_id', 128)->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn($runs, $column)) {
                Schema::table($runs, static function (Blueprint $table) use ($definition): void {
                    $definition($table);
                });
            }
        }
        if (! Schema::hasIndex($runs, 'rick_runs_parent_action_uq')) {
            Schema::table($runs, static function (Blueprint $table): void {
                $table->unique(
                    ['tenant_id', 'parent_run_id', 'recovery_action'],
                    'rick_runs_parent_action_uq',
                );
            });
        }

        $invocations = $this->table('llm_invocations', 'rick_llm_invocations');
        foreach ([
            'source_run_id' => static fn (Blueprint $table) => $table->string('source_run_id', 128)->nullable(),
            'source_invocation_id' => static fn (Blueprint $table) => $table->string('source_invocation_id', 128)->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn($invocations, $column)) {
                Schema::table($invocations, static function (Blueprint $table) use ($definition): void {
                    $definition($table);
                });
            }
        }
        if (! Schema::hasIndex($invocations, 'rick_inv_source_ix')) {
            Schema::table($invocations, static function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'source_run_id', 'source_invocation_id'],
                    'rick_inv_source_ix',
                );
            });
        }
    }

    public function down(): void
    {
        $invocations = $this->table('llm_invocations', 'rick_llm_invocations');
        if (Schema::hasIndex($invocations, 'rick_inv_source_ix')) {
            Schema::table($invocations, static fn (Blueprint $table) => $table->dropIndex('rick_inv_source_ix'));
        }
        foreach (['source_invocation_id', 'source_run_id'] as $column) {
            if (Schema::hasColumn($invocations, $column)) {
                Schema::table($invocations, static fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        $runs = $this->table('runs', 'rick_runs');
        if (Schema::hasIndex($runs, 'rick_runs_parent_action_uq')) {
            Schema::table($runs, static fn (Blueprint $table) => $table->dropUnique('rick_runs_parent_action_uq'));
        }
        foreach (['recovery_step_id', 'recovery_action', 'parent_run_id'] as $column) {
            if (Schema::hasColumn($runs, $column)) {
                Schema::table($runs, static fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function table(string $key, string $default): string
    {
        $configured = config('rick.tables.'.$key, $default);
        if (
            ! is_string($configured)
            || strlen($configured) > 63
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $configured) !== 1
        ) {
            throw new InvalidArgumentException("Rick table name [{$key}] is invalid.");
        }

        return $configured;
    }
};
