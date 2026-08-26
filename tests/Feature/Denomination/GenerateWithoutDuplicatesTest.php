<?php

namespace Tests\Feature\Denomination;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Registration;
use App\Services\Denomination\DenominationGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests that generation never proposes a denomination we already hold.
 *
 * A name the SE already granted us cannot be requested again: the portal refuses
 * the trámite and the bot retries it indefinitely. That is what happened with
 * GUANG HUA COMERCIAL — it lived on a registration, and the old check only looked
 * at pool names, so it was invisible.
 */
class GenerateWithoutDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key']);
    }

    #[Test]
    public function names_already_held_are_sent_to_the_model_as_forbidden(): void
    {
        $this->fakeClaudeReturning(['NUEVA UNO']);

        app(DenominationGeneratorService::class)->generate(1, ['GUANG HUA COMERCIAL']);

        Http::assertSent(function ($request): bool {
            $prompt = $request->data()['messages'][0]['content'];

            return str_contains($prompt, 'PROHIBIDO repetir')
                && str_contains($prompt, 'GUANG HUA COMERCIAL');
        });
    }

    #[Test]
    public function the_prompt_stays_clean_when_nothing_is_held_yet(): void
    {
        $this->fakeClaudeReturning(['NUEVA UNO']);

        app(DenominationGeneratorService::class)->generate(1);

        Http::assertSent(fn ($request): bool => ! str_contains(
            $request->data()['messages'][0]['content'],
            'PROHIBIDO repetir',
        ));
    }

    #[Test]
    public function a_name_tied_to_a_registration_still_counts_as_taken(): void
    {
        // The exact shape of the GUANG HUA bug: approved and attached to an
        // expedient, so a pool-only query never saw it.
        $registration = Registration::factory()->create();
        LegalName::create([
            'registration_id' => $registration->id,
            'name' => 'GUANG HUA COMERCIAL',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
        ]);

        $held = LegalName::pluck('name')->all();

        $this->assertContains('GUANG HUA COMERCIAL', $held);
    }

    /**
     * Fake the Anthropic Messages API with a fixed list of names.
     *
     * @param  list<string>  $names
     */
    private function fakeClaudeReturning(array $names): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['text' => json_encode($names)]],
            ], 200),
        ]);
    }
}
