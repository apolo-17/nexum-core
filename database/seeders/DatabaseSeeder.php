<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Main database seeder — calls all sub-seeders in the correct order.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Run order matters: roles must exist before users are assigned to them.
     */
    public function run(): void
    {
        // Roles and the initial admin are required in every environment, production included.
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            NotificationSettingsSeeder::class,
        ]);

        // Demo expedientes are for local/staging only — never seed 60 fake companies
        // into production. Run `php artisan db:seed --class=ChineseCompaniesSeeder`
        // explicitly if you ever need them in a non-production environment.
        if (! app()->environment('production')) {
            $this->call(ChineseCompaniesSeeder::class);

            // Catalog of legal representatives / commissaries + sample assignments.
            // Runs after the demo companies so it can attach agents to real actas.
            $this->call(LegalAgentsSeeder::class);
        }
    }
}
