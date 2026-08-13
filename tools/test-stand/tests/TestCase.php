<?php

declare(strict_types=1);

namespace Rick\Stand\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\RickServiceProvider;
use Rick\Stand\Fixture\CassetteCatalog;
use Rick\Stand\Fixture\CassetteFakeGatewayFactory;
use Rick\Stand\Package\PackageLocator;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, RickServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode('rick-laravel-test-encryption-key'));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(PackageLocator::root().'/database/migrations');
    }

    protected function setUp(): void
    {
        foreach (['OPENAI_API_KEY', 'OPENROUTER_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY', 'ANTHROPIC_API_KEY'] as $credential) {
            putenv($credential);
            unset($_ENV[$credential], $_SERVER[$credential]);
        }
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @param list<string> $fixtures */
    protected function useCassettes(array $fixtures): void
    {
        $catalog = new CassetteCatalog(dirname(__DIR__).'/fixtures');
        $gateway = (new CassetteFakeGatewayFactory)->make($catalog, $fixtures);
        $this->app->instance(GatewayBase::class, $gateway);
    }

    protected function application(): Application
    {
        return $this->app;
    }
}
