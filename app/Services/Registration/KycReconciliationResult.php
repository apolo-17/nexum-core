<?php

declare(strict_types=1);

namespace App\Services\Registration;

/**
 * Outcome of reconciling shareholders against their passports.
 *
 * `warnings` are non-blocking messages for the team to review (a passport number that does
 * not match, or an official name that is a different person than the one on file).
 * `officialNames` maps a shareholder id to the passport's official spelling when it is the
 * same person written differently (e.g. a different word order) — safe to adopt for the acta.
 */
final class KycReconciliationResult
{
    /**
     * @param  list<string>  $warnings  Non-blocking review messages.
     * @param  array<string, string>  $officialNames  shareholder id => official passport name.
     */
    public function __construct(
        public readonly array $warnings,
        public readonly array $officialNames,
    ) {}
}
