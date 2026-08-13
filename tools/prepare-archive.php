<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directory = $root.'/build/archive';
$archive = $directory.'/laravel-rick.tar';

if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    throw new RuntimeException("Unable to create archive directory [{$directory}].");
}

if (is_file($archive) && ! unlink($archive)) {
    throw new RuntimeException("Unable to replace archive [{$archive}].");
}
