<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the Laravel application instance for testing.
     *
     * Overrides the default implementation to redirect the services provider
     * cache to a process-specific temp file. This prevents any `artisan` command
     * running in the same container (e.g. `sail artisan migrate`) from writing or
     * corrupting the shared `bootstrap/cache/services.php` while tests are in flight.
     *
     * @return Application
     */
    public function createApplication(): Application
    {
        // Isolate the services cache to this test process so concurrent
        // artisan invocations in the Docker container cannot overwrite it.
        // The path is resolved by the framework from the APP_SERVICES_CACHE
        // env var (see Application::getCachedServicesPath()).
        $isolatedPath = sys_get_temp_dir() . '/nexum_services_' . getmypid() . '.php';
        putenv("APP_SERVICES_CACHE={$isolatedPath}");
        $_ENV['APP_SERVICES_CACHE']    = $isolatedPath;
        $_SERVER['APP_SERVICES_CACHE'] = $isolatedPath;

        /** @var Application $app */
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
