<?php

use Illuminate\Database\Migrations\Migration;

/**
 * No-op — superseded by the create_documents_table migration.
 *
 * storage_path is now defined directly in 2026_06_16_220004_create_documents_table.php.
 * relay_zip_path was removed from the add_relay_fields migration (2026_06_17) at the
 * same time, so there is nothing to rename here.
 *
 * This file is kept to avoid resetting the migrations table on environments that
 * may have already recorded it as run.
 */
return new class extends Migration
{
    /**
     * No-op.
     */
    public function up(): void
    {
        // Intentionally empty — see class docblock.
    }

    /**
     * No-op.
     */
    public function down(): void
    {
        // Intentionally empty — see class docblock.
    }
};
