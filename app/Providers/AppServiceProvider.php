<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;

/**
 * Application-level service provider for cross-cutting registrations.
 *
 * Horizon is excluded from auto-discovery (composer.json dont-discover) to prevent
 * the phpredis extension from being initialized during the test suite, which causes
 * a fatal PHP process crash on PHP 8.5. We register it here manually for every
 * non-testing environment so production, staging, and local remain unaffected.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Horizon is not auto-discovered (see composer.json dont-discover).
        // Register it manually in all environments except testing, where its
        // Redis queue connector would trigger phpredis initialization and crash.
        if (! $this->app->environment('testing')) {
            $this->app->register(HorizonServiceProvider::class);
        }
    }
}
