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
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();

            $table->string('type')->comment('passport, visa, power_of_attorney, incorporation_act, csf, bank_proof, rfc_document, efirma, other');
            $table->string('name')->comment('Descriptive name for the document');

            // Google Drive storage references — nullable until the notary uploads the file to Drive.
            $table->string('google_drive_file_id')->nullable()->comment('File ID in Google Drive');
            $table->string('google_drive_url')->nullable()->comment('Direct access URL in Google Drive');

            // Traceability — uploaded_by is nullable for documents created automatically via the relay webhook.
            $table->string('stage')->comment('Process stage during which this document was uploaded');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable()->comment('Timestamp when the document was verified by the notary');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
