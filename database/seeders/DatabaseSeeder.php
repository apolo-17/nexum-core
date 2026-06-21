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
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            ChineseCompaniesSeeder::class,
        ]);
    }
}
