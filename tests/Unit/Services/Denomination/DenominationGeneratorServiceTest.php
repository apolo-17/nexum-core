<?php

namespace Tests\Unit\Services\Denomination;

use App\Services\Denomination\DenominationGeneratorService;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for DenominationGeneratorService.
 *
 * The Anthropic call is faked so the test exercises prompt dispatch and the
 * parsing/normalization of the model's response without network access.
 */
class DenominationGeneratorServiceTest extends TestCase
{
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key']);
    }

    #[Test]
    public function it_parses_distinct_uppercased_names_from_the_model_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => '["Alfa Consultores", "Beta Servicios", "alfa consultores"]',
                ]],
            ]),
        ]);

        $names = app(DenominationGeneratorService::class)->generate(10);

        // Duplicates collapse case-insensitively and everything is upper-cased.
        $this->assertSame(['ALFA CONSULTORES', 'BETA SERVICIOS'], $names);
    }

    #[Test]
    public function it_caps_the_result_to_the_requested_quantity(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => '["UNO","DOS","TRES","CUATRO","CINCO"]',
                ]],
            ]),
        ]);

        $names = app(DenominationGeneratorService::class)->generate(2);

        $this->assertCount(2, $names);
    }

    #[Test]
    public function it_throws_when_the_api_key_is_missing(): void
    {
        config(['services.anthropic.api_key' => null]);

        $this->expectException(\RuntimeException::class);

        app(DenominationGeneratorService::class)->generate();
    }
}
