<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Infrastructure\Persistence\DatabaseRow;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\TestCase;

final class MigrationPortabilityTest extends TestCase
{
    public function test_all_business_identifiers_are_portable_varchar_128_columns(): void
    {
        $identifierColumns = [
            'rick_runs' => ['tenant_id', 'id'],
            'rick_step_executions' => ['tenant_id', 'id', 'run_id', 'step_id'],
            'rick_llm_invocations' => ['tenant_id', 'id', 'run_id', 'step_execution_id', 'step_id'],
            'rick_invocation_attempts' => [
                'tenant_id',
                'id',
                'invocation_id',
                'run_id',
                'gateway_invocation_id',
                'provider_request_id',
                'provider_generation_id',
            ],
            'rick_outbox' => ['tenant_id', 'id', 'run_id', 'invocation_id'],
            'rick_run_observations' => ['tenant_id', 'run_id'],
        ];

        foreach ($identifierColumns as $table => $expected) {
            $columns = $this->columns($table);
            foreach ($expected as $column) {
                self::assertArrayHasKey($column, $columns);
                self::assertSame('varchar', $columns[$column], "{$table}.{$column}");
            }
        }

        $migration = $this->migrationSource();
        foreach ([
            'tenant_id' => 6,
            'id' => 5,
            'run_id' => 5,
            'step_id' => 2,
            'step_execution_id' => 1,
            'invocation_id' => 2,
        ] as $column => $occurrences) {
            self::assertSame(
                $occurrences,
                substr_count($migration, "->string('{$column}', 128)"),
                $column,
            );
        }
    }

    public function test_explicit_constraint_and_index_names_are_short_and_tenant_first(): void
    {
        $migration = $this->migrationSource();
        $matched = preg_match_all(
            "/'((?:rick_)[a-z0-9_]+_(?:pk|fk|uq|ix))'/",
            $migration,
            $names,
        );
        self::assertIsInt($matched);
        self::assertGreaterThan(0, $matched);

        foreach ($names[1] as $name) {
            self::assertLessThanOrEqual(63, strlen($name), $name);
        }
        self::assertStringNotContainsString("->index(['status'", $migration);
        self::assertStringNotContainsString("->index(['lease_expires_at'", $migration);
    }

    public function test_sqlite_foreign_keys_are_composite_and_cascade_children(): void
    {
        if ($this->database()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('SQLite-specific schema introspection.');
        }

        foreach ([
            'rick_step_executions',
            'rick_llm_invocations',
            'rick_invocation_attempts',
            'rick_outbox',
            'rick_run_observations',
        ] as $table) {
            $foreignKeys = $this->database()->select("PRAGMA foreign_key_list({$table})");
            self::assertNotEmpty($foreignKeys, $table);
            $columns = [];
            foreach ($foreignKeys as $foreignKey) {
                if (! is_object($foreignKey)) {
                    self::fail("Foreign-key metadata for [{$table}] is not an object.");
                }
                $row = DatabaseRow::from($foreignKey);
                $columns[] = $row->string('from');
                self::assertSame('CASCADE', $row->string('on_delete'));
            }
            self::assertContains('tenant_id', $columns, $table);
        }
    }

    public function test_observability_migration_upgrades_the_version_0_1_schema_idempotently(): void
    {
        Schema::dropIfExists('rick_run_observations');
        Schema::table('rick_invocation_attempts', static function (Blueprint $table): void {
            $table->dropColumn('http_status_class');
        });

        self::assertFalse(Schema::hasTable('rick_run_observations'));
        self::assertFalse(Schema::hasColumn('rick_invocation_attempts', 'http_status_class'));

        $migration = require dirname(__DIR__, 2)
            .'/database/migrations/2026_08_01_000002_add_rick_observability_fields.php';
        if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
            self::fail('Observability migration must expose an up method.');
        }

        $migration->up();
        $migration->up();

