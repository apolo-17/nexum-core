<?php

/*
|--------------------------------------------------------------------------
| PHPUnit Bootstrap
|--------------------------------------------------------------------------
|
| Clear the services provider cache before the test suite starts.
|
| When `php artisan optimize` is run in a non-testing environment,
| `bootstrap/cache/services.php` is built with HorizonServiceProvider.
| That provider eagerly initialises the phpredis extension which segfaults
| on PHP 8.5 when Redis is not actually needed during tests.
|
| Deleting the manifest here forces Laravel's ProviderRepository to
| rebuild it from `bootstrap/providers.php`, which correctly excludes
| HorizonServiceProvider when APP_ENV=testing.
|
*/

$servicesCache = __DIR__ . '/../bootstrap/cache/services.php';

if (file_exists($servicesCache)) {
    @unlink($servicesCache);
}

require_once __DIR__ . '/../vendor/autoload.php';
