<?php

namespace Tests\Feature\Denomination;

use App\Enums\LegalNameStatusEnum;
use App\Jobs\SubmitDenominationToMuaNowJob;
use App\Models\LegalName;
use App\Services\LegalName\CheckMuaAvailabilityService;
use App\Services\Mua\MuaSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the SE public-registry check that runs before a denomination is sent.
 *
 * The SE refuses a trámite for a name already on its registry, and does so without
 * declaring the name "not viable" — so the bot reads it as a technical fault and
 * the denomination is retried indefinitely. Catching it here turns an endless loop
 * into one honest rejection.
 */
class SeRegistryPreCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_name_already_on_the_se_registry_is_rejected_without_sending(): void
    {
        // A non-empty `data` array is the portal's way of saying "taken".
        $this->fakeMuaReturning(['data' => [['name' => 'GUANG HUA COMERCIAL']]]);
        $name = $this->poolName('GUANG HUA COMERCIAL');

        (new SubmitDenominationToMuaNowJob($name->id))->handle(app(MuaSubmissionService::class));

        $fresh = $name->fresh();
        $this->assertSame(LegalNameStatusEnum::REJECTED, $fresh->status);
        $this->assertStringContainsString('ya está registrada en la SE', (string) $fresh->rejection_reason);
    }

    #[Test]
    public function an_unreachable_portal_never_blocks_a_submission(): void
    {
        // Treating "unknown" as "taken" would silently discard perfectly good names
        // every time the SE portal has a bad day.
        Http::fake(['mua.economia.gob.mx/*' => Http::response('', 503)]);
        $name = $this->poolName('NOMBRE NUEVO');

        (new SubmitDenominationToMuaNowJob($name->id))->handle(app(MuaSubmissionService::class));

        $this->assertNotSame(LegalNameStatusEnum::REJECTED, $name->fresh()->status);
    }

    #[Test]
    public function an_available_name_is_not_rejected_by_the_pre_check(): void
    {
        $this->fakeMuaReturning(['data' => []]);
        $name = $this->poolName('NOMBRE LIBRE');

        (new SubmitDenominationToMuaNowJob($name->id))->handle(app(MuaSubmissionService::class));

        $this->assertNotSame(LegalNameStatusEnum::REJECTED, $name->fresh()->status);
    }

    #[Test]
    public function the_batch_check_reports_taken_available_and_unknown(): void
    {
        Http::fake([
            'mua.economia.gob.mx/*' => Http::sequence()
                ->push(['data' => [['name' => 'TOMADA']]], 200)
                ->push(['data' => []], 200),
        ]);

        $result = app(CheckMuaAvailabilityService::class)->checkMany(['TOMADA', 'LIBRE']);

        $this->assertArrayHasKey('TOMADA', $result);
        $this->assertArrayHasKey('LIBRE', $result);
    }

    #[Test]
    public function names_with_special_characters_are_reported_as_unusable(): void
    {
        // The portal rejects these before even querying, so no request is made.
        Http::fake();

        $result = app(CheckMuaAvailabilityService::class)->checkMany(['ACME & CO.']);

        $this->assertFalse($result['ACME & CO.']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fakeMuaReturning(array $payload): void
    {
        Http::fake(['mua.economia.gob.mx/*' => Http::response($payload, 200)]);
    }

    private function poolName(string $name): LegalName
    {
        return LegalName::create([
            'registration_id' => null,
            'name' => $name,
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::WAIT,
        ]);
    }
}
