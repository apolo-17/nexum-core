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
        Schema::create('tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();

            $table->string('title')->comment('Short task title');
            $table->text('description')->nullable()->comment('Detailed explanation of what needs to be done');
            $table->string('priority')->default('medium')->comment('low, medium, high');

            // Type distinguishes human tasks from system-automated ones
            $table->string('type')->default('manual')->comment('manual, automated');
            $table->string('automated_by')->nullable()->comment('Service that completed this task automatically, e.g. singapur_relay, sat_scraper');

            $table->date('due_date')->nullable();

            // Assignment
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');

            // Completion
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
