<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;

final readonly class LaravelPayloadProtector implements PayloadProtectorBase
{
    public function __construct(private StringEncrypter $encrypter) {}

    public function protect(string $payload): string
    {
        return $this->encrypter->encryptString($payload);
    }

    public function reveal(string $payload): string
    {
        return $this->encrypter->decryptString($payload);
    }
}
