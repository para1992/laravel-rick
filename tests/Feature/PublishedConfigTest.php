<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Rick\Laravel\RickServiceProvider;
use Rick\Laravel\Tests\TestCase;

final class PublishedConfigTest extends TestCase
{
    public function test_published_config_matches_the_version_two_snapshot(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/config/rick.php';
        $published = ServiceProvider::pathsToPublish(RickServiceProvider::class, 'rick-config');
        self::assertCount(1, $published);
        $publishedSource = array_key_first($published);
        self::assertIsString($publishedSource);
        self::assertSame(realpath($source), realpath($publishedSource));
        self::assertSame(config_path('rick.php'), $published[$publishedSource]);

        $config = require $source;
        self::assertIsArray($config);
        $llm = $config['llm'] ?? null;
        self::assertIsArray($llm);
        $snapshot = [
            'schema_version' => 2,
            'top_level_keys' => array_keys($config),
            'tables' => $config['tables'] ?? null,
            'queue' => $config['queue'] ?? null,
            'structured_responses' => $llm['structured_responses'] ?? null,
        ];
        $json = json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        self::assertJsonStringEqualsJsonFile(
            dirname(__DIR__).'/Fixtures/published-config-v2.json',
            $json,
        );
        self::assertIsString(config('rick.queue.control'));
        self::assertIsString(config('rick.queue.llm'));
        self::assertSame(1, config('rick.llm.structured_responses.attempts'));
        self::assertSame(
            'same_route_then_fallback',
            config('rick.llm.structured_responses.strategy'),
        );
    }
}
