<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Llm\ValueObject;

use InvalidArgumentException;
use Rick\Laravel\Domain\Llm\Interface\PayloadBase;

final readonly class Message implements PayloadBase
{
    public function __construct(
        public string $role,
        public string $content,
    ) {
        if (! in_array($role, ['system', 'user', 'assistant'], true)) {
            throw new InvalidArgumentException("Unsupported message role [{$role}].");
        }
    }
}
