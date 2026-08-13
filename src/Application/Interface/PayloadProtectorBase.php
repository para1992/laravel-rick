<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

interface PayloadProtectorBase
{
    public function protect(string $payload): string;

    public function reveal(string $payload): string;
}
