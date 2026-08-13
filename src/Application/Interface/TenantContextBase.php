<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

use Closure;

interface TenantContextBase
{
    public function id(): string;

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(string $tenantId, Closure $operation): mixed;
}
