<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Outbox;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Infrastructure\Outbox\Exception\OutboxPayloadException;
use Rick\Laravel\Infrastructure\Persistence\Json\DomainEventCodec;
use Rick\Laravel\Infrastructure\Queue\Job\ContinueRunJob;
use Rick\Laravel\Infrastructure\Queue\Job\ExecuteInvocationJob;
use Throwable;

final class OutboxRelay
{
    private bool $running = false;

    private bool $wakeRequested = false;

    /**
     * @param  list<int>  $continueBackoff
     * @param  list<int>  $invocationBackoff
     */
    public function __construct(
        private readonly Connection $database,
        private readonly BusDispatcher $bus,
        private readonly EventDispatcher $events,
        private readonly DomainEventCodec $eventCodec,
        private readonly PayloadProtectorBase $payloads,
        private readonly TenantContextBase $tenant,
        private readonly ClockBase $clock,
        private readonly IdGeneratorBase $ids,
        private readonly string $table = 'rick_outbox',
        private readonly int $batchSize = 100,
        private readonly int $leaseSeconds = 60,
        private readonly int $maxAttempts = 10,
        private readonly int $retryBaseSeconds = 1,
        private readonly int $retryMaxSeconds = 300,
        private readonly ?string $queueConnection = null,
        private readonly string $controlQueue = 'default',
        private readonly string $llmQueue = 'default',
        private readonly int $continueTries = 5,
        private readonly int $continueTimeout = 60,
        private readonly array $continueBackoff = [1, 5, 15, 30],
        private readonly int $invocationTries = 5,
        private readonly int $invocationTimeout = 180,
        private readonly array $invocationBackoff = [5, 30, 120, 300],
    ) {
        foreach ([
            'batch size' => $batchSize,
            'lease seconds' => $leaseSeconds,
            'maximum attempts' => $maxAttempts,
            'retry base seconds' => $retryBaseSeconds,
            'retry maximum seconds' => $retryMaxSeconds,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("Outbox {$name} must be positive.");
            }
        }
        if ($retryMaxSeconds < $retryBaseSeconds) {
            throw new InvalidArgumentException('Outbox retry maximum must not be below its base delay.');
        }
    }

    public function wake(): void
    {
        if ($this->running) {
            $this->wakeRequested = true;

            return;
        }

        $this->running = true;
        try {
            do {
                $this->wakeRequested = false;
                $this->relay();
            } while ($this->wakeRequested);
        } catch (Throwable $error) {
            self::report($error);
        } finally {
            $this->running = false;
            $this->wakeRequested = false;
        }
    }

    /** @phpstan-impure */
    public function relay(?int $limit = null): OutboxRelayResult
    {
        $limit ??= $this->batchSize;
        if ($limit < 1) {
            throw new InvalidArgumentException('Outbox relay limit must be positive.');
        }

        $claimed = 0;
        $delivered = 0;
        $deferred = 0;
        $failed = 0;

        foreach ($this->candidateIds($limit) as $id) {
            try {
                $record = $this->claim($id);
            } catch (OutboxPayloadException $error) {
                self::report($error);
                $this->quarantineCandidate($id);
                $failed++;

                continue;
            }
            if ($record === null) {
                continue;
            }

            $claimed++;
            try {
                $this->deliver($record);
                $this->markDelivered($record);
                $delivered++;
            } catch (OutboxPayloadException $error) {
                self::report($error);
                $this->markFailed($record, true);
                $failed++;
            } catch (Throwable $error) {
                self::report($error);
                if ($record->attempts >= $this->maxAttempts) {
                    $this->markFailed($record, false);
                    $failed++;
                } else {
                    $this->defer($record);
                    $deferred++;
                }
            }
        }

        return new OutboxRelayResult($claimed, $delivered, $deferred, $failed);
    }

