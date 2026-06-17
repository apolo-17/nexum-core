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
     * @return void
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);
    }
}
