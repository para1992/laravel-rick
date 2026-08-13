<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

if (getenv('RICK_LIVE_CONFIRM_COST') !== '1') {
    throw new RuntimeException(
        'Paid smoke tests require RICK_LIVE_CONFIRM_COST=1.',
    );
}

$apiKey = getenv('OPENROUTER_API_KEY');
if (! is_string($apiKey) || trim($apiKey) === '') {
    throw new RuntimeException(
        'Paid smoke tests require OPENROUTER_API_KEY.',
    );
}

$localAutoload = dirname(__DIR__).'/vendor/autoload.php';
$monorepoAutoload = dirname(__DIR__, 2).'/vendor/autoload.php';
$autoload = is_file($localAutoload) ? $localAutoload : $monorepoAutoload;

if (! is_file($autoload)) {
    throw new RuntimeException('Composer autoloader was not found.');
}

/** @var ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('Rick\\Laravel\\LiveTests\\', __DIR__, true);
$loader->addPsr4('Rick\\Laravel\\Tests\\', dirname(__DIR__).'/tests', true);
$loader->addPsr4('Rick\\Laravel\\', dirname(__DIR__).'/src', true);
