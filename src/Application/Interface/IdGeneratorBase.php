<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

interface IdGeneratorBase
{
    public function generate(): string;
}
