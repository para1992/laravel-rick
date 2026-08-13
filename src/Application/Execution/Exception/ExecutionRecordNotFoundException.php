<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Exception;

use RuntimeException;

final class ExecutionRecordNotFoundException extends RuntimeException
{
    public static function for(string $id): self
    {
        return new self("Execution record [{$id}] was not found.");
    }
}
