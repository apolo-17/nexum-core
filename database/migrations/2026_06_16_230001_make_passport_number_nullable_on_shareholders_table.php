<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes shareholders.passport_number nullable.
 *
 * The passport number is not transmitted in the Singapur relay submission.json —
 * it arrives as a scanned document file. The notary team extracts and enters
 * the number manually after reviewing the uploaded passport image.
 * This migration must be coordinated with the 360 project (BD SOT).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shareholders', function (Blueprint $table) {
            $table->string('passport_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shareholders', function (Blueprint $table) {
            $table->string('passport_number')->nullable(false)->change();
        });
    }
};
