<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Infrastructure\Recovery\InvocationRecoveryRunner;

final class RecoverInvocationsCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'rick:recover {--tenant=*} {--all}';

    protected $description = 'Mark expired LLM invocation leases as requiring manual recovery';

    public function handle(
        InvocationRecoveryRunner $recovery,
        TenantCatalogBase $catalog,
        TenantContextBase $tenant,
    ): int {
        return $this->runForTenants(
            $catalog,
            $tenant,
            function (string $tenantId) use ($recovery): void {
                $count = $recovery->markExpired();
                $this->info("Tenant [{$tenantId}]: marked {$count} invocation(s) as indeterminate.");
            },
        );
    }
}
