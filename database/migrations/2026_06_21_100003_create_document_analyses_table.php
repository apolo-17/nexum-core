<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the document_analyses table for Claude vision extraction results.
 *
 * When a KYC document is approved, an AnalyzeDocumentJob is dispatched. It sends
 * the document to the Anthropic Claude API (vision / multimodal) and stores the
 * extracted structured data here. The notary team can review and correct the
 * extracted values before generating the acta constitutiva.
 *
 * Supported document types that trigger analysis:
 *   - passport / national_identity  → document_number, gender, nationality, birthdate, birthplace
 *   - kyc_proof_of_address          → address, country_of_residence
 *   - kyc_marriage_certificate      → matrimonial_regime
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_analyses', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->boolean('analyzed')
                ->default(false)
                ->comment('True once Claude has successfully extracted fields from the document.');

            // Fields extracted from passport / national identity documents.
            $table->string('document_number')->nullable()->comment('Passport or national ID number.');
            $table->string('gender', 1)->nullable()->comment('M or F from identity document.');
            $table->string('nationality')->nullable()->comment('Nationality as printed in the document.');
            $table->date('birthdate')->nullable()->comment('Date of birth from the document.');
            $table->string('birthplace')->nullable()->comment('City / country of birth from the document.');
            $table->date('expiry_date')->nullable()->comment('Document expiry date.');

            // Fields extracted from proof-of-address documents.
            $table->text('address')->nullable()->comment('Full residential address as printed in the document.');
            $table->string('country_of_residence')->nullable()->comment('Country extracted from the address document.');

            // Fields extracted from marriage certificates.
            $table->string('matrimonial_regime')
                ->nullable()
                ->comment('sociedad_conyugal or separacion_de_bienes extracted from the marriage certificate.');

            // Audit / debug.
            $table->json('raw_response')
                ->nullable()
                ->comment('Full structured JSON response from Claude for review and debugging.');

            $table->text('error_message')
                ->nullable()
                ->comment('Error message if the analysis job failed, for manual inspection.');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_analyses');
    }
};
