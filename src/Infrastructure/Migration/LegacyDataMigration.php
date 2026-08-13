<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Migration;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Infrastructure\Persistence\DatabaseRow;
use UnexpectedValueException;

final readonly class LegacyDataMigration
{
    /** @param array{runs: string, step_executions: string, llm_invocations: string} $source
     * @param  array{runs: string, step_executions: string, llm_invocations: string, invocation_attempts: string}  $target
     */
    public function __construct(
        private ConnectionInterface $database,
        private Encrypter $encrypter,
        private PayloadProtectorBase $payloads,
        private LegacyPayloadConverter $converter,
        private array $source,
        private array $target,
    ) {
        if ($source['runs'] === $target['runs']) {
            throw new InvalidArgumentException('Legacy migration requires separate source and target tables.');
        }
    }

    /** @return array{runs: int, step_executions: int, llm_invocations: int, indeterminate: int} */
    public function migrate(string $tenantId, int $batch = 500): array
    {
        if ($batch < 1) {
            throw new InvalidArgumentException('Legacy migration batch size must be positive.');
        }

        /** @var array{runs: int, step_executions: int, llm_invocations: int, indeterminate: int} $counts */
        $counts = ['runs' => 0, 'step_executions' => 0, 'llm_invocations' => 0, 'indeterminate' => 0];
        $this->database->table($this->source['runs'])
            ->where(static fn (Builder $query): Builder => $query
                ->where('tenant_id', $tenantId)
                ->orWhereNull('tenant_id'))
            ->orderBy('id')
            ->chunk($batch, function ($rows) use ($tenantId, &$counts): void {
                foreach ($rows as $row) {
                    $data = DatabaseRow::from($row);
                    $plain = $this->reveal($data->string('payload'));
                    $this->database->table($this->target['runs'])->updateOrInsert(
                        ['tenant_id' => $tenantId, 'id' => $data->string('id')],
                        [
                            'status' => $data->string('status'),
                            'version' => $data->integer('version'),
                            'payload' => $this->payloads->protect($this->converter->run($plain)),
                            'created_at' => $data->value('created_at'),
                            'updated_at' => $data->value('updated_at'),
                        ],
                    );
                    $counts['runs']++;
                }
            });

        $this->copyExecutions($tenantId, $batch, $counts);
        $this->copyInvocations($tenantId, $batch, $counts);

        return $counts;
    }

    /** @param array{runs: int, step_executions: int, llm_invocations: int, indeterminate: int} $counts */
    private function copyExecutions(string $tenantId, int $batch, array &$counts): void
    {
        $this->database->table($this->source['step_executions'])
            ->where(static fn (Builder $query): Builder => $query
                ->where('tenant_id', $tenantId)
                ->orWhereNull('tenant_id'))
            ->orderBy('id')
            ->chunk($batch, function ($rows) use ($tenantId, &$counts): void {
                foreach ($rows as $row) {
                    $data = DatabaseRow::from($row);
                    $errorCode = $data->nullableStringOr('error_code');
                    $this->database->table($this->target['step_executions'])->updateOrInsert(
                        ['tenant_id' => $tenantId, 'id' => $data->string('id')],
                        [
                            'run_id' => $data->string('run_id'),
                            'step_id' => $data->string('step_id'),
                            'sequence' => $data->integerOr('sequence', 1),
                            'status' => $data->string('status'),
                            'expected_invocations' => $data->integer('expected_invocations'),
                            'dispatched_invocations' => $data->integerOr(
                                'dispatched_invocations',
                                $data->integer('expected_invocations'),
                            ),
                            'version' => $data->integer('version'),
                            'error_code' => $errorCode,
                            'error_message' => $errorCode === null
                                ? null
                                : 'Legacy step failed; inspect pre-migration logs for details.',
                            'created_at' => $data->value('created_at'),
                            'updated_at' => $data->value('updated_at'),
                        ],
                    );
                    $counts['step_executions']++;
                }
            });
    }

    /** @param array{runs: int, step_executions: int, llm_invocations: int, indeterminate: int} $counts */
    private function copyInvocations(string $tenantId, int $batch, array &$counts): void
    {
        $this->database->table($this->source['llm_invocations'])
            ->where(static fn (Builder $query): Builder => $query
                ->where('tenant_id', $tenantId)
                ->orWhereNull('tenant_id'))
            ->orderBy('id')
            ->chunk($batch, function ($rows) use ($tenantId, &$counts): void {
                foreach ($rows as $row) {
                    $data = DatabaseRow::from($row);
                    $request = $this->converter->request($this->reveal($data->string('request_payload')));
                    $running = $data->string('status') === 'running';
                    $status = $running ? 'indeterminate' : $data->string('status');
                    $responsePayload = $data->nullableString('response_payload');
                    $errorCode = $data->nullableStringOr('error_code');
                    $this->database->table($this->target['llm_invocations'])->updateOrInsert(
                        ['tenant_id' => $tenantId, 'id' => $data->string('id')],
                        [
                            'run_id' => $data->string('run_id'),
                            'step_execution_id' => $data->string('step_execution_id'),
                            'step_id' => $data->string('step_id'),
                            'invocation_index' => $data->integer('invocation_index'),
                            'status' => $status,
                            'attempts' => $data->integer('attempts'),
                            'version' => $data->integer('version') + ($running ? 1 : 0),
                            'request_payload' => $this->payloads->protect($request),
                            'response_payload' => $responsePayload === null
                                ? null
                                : $this->payloads->protect($this->converter->response(
                                    $this->reveal($responsePayload),
                                )),
                            'error_code' => $running ? 'legacy_outcome_indeterminate' : $errorCode,
                            'error_message' => $running
                                ? 'Legacy invocation was in flight during migration; reconcile it manually.'
                                : ($errorCode === null
                                    ? null
                                    : 'Legacy invocation failed; inspect pre-migration logs for details.'),
                            'lease_expires_at' => null,
                            'created_at' => $data->value('created_at'),
                            'updated_at' => $data->value('updated_at'),
                        ],
                    );
                    if ($running) {
                        $this->indeterminateAttempt($tenantId, $data, $request);
                        $counts['indeterminate']++;
                    }
                    $counts['llm_invocations']++;
                }
            });
    }

    private function indeterminateAttempt(string $tenantId, DatabaseRow $data, string $request): void
    {
        $invocationId = $data->string('id');
        $id = 'legacy-'.substr(hash('sha256', $tenantId.':'.$invocationId), 0, 32);
        $this->database->table($this->target['invocation_attempts'])->updateOrInsert(
            ['tenant_id' => $tenantId, 'id' => $id],
            [
                'invocation_id' => $invocationId,
                'run_id' => $data->string('run_id'),
                'attempt_number' => max(1, $data->integer('attempts')),
                'status' => 'indeterminate',
                'request_fingerprint' => hash('sha256', $request),
                'provider_request_id' => null,
                'error_code' => 'legacy_outcome_indeterminate',
                'error_message' => 'Migrated while the legacy provider outcome was ambiguous.',
                'started_at' => $data->value('created_at'),
                'finished_at' => $data->value('updated_at'),
                'created_at' => $data->value('created_at'),
                'updated_at' => $data->value('updated_at'),
            ],
        );
    }

    private function reveal(string $payload): string
    {
        if (! str_starts_with($payload, 'enc:v1:')) {
            return $payload;
        }
        $value = $this->encrypter->decrypt(substr($payload, 7), false);

        return is_string($value)
            ? $value
            : throw new UnexpectedValueException('Legacy encrypted payload is not a string.');
    }
}
