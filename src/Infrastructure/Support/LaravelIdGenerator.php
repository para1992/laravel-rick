<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Support;

use Illuminate\Support\Str;
use Rick\Laravel\Application\Interface\IdGeneratorBase;

final class LaravelIdGenerator implements IdGeneratorBase
{
    public function generate(): string
    {
        return (string) Str::uuid();
    }
}
