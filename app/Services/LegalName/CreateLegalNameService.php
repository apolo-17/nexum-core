<?php

declare(strict_types=1);

namespace App\Services\LegalName;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Registration;

/**
 * Creates a denomination proposal for a registration and reorders priorities as needed.
 *
 * New denominations always start in WAIT status — they have not yet been submitted
 * to the SE. The bot picks them up on its next run.
 */
class CreateLegalNameService
{
    /**
     * Persist a new denomination proposal.
     *
     * When another denomination already holds the requested priority position,
     * all denominations at or above that position are shifted down by one.
     *
     * @param  Registration           $registration  Target registration.
     * @param  array<string, mixed>   $data          Validated payload: name, priority, mua_available.
     *
     * @return LegalName  The newly created record.
     */
    public function handle(Registration $registration, array $data): LegalName
    {
        $priority = (int) $data['priority'];

        // Shift existing denominations to make room at the requested priority.
        $registration->legalNames()
            ->where('priority', '>=', $priority)
            ->increment('priority');

        return LegalName::create([
            'registration_id' => $registration->id,
            'name'            => $data['name'],
            'priority'        => $priority,
            'status'          => LegalNameStatusEnum::WAIT->value,
            'mua_available'   => $data['mua_available'] ?? null,
        ]);
    }
}
