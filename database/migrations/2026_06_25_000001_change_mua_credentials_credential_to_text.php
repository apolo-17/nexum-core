<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen mua_credentials.credential from varchar(4000) to text.
     *
     * The stored value is an encrypted FIEL component: the .cer / .key bytes are
     * base64-encoded and then Laravel-encrypted (AES + iv + mac, wrapped in a JSON
     * envelope), which easily exceeds 4000 characters and was rejected by Postgres
     * with "value too long for type character varying(4000)". TEXT has no length cap.
     */
    public function up(): void
    {
        Schema::table('mua_credentials', function (Blueprint $table) {
            $table->text('credential')->comment('Encrypted credential value')->change();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('mua_credentials', function (Blueprint $table) {
            $table->string('credential', 4000)->comment('Encrypted credential value')->change();
        });
    }
};
