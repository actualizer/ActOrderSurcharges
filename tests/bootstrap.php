<?php declare(strict_types=1);

// Plugin lives at custom/plugins/ActOrderSurcharges/; project root is four levels up.
$projectRootAutoloader = __DIR__ . '/../../../../vendor/autoload.php';

if (!file_exists($projectRootAutoloader)) {
    fwrite(STDERR, "Project-root autoloader not found at {$projectRootAutoloader}.\n");
    fwrite(STDERR, "Run `composer install` (with --dev) in the project root.\n");
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $projectRootAutoloader;

$loader->addPsr4('Act\\OrderSurcharges\\', __DIR__ . '/../src/');
$loader->addPsr4('Act\\OrderSurcharges\\Tests\\', __DIR__ . '/');
