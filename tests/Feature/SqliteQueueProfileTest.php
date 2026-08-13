<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SQLiteConnection;
use PDO;
use Rick\Laravel\Infrastructure\Persistence\SqliteQueueProfile;
use Rick\Laravel\Tests\TestCase;
use RuntimeException;

final class SqliteQueueProfileTest extends TestCase
{
    public function test_diagnostics_report_actionable_concurrent_worker_warnings(): void
    {
        $database = $this->application()->make(ConnectionInterface::class);
        if ($database->getDriverName() !== 'sqlite') {
            self::markTestSkipped('SQLite-specific diagnostics.');
        }
        $database->statement('PRAGMA busy_timeout = 0');

        $this->artisanCommand('rick:diagnose', ['--strict' => true])
            ->expectsOutputToContain('journal_mode')
            ->expectsOutputToContain('busy_timeout')
            ->assertFailed();
        $this->artisanCommand('rick:diagnose')
            ->expectsOutputToContain('journal_mode')
            ->expectsOutputToContain('busy_timeout')
            ->assertSuccessful();
    }

    public function test_documented_file_backed_profile_passes_diagnostics(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rick-sqlite-profile-');
        if (! is_string($path)) {
            throw new RuntimeException('Unable to create a temporary SQLite database.');
        }
        $database = new SQLiteConnection(
            new PDO('sqlite:'.$path),
            $path,
            config: ['transaction_mode' => 'IMMEDIATE'],
        );

        try {
            $database->statement('PRAGMA journal_mode = WAL');
            $database->statement('PRAGMA busy_timeout = 5000');

            self::assertSame([], (new SqliteQueueProfile($database))->warnings());
        } finally {
            $database->disconnect();
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
