<?php

namespace App\Services\Registration;

use App\Enums\LegalAgentTypeEnum;
use App\Models\Registration;
use App\Models\Soldado;
use Illuminate\Support\Str;

/**
 * Reconciles the parties extracted from an acta protocolizada against what we hold in the system:
 *
 *   - Fiscal attorneys (apoderados) are matched to our soldados BY RFC. A match is linked to the
 *     expediente as a legal representative (the role SAT appointments require); an extracted RFC we
 *     don't have is reported, never invented — a soldado must already exist (mirrors the intake sync).
 *   - Chinese shareholders are cross-checked BY NAME against the registration's shareholders, only to
 *     flag whether the acta matches the people we onboarded — no association is created for them.
 */
class ApoderadoReconciliationService
{
    /**
     * Reconcile the extracted parties and (for matched apoderados) link them to the expediente.
     *
     * @param  Registration  $registration  The expediente the acta belongs to.
     * @param  list<array{nombre?: string, rfc?: ?string}>  $apoderados  Fiscal attorneys from the acta.
     * @param  list<array{nombre?: string}>  $socios  Chinese shareholders from the acta.
     * @return array{
     *     apoderados_matched: list<array{nombre: string, rfc: string, soldado_id: string, name_matches: bool}>,
     *     apoderados_not_found: list<array{nombre: string, rfc: ?string}>,
     *     socios_check: list<array{nombre: string, en_sistema: bool}>
     * }
     */
    public function reconcile(Registration $registration, array $apoderados, array $socios = []): array
    {
        $matched = [];
        $notFound = [];

        foreach ($apoderados as $apoderado) {
            $rfc = strtoupper(trim((string) ($apoderado['rfc'] ?? '')));
            $nombre = trim((string) ($apoderado['nombre'] ?? ''));

            $soldado = $rfc !== ''
                ? Soldado::whereRaw('UPPER(rfc) = ?', [$rfc])->first()
                : null;

            if ($soldado === null) {
                $notFound[] = ['nombre' => $nombre, 'rfc' => $rfc !== '' ? $rfc : null];

                continue;
            }

            $registration->soldados()->syncWithoutDetaching([
                $soldado->id => ['role' => LegalAgentTypeEnum::LEGAL_REPRESENTATIVE->value],
            ]);

            $matched[] = [
                'nombre' => $nombre,
                'rfc' => $rfc,
                'soldado_id' => $soldado->id,
                'name_matches' => $this->namesLookAlike($nombre, (string) $soldado->name),
            ];
        }

        return [
            'apoderados_matched' => $matched,
            'apoderados_not_found' => $notFound,
            'socios_check' => $this->checkSocios($registration, $socios),
        ];
    }

    /**
     * Flag, for each extracted shareholder, whether a same-named shareholder exists on the expediente.
     *
     * @param  list<array{nombre?: string}>  $socios
     * @return list<array{nombre: string, en_sistema: bool}>
     */
    private function checkSocios(Registration $registration, array $socios): array
    {
        $known = $registration->shareholders()->pluck('name')
            ->map(fn ($n): string => $this->normalize((string) $n));

        $out = [];
        foreach ($socios as $socio) {
            $nombre = trim((string) ($socio['nombre'] ?? ''));
            if ($nombre === '') {
                continue;
            }
            $needle = $this->normalize($nombre);
            $out[] = [
                'nombre' => $nombre,
                'en_sistema' => $known->contains(fn (string $n): bool => $this->tokensOverlap($n, $needle)),
            ];
        }

        return $out;
    }

    /**
     * Loose name comparison: same after accent/upper/space normalization, or strong token overlap.
     * Names in actas are romanized and reordered, so exact equality is too strict.
     */
    private function namesLookAlike(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return $this->tokensOverlap($this->normalize($a), $this->normalize($b));
    }

    /**
     * True when the two normalized names share at least two tokens (or one, for very short names).
     */
    private function tokensOverlap(string $a, string $b): bool
    {
        $ta = array_filter(explode(' ', $a), fn ($t): bool => strlen($t) > 2);
        $tb = array_filter(explode(' ', $b), fn ($t): bool => strlen($t) > 2);
        $common = array_intersect($ta, $tb);

        return count($common) >= min(2, max(1, min(count($ta), count($tb))));
    }

    private function normalize(string $name): string
    {
        $name = Str::ascii($name);
        $name = strtoupper(trim($name));

        return (string) preg_replace('/\s+/', ' ', $name);
    }
}
