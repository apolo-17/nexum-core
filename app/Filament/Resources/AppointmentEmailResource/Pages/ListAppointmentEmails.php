<?php

namespace App\Filament\Resources\AppointmentEmailResource\Pages;

use App\Filament\Resources\AppointmentEmailResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for the SAT appointment email pool.
 */
class ListAppointmentEmails extends ListRecords
{
    /**
     * @var class-string<AppointmentEmailResource>
     */
    protected static string $resource = AppointmentEmailResource::class;
}
