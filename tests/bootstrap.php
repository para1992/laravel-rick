<?php

declare(strict_types=1);
use Composer\Autoload\ClassLoader;

$localAutoload = dirname(__DIR__).'/vendor/autoload.php';
$monorepoAutoload = dirname(__DIR__, 2).'/vendor/autoload.php';
$autoload = is_file($localAutoload) ? $localAutoload : $monorepoAutoload;

if (! is_file($autoload)) {
    throw new RuntimeException('Composer autoloader was not found.');
}

/** @var ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('Rick\\Laravel\\Tests\\', __DIR__, true);
$loader->addPsr4('Rick\\Laravel\\', dirname(__DIR__).'/src', true);
