<?php

use Illuminate\Database\Migrations\Migration;

/**
 * No-op — superseded by the create_documents_table migration.
 *
 * rejected_at, rejected_by and rejection_reason are now defined directly in
 * 2026_06_16_220004_create_documents_table.php. This file is kept to avoid
 * resetting the migrations table on environments that may have already recorded it.
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
