<?php

namespace Database\Seeders;

use App\Models\Soldado;
use App\Models\SoldadoCredential;
use Illuminate\Database\Seeder;

/**
 * Seeds a test MUA-capable soldado using the FIEL credentials of Apolo (AOMA940814N35).
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
     */
    public function run(): void
    {
        // Resolve credential files — try the mounted path first, then a fallback env path.
        $fielDir = env('FIEL_TEST_PATH', self::FIEL_DIR);

        $cerPath = $fielDir.'/00001000000721265245.cer';
        $keyPath = $fielDir.'/Claveprivada_FIEL_AOMA940814N35_20260105_151808.key';
        $password = env('FIEL_TEST_PASSWORD');

        if (! file_exists($cerPath) || ! file_exists($keyPath)) {
            $this->command->warn('FIEL files not found at: '.$fielDir);
            $this->command->warn('Set FIEL_TEST_PATH in .env and try again.');

            return;
        }

        if (! $password) {
            $this->command->warn('FIEL_TEST_PASSWORD not set in .env. Skipping credential storage.');

            return;
        }

        $soldado = Soldado::updateOrCreate(
            ['rfc' => 'AOMA940814N35'],
            [
                'name' => 'Apolinar Morales (test FIEL)',
                'email' => 'apolo.test@notaria.mx',
                'available_for_mua' => true,
                'is_active' => true,
            ]
        );

        // Store certificate — firstOrNew so the value is set before the INSERT hits the DB.
        SoldadoCredential::firstOrNew(['soldado_id' => $soldado->id, 'type' => 'certificate'])
            ->setEncryptedValue(base64_encode(file_get_contents($cerPath)))
            ->save();

        // Store private key.
        SoldadoCredential::firstOrNew(['soldado_id' => $soldado->id, 'type' => 'private_key'])
            ->setEncryptedValue(base64_encode(file_get_contents($keyPath)))
            ->save();

        // Store password.
        SoldadoCredential::firstOrNew(['soldado_id' => $soldado->id, 'type' => 'password'])
            ->setEncryptedValue($password)
            ->save();

        $this->command->info("MUA soldado seeded: {$soldado->name} ({$soldado->rfc})");
        $this->command->info('Soldado is ready: '.($soldado->fresh()->isReadyForMua() ? 'YES ✓' : 'NO — check credentials'));
    }
}
