<?php

namespace Tests\Feature\Denomination;

use App\Enums\LegalNameStatusEnum;
use App\Filament\Resources\DenominationResource;
use App\Models\LegalName;
use App\Models\MuaAccount;
use App\Models\MuaCredential;
use App\Models\Registration;
use App\Services\Mua\MuaSubmissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the standalone denomination pool.
 *
 * Covers: pool names exist without a registration (B1), the resource only lists
 * pool names (B4 scope), and a pool name is submitted to MUA using its own
 * company_type when there is no registration (B2).
 */
class DenominationPoolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_pool_denomination_can_exist_without_a_registration(): void
    {
        $name = LegalName::create([
            'registration_id' => null,
            'name' => 'ALFA CONSULTORES',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::DRAFT,
        ]);

        $this->assertDatabaseHas('legal_names', [
            'id' => $name->id,
            'registration_id' => null,
            'company_type' => 'srl',
        ]);
    }

    #[Test]
    public function the_resource_only_lists_pool_names(): void
    {
        $registration = Registration::factory()->create();

        LegalName::create([
            'registration_id' => $registration->id,
            'name' => 'EXPEDIENTE NAME',
            'priority' => 1,
            'status' => LegalNameStatusEnum::WAIT,
        ]);

        $pool = LegalName::create([
            'registration_id' => null,
            'name' => 'POOL NAME',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::DRAFT,
        ]);

        $ids = DenominationResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$pool->id], $ids);
    }

    #[Test]
    public function a_pool_name_submits_to_mua_with_its_own_company_type(): void
    {
        // Wednesday 10:00 CDMX — within the SE submission window.
        Carbon::setTestNow(Carbon::create(2026, 6, 24, 16, 0, 0, 'UTC'));

        config([
            'services.mua_bot.url' => 'http://mua-bot:8000',
            'services.mua_bot.api_key' => 'bot-key',
        ]);

        Http::fake(['http://mua-bot:8000/*' => Http::response([], 200)]);

        $account = MuaAccount::create([
            'name' => 'Soldado Uno',
            'rfc' => 'AAAA000101AAA',
            'is_active' => true,
        ]);

        foreach (['certificate', 'private_key', 'password'] as $type) {
            (new MuaCredential([
                'mua_account_id' => $account->id,
                'type' => $type,
            ]))->setEncryptedValue("value-{$type}")->save();
        }

        $poolName = LegalName::create([
            'registration_id' => null,
            'name' => 'BETA SERVICIOS',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::WAIT,
        ]);

        $submitted = app(MuaSubmissionService::class)->trySubmit($poolName);

        $this->assertTrue($submitted);

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/submit')
                && $request['company_type'] === 'srl'
                && $request['denomination'] === 'BETA SERVICIOS';
        });

        $this->assertSame(LegalNameStatusEnum::PENDING, $poolName->fresh()->status);

        Carbon::setTestNow();
    }
}
