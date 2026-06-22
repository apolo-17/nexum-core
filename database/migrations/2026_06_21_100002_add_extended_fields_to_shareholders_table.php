<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds extended identity and contact fields to the shareholders table.
 *
 * These fields are needed to generate the acta constitutiva. They can arrive
 * from the Singapur relay (extended contract) or be extracted automatically via
 * Claude document analysis from the KYC passport and proof-of-address documents.
 * The notary team can also fill or correct them manually in the Filament dashboard.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shareholders', function (Blueprint $table): void {
            $table->string('gender')
                ->nullable()
                ->after('is_married')
                ->comment('M or F — used for gendered text in the acta constitutiva.');

            $table->date('birthdate')
                ->nullable()
                ->after('gender')
                ->comment('Date of birth. Required for the acta constitutiva.');

            $table->string('birthplace')
                ->nullable()
                ->after('birthdate')
                ->comment('City and country of birth. Required for the acta constitutiva.');

            $table->string('civil_status')
                ->nullable()
                ->after('birthplace')
                ->comment('Formal civil status: soltero, casado, divorciado, viudo. Derived from is_married if not provided.');

            $table->string('phone_country_code', 10)
                ->nullable()
                ->after('phone')
                ->comment('E.164 country dialling code, e.g. +86 for China. Used for DocuSign SMS verification.');

            $table->string('tax_id')
                ->nullable()
                ->after('phone_country_code')
                ->comment('Foreign tax identification number (NIF, TIN, etc.). Not applicable for Chinese nationals (use EXTF900101NI1).');

            $table->text('address_line')
                ->nullable()
                ->after('tax_id')
                ->comment('Full residential address as it will appear in the acta. Extracted from proof-of-address document.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shareholders', function (Blueprint $table): void {
            $table->dropColumn([
                'gender',
                'birthdate',
                'birthplace',
                'civil_status',
                'phone_country_code',
                'tax_id',
                'address_line',
            ]);
        });
    }
};
