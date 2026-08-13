<?php

declare(strict_types=1);

namespace Rick\Stand\Tests\Feature;

use Rick\Stand\Package\PackageLocator;
use Rick\Stand\Support\StrictJson;
use Rick\Stand\Tests\TestCase;

final class PlatformContractTest extends TestCase
{
    public function test_full_infrastructure_and_compatibility_matrix_is_declared_in_ci(): void
    {
        $platform = StrictJson::file(dirname(__DIR__, 2).'/inventory/platform.json');
        self::assertSame(['sqlite', 'mysql-8.4', 'mariadb-11.4', 'postgresql-17'], $platform['database']);
        $required = StrictJson::file(dirname(__DIR__, 2).'/inventory/ci-required.json')['markers'];
        $workflow = PackageLocator::root().'/.github/workflows/ci.yml';
        if (is_file($workflow)) {
            $github = (string) file_get_contents($workflow);
            foreach ($required as $needle) {
                self::assertStringContainsString($needle, $github);
            }
        } else {
            self::assertCount(7, $required, 'Archive consumers use the stand-owned CI contract snapshot.');
        }
    }

    public function test_source_and_archive_inventory_normalization_is_target_independent(): void
    {
        $composer = StrictJson::file(PackageLocator::root().'/composer.json');
        self::assertContains('/tools', $composer['archive']['exclude']);
        self::assertSame('rickphp/laravel-rick', $composer['name']);
    }
}
