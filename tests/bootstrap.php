<?php

/*
|--------------------------------------------------------------------------
| Test Bootstrap File
|--------------------------------------------------------------------------
|
| This file is loaded before running tests. It ensures that cached config
| files don't interfere with test execution by removing them if present.
|
*/

// Remove config cache if it exists to prevent env() calls from failing
$configCache = __DIR__ . '/../bootstrap/cache/config.php';
if (file_exists($configCache)) {
    @unlink($configCache);
}

// Remove route cache if it exists
$routeCache = __DIR__ . '/../bootstrap/cache/routes-v7.php';
if (file_exists($routeCache)) {
    @unlink($routeCache);
}

// Load Composer's autoloader
require __DIR__ . '/../vendor/autoload.php';
