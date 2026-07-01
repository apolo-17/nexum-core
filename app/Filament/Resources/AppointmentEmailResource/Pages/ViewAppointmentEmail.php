<?php

namespace App\Filament\Resources\AppointmentEmailResource\Pages;

use App\Filament\Resources\AppointmentEmailResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail view of a pool address: availability and the appointments/companies it served.
 */
class ViewAppointmentEmail extends ViewRecord
{
    /**
     * @var class-string<AppointmentEmailResource>
     */
    protected static string $resource = AppointmentEmailResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
