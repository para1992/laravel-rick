<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Infrastructure\Outbox\OutboxRelay;

final class RetryOutboxCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'rick:outbox:retry {--tenant=*} {--all} {--limit=}';

    protected $description = 'Return quarantined Rick outbox rows to pending delivery';

    public function handle(
        OutboxRelay $relay,
        TenantCatalogBase $catalog,
        TenantContextBase $tenant,
    ): int {
        $limit = $this->limit();

        return $this->runForTenants(
            $catalog,
            $tenant,
            function (string $tenantId) use ($relay, $limit): void {
                $retried = $relay->retryFailed($limit);
                $this->info("Tenant [{$tenantId}]: returned {$retried} outbox row(s) to pending.");
            },
        );
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('Outbox retry limit must be a positive integer.');
        }

        return (int) $value;
    }
}
