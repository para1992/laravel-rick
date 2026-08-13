<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

interface TenantCatalogBase
{
    /** @return iterable<string> */
    public function tenantIds(): iterable;
}
