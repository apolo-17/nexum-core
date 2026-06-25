<?php

namespace Tests\Feature\Api\V3;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the MUA bot callback handling standalone pool denominations.
 *
 * Pool names have no registration, so the callback must not assume one exists
 * when the SE resolves them — otherwise approval crashes on a null registration.
 */
class MuaBotCallbackPoolTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-mua-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['services.mua_bot.secret_key' => self::SECRET]);
    }

    #[Test]
    public function it_approves_a_pool_denomination_without_a_registration(): void
    {
        $pool = LegalName::create([
            'registration_id' => null,
            'name' => 'ALFA CONSULTORES',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::PENDING,
        ]);

        $response = $this->postCallback([
            'legal_name_id' => $pool->id,
            'status' => 'approved',
            'clave_unica' => 'A1B2C3',
            'authorization_at' => '2026-06-24T10:00:00Z',
            'constancia_pdf_base64' => base64_encode('fake-constancia-pdf'),
        ]);

        $response->assertOk();

        $fresh = $pool->fresh();
        $this->assertSame(LegalNameStatusEnum::APPROVED, $fresh->status);
        $this->assertSame('A1B2C3', $fresh->clave_unica_denominacion);

        Storage::disk('s3')->assertExists("denominations/pool/constancia_denominacion_{$pool->id}.pdf");
    }

    #[Test]
    public function it_rejects_a_pool_denomination_without_a_registration(): void
    {
        $pool = LegalName::create([
            'registration_id' => null,
            'name' => 'BETA SERVICIOS',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::PENDING,
        ]);

        $response = $this->postCallback([
            'legal_name_id' => $pool->id,
            'status' => 'rejected',
            'rejection_reason' => 'Nombre ya en uso.',
        ]);

        $response->assertOk();
        $this->assertSame(LegalNameStatusEnum::REJECTED, $pool->fresh()->status);
    }

    /**
     * POST a signed callback to the MUA bot endpoint.
     *
     * @param  array<string, mixed>  $body  Callback body (timestamp + signature added here).
     */
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
