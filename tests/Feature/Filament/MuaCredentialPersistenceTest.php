<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MuaAccountResource;
use App\Models\MuaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for MuaAccountResource::persistCredentials().
 *
 * Regression for the NOT NULL violation on mua_credentials.credential: the value
 * must be set before the row is inserted (firstOrNew + setEncryptedValue), not
 * after (updateOrCreate would insert a null credential first).
 */
class MuaCredentialPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stores_encrypted_credentials_and_skips_blank_values(): void
    {
        $account = MuaAccount::create([
            'name' => 'Soldado Uno',
            'rfc' => 'AAAA000101AAA',
            'is_active' => true,
        ]);

        MuaAccountResource::persistCredentials($account, [
            'certificate' => 'CERT-BASE64',
            'private_key' => 'KEY-BASE64',
            'password' => 'secret-pass',
            'unused' => null,
        ]);

        // Blank value skipped → only the three real credentials are written.
        $this->assertDatabaseCount('mua_credentials', 3);

        $fresh = $account->fresh();
        $this->assertSame('CERT-BASE64', $fresh->getCredential('certificate'));
        $this->assertSame('KEY-BASE64', $fresh->getCredential('private_key'));
        $this->assertSame('secret-pass', $fresh->getCredential('password'));
    }

    #[Test]
    public function it_updates_an_existing_credential_without_duplicating(): void
    {
        $account = MuaAccount::create([
            'name' => 'Soldado Dos',
            'rfc' => 'BBBB000101BBB',
            'is_active' => true,
        ]);

        MuaAccountResource::persistCredentials($account, ['certificate' => 'OLD-CERT']);
        MuaAccountResource::persistCredentials($account, ['certificate' => 'NEW-CERT']);

        $this->assertDatabaseCount('mua_credentials', 1);
        $this->assertSame('NEW-CERT', $account->fresh()->getCredential('certificate'));
    }
}
