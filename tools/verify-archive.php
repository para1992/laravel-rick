<?php

declare(strict_types=1);

$path = $argv[1] ?? null;
if (! is_string($path) || ! is_file($path)) {
    throw new InvalidArgumentException('Archive path is missing or unreadable.');
}

$absolute = realpath($path);
if ($absolute === false) {
    throw new RuntimeException('Unable to resolve archive path.');
}

$files = [];
$prefix = 'phar://'.$absolute.'/';
$iterator = new RecursiveIteratorIterator(new PharData($absolute));
foreach ($iterator as $entry) {
    if (! $entry instanceof SplFileInfo || ! $entry->isFile()) {
        continue;
    }
    $name = str_replace('\\', '/', $entry->getPathname());
    if (str_starts_with($name, $prefix)) {
        $name = substr($name, strlen($prefix));
    }
    $files[] = ltrim($name, '/');
}
sort($files);

$required = [
    'CHANGELOG.md',
    'LICENSE',
    'README.md',
    'composer.json',
    'config/rick.php',
    'database/migrations/2026_07_26_000001_create_rick_execution_tables.php',
    'docs/installation.md',
    'src/Rick.php',
];
foreach ($required as $file) {
    if (! in_array($file, $files, true)) {
        throw new RuntimeException("Release archive is missing [{$file}].");
    }
}

$rootFiles = ['CHANGELOG.md', 'LICENSE', 'README.md', 'composer.json'];
$prefixes = ['config/', 'database/', 'docs/', 'src/', 'stubs/'];
foreach ($files as $file) {
    $allowed = in_array($file, $rootFiles, true);
    foreach ($prefixes as $allowedPrefix) {
        $allowed = $allowed || str_starts_with($file, $allowedPrefix);
    }
    if (! $allowed) {
        throw new RuntimeException("Release archive contains development file [{$file}].");
    }
}

fwrite(STDOUT, sprintf("Verified %d release archive files.\n", count($files)));
