<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Infrastructure\Migration\LegacyDataMigration;

final class MigrateLegacyCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'rick:migrate-legacy {--tenant=*} {--all} {--batch=500}';

    protected $description = 'Idempotently copy encrypted legacy Rick state into Laravel Rick tables';

    public function handle(
        LegacyDataMigration $migration,
        TenantCatalogBase $catalog,
        TenantContextBase $tenant,
    ): int {
        $batch = $this->option('batch');
        if (! is_string($batch) || ! ctype_digit($batch) || (int) $batch < 1) {
            throw new InvalidArgumentException('Migration batch option must be a positive integer.');
        }

        return $this->runForTenants(
            $catalog,
            $tenant,
            function (string $tenantId) use ($migration, $batch): void {
                $result = $migration->migrate($tenantId, (int) $batch);
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            },
        );
    }
}
