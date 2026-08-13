<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

use Closure;
use Illuminate\Database\Connection;
use Rick\Laravel\Application\Interface\TransactionBase;
use Throwable;

final readonly class LaravelTransaction implements TransactionBase
{
    public function __construct(private Connection $database) {}

    public function run(Closure $operation): mixed
    {
        return $this->database->transaction($operation);
    }

    public function afterCommit(Closure $operation): void
    {
        $isolated = static function () use ($operation): void {
            try {
                $operation();
            } catch (Throwable $error) {
                try {
                    report($error);
                } catch (Throwable) {
                    // Reporting must not prevent later after-commit callbacks.
                }
            }
        };

        if ($this->database->transactionLevel() === 0) {
            $isolated();

            return;
        }

        $this->database->afterCommit($isolated);
    }
}
