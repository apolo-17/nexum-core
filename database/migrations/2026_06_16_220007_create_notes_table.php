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
        Schema::create('notes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();

            $table->text('content')->comment('Free-text note written by a team member');
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_pinned')->default(false)->comment('Pinned notes appear at the top of the timeline');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
