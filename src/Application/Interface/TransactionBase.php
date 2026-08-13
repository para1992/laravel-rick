<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

use Closure;

interface TransactionBase
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(Closure $operation): mixed;

    /** @param Closure(): void $operation */
    public function afterCommit(Closure $operation): void;
}
