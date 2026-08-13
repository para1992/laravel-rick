<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

use Illuminate\Database\Connection;
use UnexpectedValueException;

final readonly class SqliteQueueProfile
{
    public function __construct(private Connection $database) {}

    /** @return list<string> */
    public function warnings(): array
    {
        if ($this->database->getDriverName() !== 'sqlite') {
            return [];
        }

        $journalMode = strtolower((string) $this->pragma('journal_mode'));
        $busyTimeout = (int) $this->pragma('busy_timeout');
        $warnings = [];

        if ($journalMode !== 'wal') {
            $warnings[] = sprintf(
                "SQLite journal_mode is [%s]; concurrent Rick workers should set 'journal_mode' => 'WAL' on the Laravel database connection.",
                $journalMode,
            );
        }
        if ($busyTimeout < 5000) {
            $warnings[] = sprintf(
                "SQLite busy_timeout is [%d] ms; concurrent Rick workers should set 'busy_timeout' => 5000 or higher on the Laravel database connection.",
                $busyTimeout,
            );
        }
        $configuredTransactionMode = $this->database->getConfig('transaction_mode');
        $transactionMode = is_string($configuredTransactionMode)
            ? strtoupper($configuredTransactionMode)
            : 'DEFERRED';
        if (
            version_compare(PHP_VERSION, '8.4.0', '>=')
            && $transactionMode !== 'IMMEDIATE'
        ) {
            $warnings[] = "SQLite transaction_mode is not [IMMEDIATE]; concurrent Rick workers should set 'transaction_mode' => 'IMMEDIATE' on PHP 8.4 or newer.";
        }

        return $warnings;
    }

    private function pragma(string $name): int|string
    {
        $row = $this->database->selectOne("PRAGMA {$name}");
        if (! is_object($row)) {
            throw new UnexpectedValueException("SQLite PRAGMA [{$name}] returned no value.");
        }
        $values = array_values(get_object_vars($row));
        $value = $values[0] ?? null;
        if (! is_int($value) && ! is_string($value)) {
            throw new UnexpectedValueException("SQLite PRAGMA [{$name}] returned an invalid value.");
        }

        return $value;
    }
}