        self::assertTrue(Schema::hasTable('rick_run_observations'));
        self::assertTrue(Schema::hasColumn('rick_invocation_attempts', 'http_status_class'));
    }

    public function test_incident_migrations_are_idempotent_and_reclassify_legacy_gateway_ids(): void
    {
        $this->application()->instance(
            GatewayBase::class,
            (new FakeGateway)->respond(new CompletionResponse(structured: ['content' => 'valid'])),
        );
        $rick = $this->application()->make(Rick::class);
        $rick->run($rick->workflow('legacy-attempt-id')
            ->resolve('Create a candidate', 'One candidate is valid')
            ->generate('draft')
            ->build());
        $legacyGatewayId = '019fbdd1-ab7c-73c6-bbe7-6f0e223b2c44';
        $database = $this->database();
        $database->table('rick_invocation_attempts')->update([
            'gateway_invocation_id' => null,
            'provider_request_id' => $legacyGatewayId,
            'provider_id_source' => 'sdk',
        ]);
        $providerUuid = '8f14e45f-ea6b-4f67-9f2f-1de7a3c2b456';
        $providerAttemptId = 'provider-uuid-attempt';
        $legacyAttempt = (array) $database->table('rick_invocation_attempts')->first();
        $legacyAttempt['id'] = $providerAttemptId;
        $legacyAttempt['attempt_number'] = 2;
        $legacyAttempt['provider_request_id'] = $providerUuid;
        $legacyAttempt['provider_id_source'] = 'header';
        $database->table('rick_invocation_attempts')->insert($legacyAttempt);

        $diagnostics = require dirname(__DIR__, 2)
            .'/database/migrations/2026_08_01_000003_add_invocation_attempt_diagnostics.php';
        $quorum = require dirname(__DIR__, 2)
            .'/database/migrations/2026_08_01_000004_add_invocation_completion_policy.php';
        $terminalRecovery = require dirname(__DIR__, 2)
            .'/database/migrations/2026_08_01_000005_add_terminal_run_recovery.php';
        if (
            ! $diagnostics instanceof Migration
            || ! $quorum instanceof Migration
            || ! $terminalRecovery instanceof Migration
        ) {
            self::fail('Incident migrations must be Laravel migrations.');
        }

        self::invokeMigration($terminalRecovery, 'down');
        self::invokeMigration($quorum, 'down');
        self::invokeMigration($diagnostics, 'down');
        self::assertFalse(Schema::hasColumn('rick_invocation_attempts', 'gateway_invocation_id'));
        self::assertFalse(Schema::hasColumn('rick_step_executions', 'completion_policy'));
        self::assertFalse(Schema::hasColumn('rick_runs', 'parent_run_id'));
        self::assertFalse(Schema::hasColumn('rick_llm_invocations', 'source_invocation_id'));

        self::invokeMigration($diagnostics, 'up');
        self::invokeMigration($diagnostics, 'up');
        self::invokeMigration($quorum, 'up');
        self::invokeMigration($quorum, 'up');
        self::invokeMigration($terminalRecovery, 'up');
        self::invokeMigration($terminalRecovery, 'up');

        $attempt = DatabaseRow::from(
            $database->table('rick_invocation_attempts')->first()
                ?? throw new \RuntimeException('Missing migrated invocation attempt.'),
        );
        self::assertSame($legacyGatewayId, $attempt->string('gateway_invocation_id'));
        self::assertNull($attempt->nullableString('provider_request_id'));
        self::assertSame('unavailable', $attempt->string('provider_id_source'));
        $providerAttempt = DatabaseRow::from(
            $database->table('rick_invocation_attempts')->where('id', $providerAttemptId)->first()
                ?? throw new \RuntimeException('Missing provider UUID attempt.'),
        );
        self::assertNull($providerAttempt->nullableString('gateway_invocation_id'));
        self::assertSame($providerUuid, $providerAttempt->string('provider_request_id'));
        self::assertSame('header', $providerAttempt->string('provider_id_source'));
        self::assertSame(
            'all_required',
            $database->table('rick_step_executions')->value('completion_policy'),
        );
        self::assertTrue(Schema::hasColumn('rick_runs', 'parent_run_id'));
        self::assertTrue(Schema::hasColumn('rick_llm_invocations', 'source_invocation_id'));
    }

    /** @param 'down'|'up' $method */
    private static function invokeMigration(Migration $migration, string $method): void
    {
        if (! method_exists($migration, $method)) {
            self::fail("Incident migration must implement [{$method}].");
        }

        (new \ReflectionMethod($migration, $method))->invoke($migration);
    }

    /** @return array<string, string> */
    private function columns(string $table): array
    {
        $columns = [];
        foreach ($this->database()->getSchemaBuilder()->getColumns($table) as $column) {
            $name = $column['name'];
            $columns[$name] = $column['type_name'];
        }

        return $columns;
    }

    private function database(): Connection
    {
        return $this->application()->make(DatabaseManager::class)->connection();
    }

    private function migrationSource(): string
    {
        $paths = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        self::assertIsArray($paths);
        sort($paths);

        $source = '';
        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            $source .= $contents;
        }

        return $source;
    }
}
