<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Infrastructure\Configuration\RickConfiguration;
use Rick\Laravel\Infrastructure\Persistence\RunPruner;

final class PruneCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'rick:prune
        {--tenant=*}
        {--all}
        {--before= : Delete eligible runs updated before this ISO-8601 cutoff}
        {--batch= : Maximum runs per tenant}
        {--dry-run : Report eligible runs without deleting them}';

    protected $description = 'Prune terminal Rick runs whose outbox delivery is complete';

    public function handle(
        RunPruner $pruner,
        TenantCatalogBase $catalog,
        TenantContextBase $tenant,
        RickConfiguration $configuration,
        ClockBase $clock,
    ): int {
        $cutoff = $this->cutoff($configuration, $clock);
        $batch = $this->batch($configuration);
        $dryRun = $this->option('dry-run');
        if (! is_bool($dryRun)) {
            throw new InvalidArgumentException('The --dry-run option must be boolean.');
        }

        return $this->runForTenants(
            $catalog,
            $tenant,
            function (string $tenantId) use ($pruner, $cutoff, $batch, $dryRun): void {
                $result = $pruner->prune($cutoff, $batch, $dryRun);
                $verb = $dryRun ? 'matched' : 'deleted';
                $count = $dryRun ? $result->matched : $result->deleted;
                $this->info("Tenant [{$tenantId}]: {$verb} {$count} run(s).");
            },
        );
    }

    private function cutoff(RickConfiguration $configuration, ClockBase $clock): DateTimeImmutable
    {
        $value = $this->option('before');
        if ($value === null) {
            $days = $configuration->retention->cutoffDays;
            if ($days === null) {
                throw new InvalidArgumentException(
                    'A --before cutoff is required when retention.cutoff_days is not configured.',
                );
            }

            return $clock->now()->sub(new DateInterval("P{$days}D"));
        }
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Retention cutoff must be an ISO-8601 date-time.');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception $error) {
            throw new InvalidArgumentException(
                'Retention cutoff must be an ISO-8601 date-time.',
                previous: $error,
            );
        }
    }

    private function batch(RickConfiguration $configuration): int
    {
        $value = $this->option('batch');
        if ($value === null) {
            return $configuration->retention->batchSize;
        }
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('Retention batch must be a positive integer.');
        }

        return (int) $value;
    }
}
