<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shareholders', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();

            $table->string('name')->comment('Full legal name');
            $table->string('nationality')->comment('Country of nationality');
            $table->string('passport_number')->comment('Passport document number');
            $table->decimal('participation_percentage', 5, 2)->comment('Ownership percentage in the company');
            $table->string('role')->comment('legal_representative, shareholder, commissary');
            $table->string('email')->nullable();
            $table->string('phone')->nullable()->comment('Phone number with country code');
            $table->boolean('is_married')->default(false)->comment('Whether the shareholder is married — determines which KYC documents are expected (marriage cert + spouse passport)');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shareholders');
    }
};
