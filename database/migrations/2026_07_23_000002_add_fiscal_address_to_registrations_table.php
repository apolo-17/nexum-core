<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's Mexican tax address (domicilio fiscal).
 *
 * Captured by hand from the proof document (a utility bill or lease uploaded as
 * DocumentTypeEnum::PROOF_OF_ADDRESS_MX): the PDF is the evidence, these columns are the
 * data the team actually uses for the SAT paperwork.
 *
 * Split into parts rather than one free-text blob because the SAT forms ask for them
 * separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->string('fiscal_street')->nullable()->after('capital_social')
                ->comment('Calle');
            $table->string('fiscal_ext_number', 50)->nullable()->after('fiscal_street')
                ->comment('Número exterior');
            $table->string('fiscal_int_number', 50)->nullable()->after('fiscal_ext_number')
                ->comment('Número interior');
            $table->string('fiscal_neighborhood')->nullable()->after('fiscal_int_number')
                ->comment('Colonia');
            $table->string('fiscal_municipality')->nullable()->after('fiscal_neighborhood')
                ->comment('Alcaldía / municipio');
            $table->string('fiscal_state')->nullable()->after('fiscal_municipality')
                ->comment('Estado');
            $table->string('fiscal_postal_code', 10)->nullable()->after('fiscal_state')
                ->comment('Código postal');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn([
                'fiscal_street',
                'fiscal_ext_number',
                'fiscal_int_number',
                'fiscal_neighborhood',
                'fiscal_municipality',
                'fiscal_state',
                'fiscal_postal_code',
            ]);
        });
    }
};