    public function retryFailed(?int $limit = null): int
    {
        $limit ??= $this->batchSize;
        if ($limit < 1) {
            throw new InvalidArgumentException('Outbox retry limit must be positive.');
        }

        $ids = $this->database->table($this->table)
            ->where('tenant_id', $this->tenant->id())
            ->where('status', 'failed')
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();

        if ($ids === []) {
            return 0;
        }

        $now = $this->clock->now();

        return $this->database->transaction(fn (): int => $this->database->table($this->table)
            ->where('tenant_id', $this->tenant->id())
            ->where('status', 'failed')
            ->whereIn('id', $ids)
            ->update([
                'status' => 'pending',
                'available_at' => $now,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'version' => $this->database->raw('version + 1'),
                'updated_at' => $now,
            ]));
    }

    /** @return list<string> */
    private function candidateIds(int $limit): array
    {
        $now = $this->clock->now();

        return array_values($this->database->table($this->table)
            ->where('tenant_id', $this->tenant->id())
            ->where(function (Builder $eligible) use ($now): void {
                $eligible
                    ->where(function (Builder $pending) use ($now): void {
                        $pending
                            ->where('status', 'pending')
                            ->where('available_at', '<=', $now);
                    })
                    ->orWhere(function (Builder $expired) use ($now): void {
                        $expired
                            ->where('status', 'delivering')
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', $now);
                    });
            })
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all());
    }

