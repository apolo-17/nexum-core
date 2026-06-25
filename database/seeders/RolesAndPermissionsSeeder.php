<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the core roles for the Nexum notary dashboard.
 *
 * - super_admin  : Full access to all resources and configuration.
 * - notario      : Manages expedients, validates identity, moves stages.
 * - asistente_notario : Supports the notary with tasks and document uploads.
 * - developer    : Read-only access to the API documentation (/docs/api) only.
 *                  Excluded from User::canAccessPanel(), so it cannot enter the
 *                  Filament admin nor edit any data.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the seeder — create roles if they do not already exist.
     */
    public function run(): void
    {
        // Reset cached roles and permissions to avoid stale guards.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'notario']);
        Role::firstOrCreate(['name' => 'asistente_notario']);
        Role::firstOrCreate(['name' => 'developer']);

        $this->command->info('Roles created: super_admin, notario, asistente_notario, developer');
    }
}
