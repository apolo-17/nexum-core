<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the notification settings module tables.
 *
 * `notification_settings` holds one row per configurable event (see
 * NotificationEventEnum) with a master on/off toggle. `notification_setting_user`
 * is the pivot listing which users receive each event. Recipients are always
 * filtered to super_admin at dispatch time — other roles can neither be selected
 * nor notified.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('event')->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_setting_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_setting_id')
                ->constrained('notification_settings')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['notification_setting_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_setting_user');
        Schema::dropIfExists('notification_settings');
    }
};
