<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Support;

use DateTimeImmutable;
use Rick\Laravel\Application\Interface\ClockBase;

final class LaravelClock implements ClockBase
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
