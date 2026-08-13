<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Migration\LegacyDataMigration;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionRequestCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonRunStateCodec;
use Rick\Laravel\Tests\TestCase;

final class LegacyDataMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        try {
            if ($this->app !== null) {
                $this->dropLegacyTables(
                    $this->application()->make(DatabaseManager::class)->connection(),
                );
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_it_copies_encrypted_legacy_rows_and_quarantines_in_flight_calls_idempotently(): void
    {
        $database = $this->application()->make(DatabaseManager::class)->connection();
        $this->createLegacyTables($database);
        $run = $this->legacyRun();
        $runPayload = $this->legacyRunPayload($run);
        $requestPayload = $this->legacyRequestPayload();
        $now = '2026-07-26 12:00:00';

        $database->table('legacy_rick_runs')->insert([
            'tenant_id' => null,
            'id' => 'legacy-run',
            'status' => 'created',
            'version' => $run->version(),
            'payload' => $runPayload,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table('legacy_rick_step_executions')->insert([
            'tenant_id' => null,
            'id' => 'legacy-execution',
            'run_id' => 'legacy-run',
            'step_id' => '001_resolve',
            'status' => 'running',
            'expected_invocations' => 1,
            'dispatched_invocations' => 1,
            'version' => 3,
            'error_code' => null,
            'error_message' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table('legacy_rick_llm_invocations')->insert([
            'tenant_id' => null,
            'id' => 'legacy-invocation',
            'run_id' => 'legacy-run',
            'step_execution_id' => 'legacy-execution',
            'step_id' => '001_resolve',
            'invocation_index' => 0,
            'status' => 'running',
            'attempts' => 1,
            'version' => 4,
            'request_payload' => $requestPayload,
            'response_payload' => null,
            'error_code' => null,
            'error_message' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $counts = $this->application()->make(LegacyDataMigration::class)->migrate('default', 1);

        self::assertSame([
            'runs' => 1,
            'step_executions' => 1,
            'llm_invocations' => 1,
            'indeterminate' => 1,
        ], $counts);
        self::assertSame(
            'legacy-run',
            $this->application()->make(RunRepositoryBase::class)
                ->get(RunId::fromString('legacy-run'))
                ->id()
                ->toString(),
        );
        self::assertSame(
            InvocationStatus::Indeterminate,
            $this->application()->make(ExecutionRepositoryBase::class)
                ->getInvocation(InvocationId::fromString('legacy-invocation'))
                ->status(),
        );
        self::assertSame(
            'indeterminate',
            $database->table('rick_invocation_attempts')
                ->where('invocation_id', 'legacy-invocation')
                ->value('status'),
        );
        self::assertSame(
            1,
            $database->table('rick_llm_invocations')
                ->where('id', 'legacy-invocation')
                ->count(),
        );
        self::assertNotSame(
            $requestPayload,
            $database->table('rick_llm_invocations')
                ->where('id', 'legacy-invocation')
                ->value('request_payload'),
        );

        $this->application()->make(LegacyDataMigration::class)->migrate('default', 1);

        self::assertSame(1, $database->table('rick_runs')->where('id', 'legacy-run')->count());
        self::assertSame(1, $database->table('rick_llm_invocations')->where('id', 'legacy-invocation')->count());
        self::assertSame(1, $database->table('rick_invocation_attempts')->where('invocation_id', 'legacy-invocation')->count());
        self::assertSame($runPayload, $database->table('legacy_rick_runs')->value('payload'));
        self::assertSame($requestPayload, $database->table('legacy_rick_llm_invocations')->value('request_payload'));
    }

    private function legacyRun(): WorkflowRun
    {
        return WorkflowRun::start(
            RunId::fromString('legacy-run'),
            new CompiledWorkflow('legacy', '1.0.0', [
                new ResolveStep(
                    StepId::fromString('001_resolve'),
                    'Migrate safely',
                    DefinitionOfDone::fromString('The legacy run remains resumable'),
                ),
            ]),
            new RunInput(['subject' => 'encrypted legacy subject']),
            10,
        );
    }

    private function legacyRunPayload(WorkflowRun $run): string
    {
        $current = JsonInput::map(
            json_decode(
                $this->application()->make(JsonRunStateCodec::class)->encode($run),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            'run',
        );
        $legacyState = JsonInput::map($current['run'] ?? null, 'run.run');
        $legacyState['schema_version'] = 2;
        $legacyState['dod'] = [
            'automatic' => $legacyState['dod_automatic'],
            'value' => $legacyState['dod'],
        ];
        unset($legacyState['dod_automatic']);
        $workflow = JsonInput::map($legacyState['workflow'] ?? null, 'run.run.workflow');
        $steps = [];
        foreach (JsonInput::list($workflow['steps'] ?? null, 'run.run.workflow.steps') as $index => $value) {
            $step = JsonInput::map($value, "run.run.workflow.steps.{$index}");
            unset($step['schema_version']);
            if (isset($step['model_policy_id'])) {
                $step['model_policy'] = $step['model_policy_id'];
                unset($step['model_policy_id']);
            }
            if (($step['type'] ?? null) === 'resolve') {
                $step['dod'] = [
                    'automatic' => $step['dod_automatic'],
                    'value' => $step['dod'],
                ];
                unset($step['dod_automatic']);
            }
            $steps[] = $step;
        }
        $workflow['steps'] = $steps;
        $legacyState['workflow'] = $workflow;
        $legacy = json_encode($legacyState, JSON_THROW_ON_ERROR);

        return $this->legacyEncrypt($legacy);
    }

    private function legacyRequestPayload(): string
    {
        $current = JsonInput::map(
            json_decode(
                $this->application()->make(CompletionRequestCodec::class)->encode(
                    new CompletionRequest(
                        [new Message('user', 'Pay for this request exactly once.')],
                        ResponseContract::Text,
                        'legacy-operation',
                        'medium',
                    ),
                ),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            'request',
        );
        $legacy = json_encode(
            JsonInput::map($current['request'] ?? null, 'request.request'),
            JSON_THROW_ON_ERROR,
        );

        return $this->legacyEncrypt($legacy);
    }

    private function legacyEncrypt(string $payload): string
    {
        return 'enc:v1:'.$this->application()->make(Encrypter::class)->encrypt($payload, false);
    }

    private function createLegacyTables(Connection $database): void
    {
        $this->dropLegacyTables($database);
        $schema = $database->getSchemaBuilder();
        $schema->create('legacy_rick_runs', static function (Blueprint $table): void {
            $table->string('tenant_id')->nullable();
            $table->string('id')->primary();
            $table->string('status');
            $table->unsignedBigInteger('version');
            $table->longText('payload');
            $table->timestamps();
        });
        $schema->create('legacy_rick_step_executions', static function (Blueprint $table): void {
            $table->string('tenant_id')->nullable();
            $table->string('id')->primary();
            $table->string('run_id');
            $table->string('step_id');
            $table->string('status');
            $table->unsignedInteger('expected_invocations');
            $table->unsignedInteger('dispatched_invocations');
            $table->unsignedBigInteger('version');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
        $schema->create('legacy_rick_llm_invocations', static function (Blueprint $table): void {
            $table->string('tenant_id')->nullable();
            $table->string('id')->primary();
            $table->string('run_id');
            $table->string('step_execution_id');
            $table->string('step_id');
            $table->unsignedInteger('invocation_index');
            $table->string('status');
            $table->unsignedInteger('attempts');
            $table->unsignedBigInteger('version');
            $table->longText('request_payload');
            $table->longText('response_payload')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    private function dropLegacyTables(Connection $database): void
    {
        $schema = $database->getSchemaBuilder();
        $schema->dropIfExists('legacy_rick_llm_invocations');
        $schema->dropIfExists('legacy_rick_step_executions');
        $schema->dropIfExists('legacy_rick_runs');
    }
}
