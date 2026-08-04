<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the romanized full name Claude reads from the passport, so the acta can show a
 * Latin-alphabet socio name even when the relay only sent the Chinese form value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_analyses', function (Blueprint $table): void {
            $table->string('full_name')->nullable()->after('analyzed')
                ->comment('Romanized full name as printed in the identity document.');
        });
    }

    public function down(): void
    {
        Schema::table('document_analyses', function (Blueprint $table): void {
            $table->dropColumn('full_name');
        });
    }
};
