<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\LegalAgentTypeEnum;
use App\Models\Registration;
use App\Models\Soldado;
use App\Services\Registration\Exceptions\NotEnoughApoderadosException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assigns the soldados that act as apoderados (legal representatives) in an acta.
 *
 * Every acta must name between MIN_APODERADOS and MAX_APODERADOS soldados. To keep the
 * workload fair, the service always picks the LEAST-LOADED eligible soldados — the ones
 * named in the fewest actas so far — and breaks ties at random so the same people are not
 * chosen every time. Over many actas each soldado accumulates a similar number of them.
 *
 * A soldado is eligible when they are active, flagged available_as_legal_representative,
 * and carry the RFC + email the downstream SAT appointment needs.
 */
class ApoderadoAssignmentService
{
    /** Minimum apoderados an acta must name. */
    public const MIN_APODERADOS = 3;

    /** Maximum apoderados an acta may name. */
    public const MAX_APODERADOS = 4;

    /**
     * Assign 3–4 least-loaded soldados to the registration as legal representatives.
     *
     * Idempotent: when the registration already has legal representatives they are returned
     * unchanged (no reassignment). Runs in a transaction with a row lock on the candidate
     * soldados so two concurrent ingestions do not overload the same people.
     *
     * @return Collection<int, Soldado> The soldados now assigned as apoderados.
     *
     * @throws NotEnoughApoderadosException When fewer than MIN_APODERADOS soldados are eligible.
     */
    public function assign(Registration $registration): Collection
    {
        $existing = $registration->legalRepresentatives()->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        return DB::transaction(function () use ($registration): Collection {
            $eligible = $this->eligibleQuery()->lockForUpdate()->get();

            if ($eligible->count() < self::MIN_APODERADOS) {
                throw new NotEnoughApoderadosException($eligible->count(), self::MIN_APODERADOS);
            }

            $chosen = $this->pickLeastLoaded($eligible);

            foreach ($chosen as $soldado) {
                $registration->soldados()->attach(
                    $soldado->id,
                    ['role' => LegalAgentTypeEnum::LEGAL_REPRESENTATIVE->value],
                );
            }

            return $chosen;
        });
    }

    /**
     * Pick the least-loaded soldados, up to MAX_APODERADOS.
     *
     * Shuffling first randomizes the order among soldados that share the same acta count;
     * a stable sort by acta count then keeps "fewest actas first" while preserving that
     * random order within each tier — so equally-loaded soldados rotate fairly.
     *
     * @param  Collection<int, Soldado>  $eligible  Eligible soldados carrying `actas_count`.
     * @return Collection<int, Soldado>
     */
    private function pickLeastLoaded(Collection $eligible): Collection
    {
        $take = min(self::MAX_APODERADOS, $eligible->count());

        return $eligible
            ->shuffle()
            ->sortBy('actas_count')
            ->take($take)
            ->values();
    }

    /**
     * Base query for eligible soldados, annotated with how many actas each already has as a
     * legal representative (`actas_count`) so the caller can rank them by workload.
     */
    private function eligibleQuery(): Builder
    {
        return Soldado::query()
            ->where('is_active', true)
            ->where('available_as_legal_representative', true)
            ->whereNotNull('rfc')
            ->whereNotNull('email')
            ->withCount([
                'registrations as actas_count' => fn (Builder $query) => $query
                    ->where('registration_soldado.role', LegalAgentTypeEnum::LEGAL_REPRESENTATIVE->value),
            ]);
    }
}
