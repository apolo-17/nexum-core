<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Registers Horizon's authorization gate and notification channels.
 *
 * The dashboard at /horizon is restricted to users with the super_admin role.
 * In local environments Laravel Horizon bypasses the gate automatically.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        Horizon::routeMailNotificationsTo(
            env('HORIZON_NOTIFICATION_EMAIL', '')
        );
    }

    /**
     * Register the Horizon gate.
     *
     * Only authenticated users with the super_admin role may access the dashboard
     * in non-local environments. Laravel Horizon skips this gate on local.
     *
     * @return void
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user): bool {
            return $user?->hasRole('super_admin') ?? false;
        });
    }
}
