<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the mua_credentials table.
     *
     * Stores the three credential components of a FIEL (e.firma):
     *   - certificate  → base64-encoded .cer file content
     *   - private_key  → base64-encoded .key file content
     *   - password     → passphrase to decrypt the private key
     *
     * Values are encrypted at rest using Laravel's Crypt facade before storage.
     */
    public function up(): void
    {
        if (Schema::hasTable('mua_credentials')) {
            return;
        }

        Schema::create('mua_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('mua_account_id')
                ->constrained('mua_accounts')
                ->cascadeOnDelete();

            $table->string('type')->comment('certificate | private_key | password');

            // varchar(4000): base64-encoded cert/key can be long; encrypted payload adds ~33% overhead.
            $table->string('credential', 4000)->comment('Encrypted credential value');

            $table->timestamps();

            $table->unique(['mua_account_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mua_credentials');
    }
};
