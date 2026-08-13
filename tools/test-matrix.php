<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir().'/laravel-rick-matrix-'.str_replace('.', '', uniqid('', true));
$preferSource = getenv('RICK_MATRIX_PREFER_SOURCE') === '1';
$lanes = [
    ['laravel' => '12', 'testbench' => '^10.0', 'ai' => '^0.9.1', 'dependencies' => 'lowest'],
    ['laravel' => '12', 'testbench' => '^10.0', 'ai' => '^0.10.0', 'dependencies' => 'highest'],
    ['laravel' => '13', 'testbench' => '^11.0', 'ai' => '^0.9.1', 'dependencies' => 'lowest'],
    ['laravel' => '13', 'testbench' => '^11.0', 'ai' => '^0.10.0', 'dependencies' => 'highest'],
];

mkdir($temporaryRoot, 0777, true);
$composerHome = $temporaryRoot.'/composer-home';
mkdir($composerHome, 0777, true);
$environment = matrixEnvironment($composerHome, $temporaryRoot.'/composer-cache');

try {
    foreach ($lanes as $lane) {
        $directory = sprintf(
            '%s/laravel-%s-%s',
            $temporaryRoot,
            $lane['laravel'],
            $lane['dependencies'],
        );
        copyTree($root, $directory);
        $composerPath = $directory.'/composer.json';
        $composerContents = file_get_contents($composerPath);
        if ($composerContents === false) {
            throw new RuntimeException('Unable to read composer.json.');
        }
        $composer = json_decode(
            $composerContents,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (! is_array($composer) || ! is_array($composer['require'] ?? null)
            || ! is_array($composer['require-dev'] ?? null)) {
            throw new RuntimeException('Composer matrix input has an invalid shape.');
        }
        foreach ([
            'illuminate/contracts',
            'illuminate/database',
            'illuminate/events',
            'illuminate/pipeline',
            'illuminate/queue',
            'illuminate/support',
        ] as $package) {
            $composer['require'][$package] = '^'.$lane['laravel'].'.0';
        }
        $composer['require']['laravel/ai'] = $lane['ai'];
        $composer['require-dev']['orchestra/testbench'] = $lane['testbench'];
        file_put_contents(
            $composerPath,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
        $lock = $directory.'/composer.lock';
        if (is_file($lock)) {
            unlink($lock);
        }

        fwrite(STDOUT, sprintf(
            "\nLaravel %s / Laravel AI %s / %s dependencies / PHP %s\n",
            $lane['laravel'],
            $lane['ai'],
            $lane['dependencies'],
            PHP_VERSION,
        ));
        $update = ['composer', 'update', '--with-all-dependencies', '--no-interaction', '--no-progress'];
        if ($preferSource) {
            $update[] = '--prefer-source';
        }
        if ($lane['dependencies'] === 'lowest') {
            $update[] = '--prefer-lowest';
            $update[] = '--prefer-stable';
        }
        runProcess($update, $directory, $environment);
        runProcess(['composer', 'qa'], $directory, $environment);
    }
} finally {
    removeTree($temporaryRoot);
}

/** Copy first-party package files without caches, build products, or VCS state. */
function copyTree(string $source, string $target): void
{
    $excluded = [
        '.composer-cache', '.git', '.local-ci', '.local.qa', '.pest.cache', '.phpstan.cache',
        '.phpunit.cache', 'build', 'graphify-out', 'vendor',
    ];
    mkdir($target, 0777, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        if (! $entry instanceof SplFileInfo) {
            continue;
        }
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $topLevel = explode(DIRECTORY_SEPARATOR, $relative, 2)[0];
        if (in_array($topLevel, $excluded, true)) {
            continue;
        }
        $destination = $target.DIRECTORY_SEPARATOR.$relative;
        if ($entry->isDir()) {
            if (! is_dir($destination)) {
                mkdir($destination, 0777, true);
            }
        } elseif ($entry->isFile()) {
            $parent = dirname($destination);
            if (! is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            copy($entry->getPathname(), $destination);
        }
    }
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 */
function runProcess(array $command, string $directory, array $environment): void
{
    $process = proc_open(
        $command,
        [STDIN, STDOUT, STDERR],
        $pipes,
        $directory,
        $environment,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start matrix command.');
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            'Matrix command [%s] failed with exit code %d.',
            implode(' ', $command),
            $exitCode,
        ));
    }
}

/** @return array<string, string> */
function matrixEnvironment(string $composerHome, string $composerCache): array
{
    $environment = getenv();
    $environment['COMPOSER_HOME'] = $composerHome;
    if (! isset($environment['COMPOSER_CACHE_DIR']) || $environment['COMPOSER_CACHE_DIR'] === '') {
        $environment['COMPOSER_CACHE_DIR'] = $composerCache;
    }

    return $environment;
}

function removeTree(string $directory): void
{
    $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'laravel-rick-matrix-';
    if (! str_starts_with($directory, $prefix) || ! is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if (! $entry instanceof SplFileInfo) {
            continue;
        }
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());
        } else {
            rmdir($entry->getPathname());
        }
    }
    rmdir($directory);
}
