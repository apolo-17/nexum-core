<?php

namespace Tests\Unit\Services\Mua;

use App\Services\Mua\MuaSubmissionService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MuaSubmissionService::resolveCompanyTypeSlug().
 *
 * Covers the company_type validation/normalization guard that runs at the submit
 * boundary — the bot owns the slug → SE régimen translation, so Nexum only needs
 * to forward a slug in {sa, srl, sapi}. No DB or HTTP involved.
 */
class MuaSubmissionServiceTest extends TestCase
{
    private MuaSubmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MuaSubmissionService;
    }

    // -------------------------------------------------------------------------
    // Display labels normalize to the bare slug
    // -------------------------------------------------------------------------

    #[Test]
    public function it_normalizes_the_stored_display_label_to_the_bare_slug(): void
    {
        $this->assertSame('sa', $this->service->resolveCompanyTypeSlug('SA de CV'));
        $this->assertSame('srl', $this->service->resolveCompanyTypeSlug('SRL de CV'));
        $this->assertSame('sapi', $this->service->resolveCompanyTypeSlug('SAPI de CV'));
    }

    // -------------------------------------------------------------------------
    // Already-bare slugs and mixed casing / whitespace
    // -------------------------------------------------------------------------

    #[Test]
    public function it_accepts_an_already_bare_slug(): void
    {
        $this->assertSame('sa', $this->service->resolveCompanyTypeSlug('sa'));
        $this->assertSame('srl', $this->service->resolveCompanyTypeSlug('srl'));
        $this->assertSame('sapi', $this->service->resolveCompanyTypeSlug('sapi'));
    }

    #[Test]
    public function it_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame('srl', $this->service->resolveCompanyTypeSlug('  Srl De Cv  '));
        $this->assertSame('sapi', $this->service->resolveCompanyTypeSlug('sapi'));
    }

    // -------------------------------------------------------------------------
    // Unsupported values are rejected
    // -------------------------------------------------------------------------

    #[Test]
    public function it_throws_on_an_unsupported_company_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->resolveCompanyTypeSlug('SAS de CV');
    }

    #[Test]
    public function it_throws_on_an_empty_company_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->resolveCompanyTypeSlug('');
    }
}
