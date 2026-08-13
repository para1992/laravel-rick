<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Closure;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Tests\TestCase;
use RuntimeException;

final class TenantOperationsTest extends TestCase
{
    public function test_operational_commands_default_to_current_tenant_and_accept_repeatable_tenants(): void
    {
        $this->artisanCommand('rick:outbox:relay')
            ->expectsOutputToContain('Tenant [default]')
            ->assertSuccessful();

        $this->artisanCommand('rick:outbox:relay', [
            '--tenant' => ['tenant-a', 'tenant-b', 'tenant-a'],
        ])
            ->expectsOutputToContain('Tenant [tenant-a]')
            ->expectsOutputToContain('Tenant [tenant-b]')
            ->assertSuccessful();
    }

    public function test_all_uses_catalog_and_is_mutually_exclusive_with_explicit_tenants(): void
    {
        $this->application()->instance(TenantCatalogBase::class, new class implements TenantCatalogBase
        {
            public function tenantIds(): iterable
            {
                return ['catalog-a', 'catalog-b', 'catalog-a'];
            }
        });

        $this->artisanCommand('rick:outbox:relay', ['--all' => true])
            ->expectsOutputToContain('Tenant [catalog-a]')
            ->expectsOutputToContain('Tenant [catalog-b]')
            ->assertSuccessful();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');
        $this->artisanCommand('rick:outbox:relay', [
            '--all' => true,
            '--tenant' => ['catalog-a'],
        ])->run();
    }

    public function test_one_tenant_failure_does_not_stop_later_tenants_and_returns_failure(): void
    {
        $context = new class implements TenantContextBase
        {
            private string $tenantId = 'default';

            public function id(): string
            {
                return $this->tenantId;
            }

            public function run(string $tenantId, Closure $operation): mixed
            {
                if ($tenantId === 'broken') {
                    throw new RuntimeException('tenant-secret-marker');
                }
                $previous = $this->tenantId;
                $this->tenantId = $tenantId;
                try {
                    return $operation();
                } finally {
                    $this->tenantId = $previous;
                }
            }
        };
        $catalog = new class implements TenantCatalogBase
        {
            public function tenantIds(): iterable
            {
                return ['broken', 'healthy'];
            }
        };
        $this->application()->forgetScopedInstances();
        $this->application()->instance(TenantContextBase::class, $context);
        $this->application()->instance(TenantCatalogBase::class, $catalog);

        $this->artisanCommand('rick:outbox:relay', ['--all' => true])
            ->expectsOutputToContain('Tenant [broken] failed')
            ->expectsOutputToContain('Tenant [healthy]')
            ->doesntExpectOutputToContain('tenant-secret-marker')
            ->assertFailed();
    }
}
