<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

use DateTimeImmutable;

interface ClockBase
{
    public function now(): DateTimeImmutable;
}
