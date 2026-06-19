<?php

namespace Database\Seeders;

use App\Models\MuaAccount;
use App\Models\MuaCredential;
use Illuminate\Database\Seeder;

/**
 * Seeds the test MUA account using the FIEL credentials of Apolo (AOMA940814N35).
 *
 * The credentials are read from the mounted folder at runtime so they are never
 * hardcoded in version control. Run this seeder only in local/staging environments.
 *
 * Usage:
 *   vendor/bin/sail artisan db:seed --class=MuaAccountSeeder
 */
class MuaAccountSeeder extends Seeder
{
    /**
     * Path to the FIEL credentials folder (mounted on the dev machine).
     *
     * @var string
     */
    private const FIEL_DIR = '/var/www/html/../../Downloads/Renovacion_FIEL_AOMA940814N35_20260105151808';

    /**
     * Seed the test MUA account with FIEL credentials.
     *
     * @return void
     */
    public function run(): void
    {
        // Resolve credential files — try the mounted path first, then a fallback env path.
        $fielDir = env('FIEL_TEST_PATH', self::FIEL_DIR);

        $cerPath = $fielDir . '/00001000000721265245.cer';
        $keyPath = $fielDir . '/Claveprivada_FIEL_AOMA940814N35_20260105_151808.key';
        $password = env('FIEL_TEST_PASSWORD');

        if (! file_exists($cerPath) || ! file_exists($keyPath)) {
            $this->command->warn('FIEL files not found at: ' . $fielDir);
            $this->command->warn('Set FIEL_TEST_PATH in .env and try again.');

            return;
        }

        if (! $password) {
            $this->command->warn('FIEL_TEST_PASSWORD not set in .env. Skipping credential storage.');

            return;
        }

        $account = MuaAccount::updateOrCreate(
            ['rfc' => 'AOMA940814N35'],
            [
                'name'      => 'Apolinar Morales (test FIEL)',
                'is_active' => true,
            ]
        );

        // Store certificate — firstOrNew so the value is set before the INSERT hits the DB.
        MuaCredential::firstOrNew(['mua_account_id' => $account->id, 'type' => 'certificate'])
            ->setEncryptedValue(base64_encode(file_get_contents($cerPath)))
            ->save();

        // Store private key.
        MuaCredential::firstOrNew(['mua_account_id' => $account->id, 'type' => 'private_key'])
            ->setEncryptedValue(base64_encode(file_get_contents($keyPath)))
            ->save();

        // Store password.
        MuaCredential::firstOrNew(['mua_account_id' => $account->id, 'type' => 'password'])
            ->setEncryptedValue($password)
            ->save();

        $this->command->info("MUA account seeded: {$account->name} ({$account->rfc})");
        $this->command->info('Account is ready: ' . ($account->fresh()->isReady() ? 'YES ✓' : 'NO — check credentials'));
    }
}
