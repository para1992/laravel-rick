<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Testing\PendingCommand;
use Laravel\Ai\AiServiceProvider;
use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;
use Rick\Laravel\RickServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function application(): Application
    {
        return $this->app ?? throw new LogicException('The Laravel test application is not booted.');
    }

    /** @param array<string, mixed> $parameters */
    protected function artisanCommand(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        if (! $pending instanceof PendingCommand) {
            throw new LogicException('The test console returned an immediate exit code.');
        }

        return $pending;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set(
            'app.key',
            'base64:'.base64_encode('rick-laravel-test-encryption-key'),
        );

        $driver = getenv('RICK_TEST_DB_DRIVER');
        if (! is_string($driver) || $driver === '' || $driver === 'sqlite') {
            return;
        }
        $connectionDriver = $driver === 'mariadb' ? 'mysql' : $driver;
        $app['config']->set('database.default', 'rick_testing');
        $connection = [
            'driver' => $connectionDriver,
            'host' => self::environment('RICK_TEST_DB_HOST', '127.0.0.1'),
            'port' => self::environment(
                'RICK_TEST_DB_PORT',
                $connectionDriver === 'pgsql' ? '5432' : '3306',
            ),
            'database' => self::environment('RICK_TEST_DB_DATABASE', 'rick'),
            'username' => self::environment('RICK_TEST_DB_USERNAME', 'rick'),
            'password' => self::environment('RICK_TEST_DB_PASSWORD', 'rick'),
            'prefix' => '',
        ];
        if ($connectionDriver === 'pgsql') {
            $connection['charset'] = 'utf8';
            $connection['search_path'] = 'public';
            $connection['sslmode'] = 'prefer';
        } else {
            $connection['charset'] = 'utf8mb4';
            $connection['collation'] = 'utf8mb4_unicode_ci';
        }
        $app['config']->set('database.connections.rick_testing', $connection);
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            RickServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
