<?php

namespace App\Filament\Resources\DenominationResource\Pages;

use App\Filament\Resources\DenominationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail (show) page for a pool denomination.
 *
 * Renders the resource infolist: denomination data, derived timings and the
 * full lifecycle timeline of events (created → submitted → in process → resolved).
 */
class ViewDenomination extends ViewRecord
{
    /**
     * @var class-string<DenominationResource>
     */
    protected static string $resource = DenominationResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
