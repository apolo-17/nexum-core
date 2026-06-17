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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Idempotency key — if the same event arrives twice, we skip processing
            $table->string('event_id')->unique()->comment('Unique event identifier from the source system');
            $table->string('source')->default('singapur_relay')->comment('Origin of the event, extensible for future integrations');

            $table->json('payload')->comment('Full raw payload received from the source');
            $table->string('status')->default('pending')->comment('pending, processed, failed');

            $table->dateTime('processed_at')->nullable()->comment('Timestamp when the event was successfully processed');
            $table->text('error_message')->nullable()->comment('Error detail if processing failed');

            $table->timestamps();

            $table->index(['source', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
