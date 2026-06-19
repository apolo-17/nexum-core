<?php

declare(strict_types=1);

namespace App\Services\LegalName;

use App\Models\LegalName;

/**
 * Deletes a denomination proposal and compacts the priority sequence of siblings.
 *
 * After deletion the remaining denominations are renumbered to ensure priorities
 * remain a contiguous sequence starting at 1.
 */
class DeleteLegalNameService
{
    /**
     * Remove the denomination and reorder sibling priorities.
     *
     * @param  LegalName  $legalName  The denomination to delete.
     *
     * @return void
     */
    public function handle(LegalName $legalName): void
    {
        $deletedPriority = $legalName->priority;
        $registrationId  = $legalName->registration_id;

        $legalName->delete();

        // Close the gap left by the deleted denomination.
        LegalName::where('registration_id', $registrationId)
            ->where('priority', '>', $deletedPriority)
            ->decrement('priority');
    }
}
