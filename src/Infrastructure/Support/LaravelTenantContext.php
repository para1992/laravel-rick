<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Support;

use Closure;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\ValueObject\Identifier;

final class LaravelTenantContext implements TenantContextBase
{
    private string $tenantId;

    public function __construct(string $defaultTenantId = 'default')
    {
        $this->tenantId = Identifier::normalize($defaultTenantId, 'Tenant ID');
    }

    public function id(): string
    {
        return $this->tenantId;
    }

    public function run(string $tenantId, Closure $operation): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = Identifier::normalize($tenantId, 'Tenant ID');

        try {
            return $operation();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
