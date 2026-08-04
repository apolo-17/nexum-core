<?php

use Database\Seeders\SatModuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Populate the CDMX SAT offices (name + physical address + coordinates) in production,
 * so the soldado's appointment email can show the office address. Idempotent: the seeder
 * upserts by sat_id, leaving manual edits (is_active) untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SatModuleSeeder)->run();
    }

    public function down(): void
    {
        // Keep the offices; they are reference data, not something to roll back.
    }
};
