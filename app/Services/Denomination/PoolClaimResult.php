<?php

declare(strict_types=1);

namespace App\Services\Denomination;

/**
 * Outcome of claiming a pool denomination for a registration.
 *
 * The claim and the constancia attachment are reported separately: a name can be
 * successfully linked to an expedient even when its SE constancia PDF is missing
 * (names approved before the pool stored PDFs, or marked approved by hand), and
 * the dashboard must be able to tell the operator exactly that.
 */
final readonly class PoolClaimResult
{
    /**
     * @param  bool  $claimed  Whether the denomination was linked to the registration.
     * @param  bool  $constanciaAttached  Whether the SE constancia PDF was copied into the expedient.
     * @param  string|null  $reason  Human-readable explanation when something did not happen.
     */
    public function __construct(
        public bool $claimed,
        public bool $constanciaAttached = false,
        public ?string $reason = null,
    ) {}
}
