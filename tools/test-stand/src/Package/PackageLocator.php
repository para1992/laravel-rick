<?php

declare(strict_types=1);

namespace Rick\Stand\Package;

use Composer\InstalledVersions;
use RuntimeException;

final class PackageLocator
{
    public static function root(): string
    {
        $override = getenv('RICK_STAND_PACKAGE_ROOT');
        if (is_string($override) && is_dir($override)) {
            return (string) realpath($override);
        }

        $path = InstalledVersions::getInstallPath('rickphp/laravel-rick');
        if (! is_string($path) || ! is_dir($path)) {
            throw new RuntimeException('Installed package [rickphp/laravel-rick] was not found.');
        }

        return (string) realpath($path);
    }
}
