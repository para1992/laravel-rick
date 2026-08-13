<?php

declare(strict_types=1);

namespace Rick\Stand\Console;

use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Rick\Stand\Package\PackageLocator;
use RuntimeException;
use SplFileInfo;

final class ArchiveRunner
{
    public function __construct(
        private readonly string $standRoot,
        private readonly ProcessRunner $process,
    ) {}

    public function run(string $lane, ?string $scenario): int
    {
        $packageRoot = PackageLocator::root();
        $archive = $packageRoot.'/build/archive/laravel-rick.tar';
        $exit = $this->process->run(['composer', 'qa:archive'], $packageRoot);
        if ($exit !== 0 || ! is_file($archive)) {
            return $exit === 0 ? 1 : $exit;
        }

        $temporary = sys_get_temp_dir().'/laravel-rick-stand-archive-'.bin2hex(random_bytes(6));
        $package = $temporary.'/package';
        $stand = $temporary.'/stand';
        mkdir($package, 0777, true);
        mkdir($stand, 0777, true);

        try {
            (new PharData($archive))->extractTo($package, null, true);
            $this->copyStand($this->standRoot, $stand);
            $composer = json_decode((string) file_get_contents($stand.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            $composer['repositories'][0]['url'] = $package;
            $composer['repositories'][0]['options']['symlink'] = false;
            file_put_contents($stand.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            $dependencyRoot = is_dir($this->standRoot.'/vendor') ? $this->standRoot : $packageRoot;
            if ($dependencyRoot === $packageRoot) {
                $this->usePackageDependencyLock($stand.'/composer.lock', $packageRoot.'/composer.lock');
            }
            $this->rewriteLockedPackage($stand.'/composer.lock', $package);
            $exit = $this->process->run(['cp', '-R', $dependencyRoot.'/vendor', $stand.'/vendor'], $stand);
            if ($exit === 0) {
                $exit = $this->process->run(['composer', 'install', '--prefer-dist', '--no-interaction', '--no-progress'], $stand, [
                    'COMPOSER_DISABLE_NETWORK' => '1',
                ]);
            }
            $environment = [
                'RICK_STAND_ARCHIVE_CHILD' => '1',
                'RICK_STAND_REPORT_ROOT' => $packageRoot.'/build/test-stand/latest',
            ];
            if ($exit !== 0) {
                if (getenv('RICK_STAND_ALLOW_ARCHIVE_FALLBACK') !== '1') {
                    return $exit;
                }
                fwrite(STDERR, "Composer archive consumer install was unavailable; using the installed dependency set with archive-first autoloading.\n");
                $environment += [
                    'RICK_STAND_PACKAGE_ROOT' => $package,
                    'RICK_STAND_AUTOLOAD' => $packageRoot.'/vendor/autoload.php',
                    'RICK_STAND_PEST' => $packageRoot.'/vendor/bin/pest',
                ];
            }
            $command = [PHP_BINARY, $stand.'/bin/rick-stand', 'run', '--lane='.$lane, '--target=archive'];
            if ($scenario !== null) {
                $command[] = '--scenario='.$scenario;
            }

            return $this->process->run($command, $stand, $environment);
        } finally {
            $this->removeTemporary($temporary);
        }
    }

    private function copyStand(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            if (str_starts_with($relative, 'vendor/')) {
                continue;
            }
            $destination = $target.'/'.$relative;
            if ($entry->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0777, true);
                }
            } elseif ($entry->isFile()) {
                if (! is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0777, true);
                }
                copy($entry->getPathname(), $destination);
            }
        }
    }

    private function rewriteLockedPackage(string $lockPath, string $package): void
    {
        $lock = json_decode((string) file_get_contents($lockPath), true, flags: JSON_THROW_ON_ERROR);
        $found = false;
        foreach (['packages', 'packages-dev'] as $group) {
            foreach ($lock[$group] ?? [] as $index => $locked) {
                if (($locked['name'] ?? null) !== 'rickphp/laravel-rick') {
                    continue;
                }
                $lock[$group][$index]['dist'] = [
                    'type' => 'path',
                    'url' => $package,
                    'reference' => hash_file('sha256', $package.'/composer.json'),
                ];
                $lock[$group][$index]['transport-options'] = ['symlink' => false];
                $found = true;
            }
        }
        if (! $found) {
            throw new RuntimeException('The stand lock file does not contain rickphp/laravel-rick.');
        }
        file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private function usePackageDependencyLock(string $standLockPath, string $packageLockPath): void
    {
        $standLock = json_decode((string) file_get_contents($standLockPath), true, flags: JSON_THROW_ON_ERROR);
        $packageLock = json_decode((string) file_get_contents($packageLockPath), true, flags: JSON_THROW_ON_ERROR);
        $rick = null;
        foreach (array_merge($standLock['packages'] ?? [], $standLock['packages-dev'] ?? []) as $locked) {
            if (($locked['name'] ?? null) === 'rickphp/laravel-rick') {
                $rick = $locked;
                break;
            }
        }
        if (! is_array($rick)) {
            throw new RuntimeException('The stand lock file does not contain rickphp/laravel-rick.');
        }
        $standLock['packages'] = [...($packageLock['packages'] ?? []), $rick];
        $standLock['packages-dev'] = $packageLock['packages-dev'] ?? [];
        file_put_contents($standLockPath, json_encode($standLock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private function removeTemporary(string $directory): void
    {
        $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'laravel-rick-stand-archive-';
        if (! str_starts_with($directory, $prefix) || ! is_dir($directory)) {
            throw new RuntimeException('Refusing to remove an unexpected archive stand path.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
