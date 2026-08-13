<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = $this->tables();

        if (! Schema::hasColumn($tables['invocation_attempts'], 'http_status_class')) {
            Schema::table($tables['invocation_attempts'], static function (Blueprint $table): void {
                $table->string('http_status_class', 3)->nullable();
            });
        }

        if (! Schema::hasTable($tables['observations'])) {
            Schema::create($tables['observations'], static function (Blueprint $table) use ($tables): void {
                $table->bigIncrements('sequence');
                $table->string('tenant_id', 128);
                $table->string('run_id', 128);
                $table->string('observation_id', 64);
                $table->unique(
                    ['tenant_id', 'run_id', 'observation_id'],
                    'rick_observations_tenant_run_id_uq',
                );
                $table->index(
                    ['tenant_id', 'run_id', 'sequence'],
                    'rick_observations_tenant_run_seq_ix',
                );
                $table->foreign(['tenant_id', 'run_id'], 'rick_observations_run_fk')
                    ->references(['tenant_id', 'id'])
                    ->on($tables['runs'])
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = $this->tables();

        Schema::dropIfExists($tables['observations']);

        if (Schema::hasColumn($tables['invocation_attempts'], 'http_status_class')) {
            Schema::table($tables['invocation_attempts'], static function (Blueprint $table): void {
                $table->dropColumn('http_status_class');
            });
        }
    }

    /** @return array{invocation_attempts: string, runs: string, observations: string} */
    private function tables(): array
    {
        $configured = config('rick.tables', []);
        if (! is_array($configured)) {
            throw new InvalidArgumentException('Rick table configuration must be an array.');
        }

        $defaults = [
            'invocation_attempts' => 'rick_invocation_attempts',
            'runs' => 'rick_runs',
            'observations' => 'rick_run_observations',
        ];
        $tables = [];

        foreach ($defaults as $key => $default) {
            $name = $configured[$key] ?? $default;
            if (
                ! is_string($name)
                || strlen($name) > 63
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1
            ) {
                throw new InvalidArgumentException("Rick table name [{$key}] is invalid.");
            }
            $tables[$key] = $name;
        }

        return $tables;
    }
};
