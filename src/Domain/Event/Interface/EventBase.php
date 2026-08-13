<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event\Interface;

interface EventBase
{
    public function eventId(): string;
}
