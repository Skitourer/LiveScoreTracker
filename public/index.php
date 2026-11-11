<?php

declare(strict_types=1);

use LiveScoreTracker\Kernel;

// PHP's built-in development server: let it serve existing static files itself.
if (PHP_SAPI === 'cli-server') {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : null;
    if (is_string($path) && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

$rootDir = dirname(__DIR__);

require $rootDir . '/vendor/autoload.php';

Kernel::createApp(Kernel::loadSettings($rootDir))->run();
