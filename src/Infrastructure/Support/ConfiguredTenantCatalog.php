<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Support;

use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Infrastructure\Configuration\RickConfiguration;

final readonly class ConfiguredTenantCatalog implements TenantCatalogBase
{
    public function __construct(private RickConfiguration $configuration) {}

    public function tenantIds(): iterable
    {
        return $this->configuration->tenant->catalog
            ?? [$this->configuration->tenant->default];
    }
}
