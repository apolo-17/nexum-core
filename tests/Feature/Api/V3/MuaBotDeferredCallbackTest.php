<?php

namespace Tests\Feature\Api\V3;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Soldado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the `deferred` callback and the late-`submitted` recovery.
 *
 * Both cover failures seen in production on 2026-08-20: denominations that
 * bounced forever against one blocked FIEL, and a signed registration Nexum
 * threw away because a `failed` from an earlier attempt had already landed.
 */
class MuaBotDeferredCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-mua-secret';

    private const CAP_REASON = 'Error: Solo se pueden tener 1 solicitud(es) en proceso, y actualmente tiene 3.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mua_bot.secret_key' => self::SECRET]);
    }

    #[Test]
    public function deferred_returns_the_name_to_the_queue_and_parks_the_fiel(): void
    {
        $soldado = $this->soldado();
        $name = $this->denomination($soldado, LegalNameStatusEnum::SUBMITTING);

        $this->postCallback([
            'legal_name_id' => $name->id,
            'status' => 'deferred',
            'reason' => self::CAP_REASON,
            'blocks_fiel' => true,
        ])->assertOk();

        $this->assertSame(LegalNameStatusEnum::WAIT, $name->fresh()->status);

        $fresh = $soldado->fresh();
        $this->assertFalse($fresh->available_for_mua, 'A blocked FIEL must leave the MUA rotation.');
        $this->assertSame(self::CAP_REASON, $fresh->mua_blocked_reason);
        $this->assertNotNull($fresh->mua_blocked_at);
    }

    #[Test]
    public function a_parked_fiel_is_no_longer_offered_for_new_submissions(): void
    {
        $blocked = $this->soldado('Bloqueada');
        $name = $this->denomination($blocked, LegalNameStatusEnum::SUBMITTING);

        $this->postCallback([
            'legal_name_id' => $name->id,
            'status' => 'deferred',
            'reason' => self::CAP_REASON,
            'blocks_fiel' => true,
        ])->assertOk();

        $available = Soldado::where('available_for_mua', true)->pluck('id');

        $this->assertNotContains($blocked->id, $available->all());
    }

    #[Test]
    public function deferred_without_the_block_flag_leaves_the_fiel_usable(): void
    {
        $soldado = $this->soldado();
        $name = $this->denomination($soldado, LegalNameStatusEnum::SUBMITTING);

        $this->postCallback([
            'legal_name_id' => $name->id,
            'status' => 'deferred',
            'reason' => 'Motivo transitorio.',
            'blocks_fiel' => false,
        ])->assertOk();

        $this->assertSame(LegalNameStatusEnum::WAIT, $name->fresh()->status);
        $this->assertTrue($soldado->fresh()->available_for_mua);
    }

    #[Test]
    public function a_late_submitted_still_lands_after_the_name_went_back_to_wait(): void
    {
        // The production race: attempt A fails and returns the name to WAIT, then
        // attempt B's `submitted` arrives moments later. Dropping it left the SE
        // holding a registered request Nexum believed was still queued.
        $soldado = $this->soldado();
        $name = $this->denomination($soldado, LegalNameStatusEnum::SUBMITTING);

        $this->postCallback([
            'legal_name_id' => $name->id,
            'status' => 'failed',
            'reason' => 'Login did not reach Mis solicitudes.',
        ])->assertOk();

        $this->assertSame(LegalNameStatusEnum::WAIT, $name->fresh()->status);
        $this->assertSame(
            $soldado->id,
            $name->fresh()->soldado_id,
            'The attempting FIEL must be remembered so a late confirmation keeps it.',
        );

        $this->postCallback([
            'legal_name_id' => $name->id,
            'status' => 'submitted',
            'portal_status' => 'Enviada a dictamen',
        ])->assertOk();

        $fresh = $name->fresh();
        $this->assertSame(LegalNameStatusEnum::PENDING, $fresh->status);
        $this->assertSame('Enviada a dictamen', $fresh->portal_status);
        $this->assertSame($soldado->id, $fresh->soldado_id);
    }

    #[Test]
    public function a_submitted_callback_never_revives_a_resolved_denomination(): void
    {
        $soldado = $this->soldado();
        $name = $this->denomination($soldado, LegalNameStatusEnum::APPROVED);

        $this->postCallback([
            'legal_name_id' => $name->id,
            'status' => 'submitted',
        ])->assertOk();

        $this->assertSame(LegalNameStatusEnum::APPROVED, $name->fresh()->status);
    }

    private function soldado(string $name = 'FIEL de prueba'): Soldado
    {
        return Soldado::create([
            'name' => $name,
            'email' => str()->random(8).'@soldados.mx',
            'is_active' => true,
            'available_for_mua' => true,
        ]);
    }

    private function denomination(Soldado $soldado, LegalNameStatusEnum $status): LegalName
    {
        return LegalName::create([
            'registration_id' => null,
            'name' => 'ZHI HUA COMERCIO DIGITAL',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => $status,
            'soldado_id' => $soldado->id,
        ]);
    }

    private function postCallback(array $body): TestResponse
    {
        $body['timestamp'] = time();

        $signed = [
            'legal_name_id' => (string) $body['legal_name_id'],
            'status' => (string) $body['status'],
            'timestamp' => (int) $body['timestamp'],
        ];
        ksort($signed);

        $signature = hash_hmac(
            'sha256',
            json_encode($signed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            self::SECRET,
        );

        return $this->withHeader('X-Signature', $signature)
            ->postJson('/api/v3/webhook/mua-bot', $body);
    }
}
