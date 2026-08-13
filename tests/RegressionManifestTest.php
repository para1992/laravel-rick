<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;

final class RegressionManifestTest extends TestCase
{
    public function test_every_implemented_capability_has_an_executable_regression_scenario(): void
    {
        $root = dirname(__DIR__);
        $capabilityManifest = $this->decode($root.'/capabilities.json');
        $regressionManifest = $this->decode($root.'/regression.json');

        self::assertSame(1, $regressionManifest['schema_version']);
        self::assertSame('Regression', $regressionManifest['suite']);
        $capabilities = JsonInput::map(
            $capabilityManifest['capabilities'] ?? null,
            'capabilities.capabilities',
        );
        $regressionCapabilities = JsonInput::map(
            $regressionManifest['capabilities'] ?? null,
            'regression.capabilities',
        );
        self::assertSame(
            array_keys($capabilities),
            array_keys($regressionCapabilities),
            'Regression capability order must follow capabilities.json.',
        );

        $configuredFiles = $this->configuredFiles($regressionManifest);
        $configuration = (string) file_get_contents($root.'/phpunit.regression.xml');

        foreach ($configuredFiles as $file) {
            self::assertFileExists($root.'/'.$file);
            self::assertStringContainsString(
                '<file>'.$file.'</file>',
                $configuration,
                "Regression file [{$file}] is not enabled in phpunit.regression.xml.",
            );
        }

        foreach ($capabilities as $capability => $statusValue) {
            if (JsonInput::string($statusValue, "capabilities.capabilities.{$capability}") !== 'implemented') {
                continue;
            }

            $scenarios = JsonInput::strings(
                $regressionCapabilities[$capability] ?? [],
                "regression.capabilities.{$capability}",
            );
            self::assertNotEmpty(
                $scenarios,
                "Implemented capability [{$capability}] has no regression scenario.",
            );

            foreach ($scenarios as $scenario) {
                [$class, $method] = $this->scenario($scenario, $capability);
                if (! class_exists($class)) {
                    self::fail("Regression class [{$class}] does not exist.");
                }
                self::assertTrue(method_exists($class, $method), "Regression scenario [{$scenario}] does not exist.");

                $testFile = (new ReflectionClass($class))->getFileName();
                self::assertNotFalse($testFile);
                self::assertContains(
                    realpath($testFile),
                    array_map(
                        static fn (string $file): string|false => realpath($root.'/'.$file),
                        $configuredFiles,
                    ),
                    "Regression scenario [{$scenario}] is outside the configured regression lanes.",
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path): array
    {
        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return JsonInput::map($decoded, $path);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function configuredFiles(array $manifest): array
    {
        $files = [];
        $lanes = JsonInput::map($manifest['lanes'] ?? null, 'regression.lanes');
        foreach ($lanes as $name => $lane) {
            foreach (JsonInput::strings($lane, "regression.lanes.{$name}") as $file) {
                $files[] = $file;
            }
        }

        self::assertSame($files, array_values(array_unique($files)), 'Regression lanes must not duplicate files.');

        return $files;
    }

    /**
     * @return array{string, string}
     */
    private function scenario(mixed $scenario, string $capability): array
    {
        self::assertIsString($scenario, "Regression scenario for [{$capability}] must be a string.");
        $parts = explode('::', $scenario, 2);
        self::assertCount(2, $parts, "Regression scenario [{$scenario}] must use Class::method.");
        self::assertStringStartsWith('test_', $parts[1]);

        return [$parts[0], $parts[1]];
    }
}
