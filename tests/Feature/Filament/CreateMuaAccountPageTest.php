<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MuaAccountResource\Pages\CreateMuaAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateMuaAccountPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_an_account_with_uploaded_credentials(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        $cer = UploadedFile::fake()->createWithContent('cert.cer', 'CERT-BYTES');
        $key = UploadedFile::fake()->createWithContent('key.key', 'KEY-BYTES');

        Livewire::test(CreateMuaAccount::class)
            ->fillForm([
                'name' => 'Soldado Test',
                'email' => 'soldado@test.com',
                'rfc' => 'aaaa000101aaa',
                'is_active' => true,
                'certificate_file' => $cer,
                'private_key_file' => $key,
                'private_key_password' => 'secret',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('mua_accounts', 1);
        $this->assertDatabaseCount('mua_credentials', 3);
    }

    #[Test]
    public function it_rejects_a_certificate_with_the_wrong_extension(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        Livewire::test(CreateMuaAccount::class)
            ->fillForm([
                'name' => 'Soldado Test',
                'email' => 'soldado@test.com',
                'rfc' => 'aaaa000101aaa',
                'is_active' => true,
                'certificate_file' => UploadedFile::fake()->create('virus.txt'),
                'private_key_file' => UploadedFile::fake()->createWithContent('key.key', 'KEY-BYTES'),
                'private_key_password' => 'secret',
            ])
            ->call('create')
            ->assertHasFormErrors(['certificate_file']);

        $this->assertDatabaseCount('mua_accounts', 0);
    }
}
