<?php

// Horizon's service providers are excluded during the test suite to prevent
// the phpredis extension from being initialized, which crashes the PHP process
// on PHP 8.5 when Redis is not used. They are registered manually via
// AppServiceProvider::register() for all non-testing environments.
$testing = (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production')) === 'testing';

return array_values(array_filter([
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    $testing ? null : App\Providers\HorizonServiceProvider::class,
]));
