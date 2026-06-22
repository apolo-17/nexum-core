<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the initial super_admin user for first access to the Filament dashboard.
 *
 * Credentials are read from environment variables so they are never hardcoded.
 * Safe to run multiple times — uses firstOrCreate to avoid duplicates.
 *
 * Required env vars:
 *   ADMIN_EMAIL    — email address for the super_admin account
 *   ADMIN_PASSWORD — plaintext password (hashed before storage)
 *   ADMIN_NAME     — display name (defaults to "Administrador")
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Create the initial super_admin user and assign the role.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@nexumcore.app');
        $password = env('ADMIN_PASSWORD', 'password');
        $name = env('ADMIN_NAME', 'Administrador');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        // Assign super_admin role — idempotent if already assigned.
        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }

        $this->command->info("super_admin user ready: {$email}");
    }
}
