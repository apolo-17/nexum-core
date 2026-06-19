<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow null values for from_stage and performed_by on stage_transitions.
 *
 * The initial webhook arrival record has no preceding stage (from_stage = null)
 * and no human performer (performed_by = null) — it is a system-generated event.
 * Both columns must be nullable to represent this correctly.
 */
return new class extends Migration
{
    /**
     * Make from_stage and performed_by nullable.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('stage_transitions', function (Blueprint $table) {
            $table->string('from_stage')->nullable()->change();
            $table->foreignId('performed_by')->nullable()->change();
        });
    }

    /**
     * Revert to non-nullable (original schema).
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('stage_transitions', function (Blueprint $table) {
            $table->string('from_stage')->nullable(false)->change();
            $table->foreignId('performed_by')->nullable(false)->change();
        });
    }
};
