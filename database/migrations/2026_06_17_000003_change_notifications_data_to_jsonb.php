<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converts notifications.data from text to jsonb.
 *
 * Filament scopes its database notifications with a `data->>'format' = 'filament'`
 * filter. On PostgreSQL the ->> operator is undefined for text columns, so the
 * dashboard crashes. jsonb lets Postgres evaluate that filter natively.
 *
 * Raw SQL with an explicit USING cast is required because Postgres cannot
 * implicitly convert text to jsonb (a plain ALTER ... TYPE would fail).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
