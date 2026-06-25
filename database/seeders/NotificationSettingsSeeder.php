<?php

namespace Database\Seeders;

use App\Enums\NotificationEventEnum;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the notification settings module.
 *
 * Creates one row per event defined in NotificationEventEnum and, on first
 * creation, opts every current super_admin into it so behaviour matches the
 * previous "notify all admins" default. Existing rows and recipient selections
 * are never overwritten, so re-running the seeder is safe.
 */
class NotificationSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $superAdminIds = User::role('super_admin')->pluck('id');

        foreach (NotificationEventEnum::cases() as $event) {
            $setting = NotificationSetting::firstOrCreate(
                ['event' => $event->value],
                ['enabled' => true],
            );

            // Only seed default recipients on first creation to preserve any
            // manual selection made later from the dashboard.
            if ($setting->wasRecentlyCreated) {
                $setting->recipients()->syncWithoutDetaching($superAdminIds);
            }
        }
    }
}
