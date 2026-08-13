<?php

declare(strict_types=1);

$standRoot = dirname(__DIR__);
$autoloadOverride = getenv('RICK_STAND_AUTOLOAD');
$autoload = is_string($autoloadOverride) && is_file($autoloadOverride)
    ? $autoloadOverride
    : $standRoot.'/vendor/autoload.php';
if (! is_file($autoload)) {
    $packageRoot = dirname($standRoot, 2);
    $autoload = $packageRoot.'/vendor/autoload.php';
    putenv('RICK_STAND_PACKAGE_ROOT='.$packageRoot);
}
if (! is_file($autoload)) {
    throw new RuntimeException('Composer autoloader was not found for the test stand.');
}

$loader = require $autoload;
$packageOverride = getenv('RICK_STAND_PACKAGE_ROOT');
if (is_string($packageOverride) && is_dir($packageOverride.'/src')) {
    $loader->addPsr4('Rick\\Laravel\\', $packageOverride.'/src', true);
}
$loader->addPsr4('Rick\\Stand\\', $standRoot.'/src', true);
$loader->addPsr4('Rick\\Stand\\Tests\\', __DIR__, true);
