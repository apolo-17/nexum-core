<?php

namespace Tests\Feature\Filament;

use App\Enums\DocumentTypeEnum;
use App\Filament\Resources\RegistrationResource;
use App\Models\Document;
use App\Models\Registration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The read-only `partner` role: sees expedientes and downloads documents, but never
 * the e.firma credentials, and cannot create/edit/delete anything.
 */
class PartnerRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function partner(): User
    {
        $user = User::factory()->create();
        $user->assignRole('partner');

        return $user;
    }

    #[Test]
    public function partner_can_access_registrations_but_is_read_only(): void
    {
        $this->actingAs($this->partner());

        $reg = Registration::factory()->create();

        $this->assertTrue(RegistrationResource::canAccess());
        $this->assertFalse(RegistrationResource::canCreate());
        $this->assertFalse(RegistrationResource::canEdit($reg));
        $this->assertFalse(RegistrationResource::canDelete($reg));
        $this->assertFalse(RegistrationResource::canDeleteAny());
    }

    #[Test]
    public function partner_can_log_into_the_panel(): void
    {
        $partner = $this->partner();

        $this->assertTrue($partner->isPartner());
        $this->assertTrue($partner->canAccessPanel(app(\Filament\Panel::class)));
    }

    #[Test]
    public function super_admin_keeps_full_access(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $reg = Registration::factory()->create();

        $this->assertTrue(RegistrationResource::canCreate());
        $this->assertTrue(RegistrationResource::canEdit($reg));
    }

    #[Test]
    public function partner_can_download_a_normal_document(): void
    {
        Storage::fake(config('filesystems.default'));
        $doc = Document::factory()->create([
            'type' => DocumentTypeEnum::CSF,
            'storage_path' => 'documents/x/csf.pdf',
        ]);
        Storage::put($doc->storage_path, 'contenido');

        $this->actingAs($this->partner())
            ->get(route('admin.documents.relay-download', $doc))
            ->assertOk();
    }

    #[Test]
    public function partner_cannot_download_an_efirma_document(): void
    {
        Storage::fake(config('filesystems.default'));
        $doc = Document::factory()->create([
            'type' => DocumentTypeEnum::EFIRMA,
            'storage_path' => 'documents/x/efirma.pdf',
        ]);
        Storage::put($doc->storage_path, 'secreto');

        $this->actingAs($this->partner())
            ->get(route('admin.documents.relay-download', $doc))
            ->assertForbidden();
    }

    #[Test]
    public function notary_can_download_an_efirma_document(): void
    {
        Storage::fake(config('filesystems.default'));
        $notary = User::factory()->create();
        $notary->assignRole('notario');

        $doc = Document::factory()->create([
            'type' => DocumentTypeEnum::EFIRMA,
            'storage_path' => 'documents/x/efirma.pdf',
        ]);
        Storage::put($doc->storage_path, 'secreto');

        $this->actingAs($notary)
            ->get(route('admin.documents.relay-download', $doc))
            ->assertOk();
    }
}
