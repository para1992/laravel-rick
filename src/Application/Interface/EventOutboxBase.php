<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

use Rick\Laravel\Domain\Event\Interface\EventBase;

interface EventOutboxBase
{
    public function record(EventBase $event): void;
}
