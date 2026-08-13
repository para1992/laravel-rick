<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Closure;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Throwable;

trait InteractsWithTenants
{
    /** @param Closure(string): void $operation */
    final protected function runForTenants(
        TenantCatalogBase $catalog,
        TenantContextBase $context,
        Closure $operation,
    ): int {
        $failed = false;
        foreach ($this->selectedTenants($catalog, $context) as $tenantId) {
            try {
                $context->run($tenantId, static fn () => $operation($tenantId));
            } catch (Throwable $error) {
                $failed = true;
                $this->error("Tenant [{$tenantId}] failed; inspect runtime logs.");
                self::reportTenantFailure($error);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function selectedTenants(TenantCatalogBase $catalog, TenantContextBase $context): array
    {
        $all = $this->option('all');
        if (! is_bool($all)) {
            throw new InvalidArgumentException('The --all option must be boolean.');
        }
        $selected = $this->option('tenant');
        if (! is_array($selected)) {
            throw new InvalidArgumentException('The --tenant option must be repeatable.');
        }
        if ($all && $selected !== []) {
            throw new InvalidArgumentException('Options --all and --tenant are mutually exclusive.');
        }

        $values = $all ? $catalog->tenantIds() : ($selected === [] ? [$context->id()] : $selected);
        $tenants = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Tenant selections must be non-empty strings.');
            }
            $tenants[] = trim($value);
        }

        return array_values(array_unique($tenants));
    }

    private static function reportTenantFailure(Throwable $error): void
    {
        try {
            report($error);
        } catch (Throwable) {
        }
    }
}