    private function claim(string $id): ?OutboxRecord
    {
        return $this->database->transaction(function () use ($id): ?OutboxRecord {
            $now = $this->clock->now();
            $row = $this->database->table($this->table)
                ->where('tenant_id', $this->tenant->id())
                ->where('id', $id)
                ->first();

            if ($row === null) {
                return null;
            }

            $data = self::rowMap(get_object_vars($row));
            $version = self::rowInteger($data, 'version');
            $attempts = self::rowInteger($data, 'attempts');
            $token = $this->ids->generate();
            $expiresAt = $now->add(new DateInterval('PT'.$this->leaseSeconds.'S'));

            $updated = $this->database->table($this->table)
                ->where('tenant_id', $this->tenant->id())
                ->where('id', $id)
                ->where('version', $version)
                ->where(function (Builder $eligible) use ($now): void {
                    $eligible
                        ->where(function (Builder $pending) use ($now): void {
                            $pending
                                ->where('status', 'pending')
                                ->where('available_at', '<=', $now);
                        })
                        ->orWhere(function (Builder $expired) use ($now): void {
                            $expired
                                ->where('status', 'delivering')
                                ->whereNotNull('lease_expires_at')
                                ->where('lease_expires_at', '<=', $now);
                        });
                })
                ->update([
                    'status' => 'delivering',
                    'attempts' => $attempts + 1,
                    'lease_token' => $token,
                    'lease_expires_at' => $expiresAt,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'version' => $version + 1,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return null;
            }

            $claimed = $this->database->table($this->table)
                ->where('tenant_id', $this->tenant->id())
                ->where('id', $id)
                ->where('lease_token', $token)
                ->first();

            if ($claimed === null) {
                return null;
            }

            try {
                return OutboxRecord::fromRow($claimed);
            } catch (Throwable $error) {
                throw new OutboxPayloadException(
                    'Outbox row is structurally invalid.',
                    previous: $error,
                );
            }
        });
    }

    private function quarantineCandidate(string $id): void
    {
        $now = $this->clock->now();
        $this->database->transaction(function () use ($id, $now): void {
            $this->database->table($this->table)
                ->where('tenant_id', $this->tenant->id())
                ->where('id', $id)
                ->whereIn('status', ['pending', 'delivering'])
                ->update([
                    'status' => 'failed',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => 'outbox_payload_invalid',
                    'last_error_message' => 'Outbox row is invalid and requires operator review.',
                    'version' => $this->database->raw('version + 1'),
                    'updated_at' => $now,
                ]);
        });
    }

    private function deliver(OutboxRecord $record): void
    {
        if ($record->kind === 'continue_run') {
            $job = new ContinueRunJob($this->tenant->id(), $record->runId);
            $job->configure($this->continueTries, $this->continueTimeout, $this->continueBackoff);
            $job->onQueue($this->controlQueue);
            $this->dispatch($job);

            return;
        }

        if ($record->kind === 'execute_invocation') {
            if ($record->invocationId === null || $record->invocationId === '') {
                throw new OutboxPayloadException('Invocation outbox row has no invocation ID.');
            }
            $job = new ExecuteInvocationJob($this->tenant->id(), $record->invocationId, $record->runId);
            $job->configure($this->invocationTries, $this->invocationTimeout, $this->invocationBackoff);
            $job->onQueue($this->llmQueue);
            $this->dispatch($job);

            return;
        }

        if ($record->kind !== 'domain_event') {
            throw new OutboxPayloadException("Unsupported outbox kind [{$record->kind}].");
        }
        if ($record->eventType === null || $record->payload === null) {
            throw new OutboxPayloadException('Domain-event outbox row has an incomplete payload.');
        }

        try {
            $event = $this->eventCodec->decode(
                $record->eventType,
                $this->payloads->reveal($record->payload),
            );
        } catch (Throwable $error) {
            throw new OutboxPayloadException('Domain-event outbox payload is structurally invalid.', previous: $error);
        }

        $this->events->dispatch($event);
    }

    private function dispatch(ContinueRunJob|ExecuteInvocationJob $job): void
    {
        if ($this->queueConnection !== null) {
            $job->onConnection($this->queueConnection);
        }

        $this->bus->dispatch($job);
    }

    private function markDelivered(OutboxRecord $record): void
    {
        $now = $this->clock->now();
        $this->database->transaction(function () use ($record, $now): void {
            $this->database->table($this->table)
                ->where('tenant_id', $this->tenant->id())
                ->where('id', $record->id)
                ->where('status', 'delivering')
                ->where('lease_token', $record->leaseToken)
                ->where('version', $record->version)
                ->update([
                    'status' => 'delivered',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'delivered_at' => $now,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'version' => $record->version + 1,
                    'updated_at' => $now,
                ]);
        });
    }

    private function defer(OutboxRecord $record): void
    {
        $now = $this->clock->now();
        $delay = min(
            $this->retryMaxSeconds,
            $this->retryBaseSeconds * (2 ** min(30, max(0, $record->attempts - 1))),
        );
        $availableAt = $now->add(new DateInterval('PT'.$delay.'S'));
        $this->finishFailure(
            $record,
            'pending',
            'outbox_delivery_failed',
            'Outbox delivery failed and will be retried; inspect runtime logs.',
            $availableAt,
        );
    }

    private function markFailed(OutboxRecord $record, bool $poison): void
    {
        $this->finishFailure(
            $record,
            'failed',
            $poison ? 'outbox_payload_invalid' : 'outbox_delivery_exhausted',
            $poison
                ? 'Outbox payload is invalid and requires operator review.'
                : 'Outbox delivery attempts were exhausted; inspect runtime logs.',
            $this->clock->now(),
        );
    }

    private function finishFailure(
        OutboxRecord $record,
        string $status,
        string $code,
        string $message,
        DateTimeImmutable $availableAt,
    ): void {
        $now = $this->clock->now();
        $this->database->transaction(function () use (
            $record,
            $status,
            $code,
            $message,
            $availableAt,
            $now,
        ): void {
            $this->database->table($this->table)
                ->where('tenant_id', $this->tenant->id())
                ->where('id', $record->id)
                ->where('status', 'delivering')
                ->where('lease_token', $record->leaseToken)
                ->where('version', $record->version)
                ->update([
                    'status' => $status,
                    'available_at' => $availableAt,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => $code,
                    'last_error_message' => $message,
                    'version' => $record->version + 1,
                    'updated_at' => $now,
                ]);
        });
    }

    /** @param array<string, mixed> $row */
    private static function rowInteger(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new OutboxPayloadException("Outbox field [{$field}] is invalid.");
        }

        return (int) $value;
    }

    /** @param array<mixed> $row
     * @return array<string, mixed>
     */
    private static function rowMap(array $row): array
    {
        $map = [];
        foreach ($row as $key => $value) {
            if (! is_string($key)) {
                throw new OutboxPayloadException('Outbox row contains an invalid column name.');
            }
            $map[$key] = $value;
        }

        return $map;
    }

    private static function report(Throwable $error): void
    {
        try {
            report($error);
        } catch (Throwable) {
            // Runtime reporting is best-effort and must not stall the relay.
        }
    }
}
