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

            $table->string('type')->comment('See DocumentTypeEnum for valid values');
            $table->string('name')->comment('Descriptive name for the document');

            // S3 / R2 / MinIO storage path — set when the file is persisted.
            // Google Drive columns kept for backward-compatibility; no longer used for new uploads.
            $table->string('storage_path')->nullable()->comment('Path inside the configured S3-compatible disk');
            $table->string('google_drive_file_id')->nullable()->comment('Legacy: file ID in Google Drive');
            $table->string('google_drive_url')->nullable()->comment('Legacy: direct access URL in Google Drive');

            // Traceability — uploaded_by is nullable for documents received via the relay webhook.
            $table->string('stage')->comment('Process stage during which this document was uploaded or received');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Document evaluation — three states expressed as two nullable timestamps:
            //   pending  → verified_at IS NULL AND rejected_at IS NULL
            //   approved → verified_at IS NOT NULL
            //   rejected → rejected_at IS NOT NULL
            $table->dateTime('verified_at')->nullable()->comment('Set when the notary approves the document');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable()->comment('Set when the notary rejects the document');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->comment('Optional explanation for rejection');

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
