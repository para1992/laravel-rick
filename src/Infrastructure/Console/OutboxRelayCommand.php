<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Infrastructure\Outbox\OutboxRelay;

final class OutboxRelayCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'rick:outbox:relay {--tenant=*} {--all} {--limit=}';

    protected $description = 'Relay pending Rick queue intents and domain events';

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
                $result = $relay->relay($limit);
                $this->info(sprintf(
                    'Tenant [%s]: claimed %d, delivered %d, deferred %d, failed %d.',
                    $tenantId,
                    $result->claimed,
                    $result->delivered,
                    $result->deferred,
                    $result->failed,
                ));
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
            throw new InvalidArgumentException('Outbox relay limit must be a positive integer.');
        }

        return (int) $value;
    }
}
