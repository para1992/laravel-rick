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

        Schema::create($tables['runs'], static function (Blueprint $table): void {
            $table->string('tenant_id', 128);
            $table->string('id', 128);
            $table->string('status', 32);
            $table->unsignedBigInteger('version');
            $table->longText('payload');
            $table->timestamps();
            $table->primary(['tenant_id', 'id'], 'rick_runs_pk');
            $table->index(
                ['tenant_id', 'status', 'updated_at'],
                'rick_runs_tenant_status_updated_ix',
            );
        });

        Schema::create($tables['step_executions'], static function (Blueprint $table) use ($tables): void {
            $table->string('tenant_id', 128);
            $table->string('id', 128);
            $table->string('run_id', 128);
            $table->string('step_id', 128);
            $table->unsignedInteger('sequence');
            $table->string('status', 32);
            $table->unsignedInteger('expected_invocations');
            $table->unsignedInteger('dispatched_invocations')->default(0);
            $table->unsignedBigInteger('version');
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->primary(['tenant_id', 'id'], 'rick_steps_pk');
            $table->unique(
                ['tenant_id', 'run_id', 'step_id', 'sequence'],
                'rick_steps_run_step_seq_uq',
            );
            $table->index(
                ['tenant_id', 'run_id', 'status'],
                'rick_steps_tenant_run_status_ix',
            );
            $table->foreign(['tenant_id', 'run_id'], 'rick_steps_run_fk')
                ->references(['tenant_id', 'id'])
                ->on($tables['runs'])
                ->cascadeOnDelete();
        });

        Schema::create($tables['llm_invocations'], static function (Blueprint $table) use ($tables): void {
            $table->string('tenant_id', 128);
            $table->string('id', 128);
            $table->string('run_id', 128);
            $table->string('step_execution_id', 128);
            $table->string('step_id', 128);
            $table->unsignedInteger('invocation_index');
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('version');
            $table->longText('request_payload');
            $table->longText('response_payload')->nullable();
            $table->longText('metrics_payload')->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();
            $table->primary(['tenant_id', 'id'], 'rick_invocations_pk');
            $table->unique(
                ['tenant_id', 'step_execution_id', 'invocation_index'],
                'rick_inv_step_idx_uq',
            );
            $table->index(
                ['tenant_id', 'run_id', 'status'],
                'rick_inv_tenant_run_status_ix',
            );
            $table->index(
                ['tenant_id', 'lease_expires_at'],
                'rick_inv_tenant_lease_ix',
            );
            $table->foreign(['tenant_id', 'run_id'], 'rick_inv_run_fk')
                ->references(['tenant_id', 'id'])
                ->on($tables['runs'])
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'step_execution_id'], 'rick_inv_step_fk')
                ->references(['tenant_id', 'id'])
                ->on($tables['step_executions'])
                ->cascadeOnDelete();
        });

        Schema::create($tables['invocation_attempts'], static function (Blueprint $table) use ($tables): void {
            $table->string('tenant_id', 128);
            $table->string('id', 128);
            $table->string('invocation_id', 128);
            $table->string('run_id', 128);
            $table->unsignedInteger('attempt_number');
            $table->string('status', 32);
            $table->string('request_fingerprint', 64);
            $table->string('provider_request_id', 128)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->primary(['tenant_id', 'id'], 'rick_attempts_pk');
            $table->unique(
                ['tenant_id', 'invocation_id', 'attempt_number'],
                'rick_attempts_inv_number_uq',
            );
            $table->index(
                ['tenant_id', 'run_id', 'status'],
                'rick_attempts_tenant_run_status_ix',
            );
            $table->foreign(['tenant_id', 'invocation_id'], 'rick_attempts_inv_fk')
                ->references(['tenant_id', 'id'])
                ->on($tables['llm_invocations'])
                ->cascadeOnDelete();
        });

        Schema::create($tables['outbox'], static function (Blueprint $table) use ($tables): void {
            $table->string('tenant_id', 128);
            $table->string('id', 128);
            $table->string('kind', 32);
            $table->string('run_id', 128);
            $table->string('invocation_id', 128)->nullable();
            $table->string('event_type', 128)->nullable();
            $table->longText('payload')->nullable();
            $table->string('deduplication_key', 128);
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->string('lease_token', 128)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedBigInteger('version');
            $table->timestamps();
            $table->primary(['tenant_id', 'id'], 'rick_outbox_pk');
            $table->unique(
                ['tenant_id', 'deduplication_key'],
                'rick_outbox_tenant_dedupe_uq',
            );
            $table->index(
                ['tenant_id', 'status', 'available_at'],
                'rick_outbox_tenant_status_available_ix',
            );
            $table->index(
                ['tenant_id', 'kind', 'status'],
                'rick_outbox_tenant_kind_status_ix',
            );
            $table->index(
                ['tenant_id', 'lease_expires_at'],
                'rick_outbox_tenant_lease_ix',
            );
            $table->index(
                ['tenant_id', 'run_id', 'status'],
                'rick_outbox_tenant_run_status_ix',
            );
            $table->foreign(['tenant_id', 'run_id'], 'rick_outbox_run_fk')
                ->references(['tenant_id', 'id'])
                ->on($tables['runs'])
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $tables = $this->tables();

        Schema::dropIfExists($tables['outbox']);
        Schema::dropIfExists($tables['invocation_attempts']);
        Schema::dropIfExists($tables['llm_invocations']);
        Schema::dropIfExists($tables['step_executions']);
        Schema::dropIfExists($tables['runs']);
    }

    /** @return array{runs: string, step_executions: string, llm_invocations: string, invocation_attempts: string, outbox: string} */
    private function tables(): array
    {
        $configured = config('rick.tables', []);
        if (! is_array($configured)) {
            throw new InvalidArgumentException('Rick table configuration must be an array.');
        }

        $defaults = [
            'runs' => 'rick_runs',
            'step_executions' => 'rick_step_executions',
            'llm_invocations' => 'rick_llm_invocations',
            'invocation_attempts' => 'rick_invocation_attempts',
            'outbox' => 'rick_outbox',
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
