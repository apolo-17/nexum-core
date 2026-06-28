<?php

namespace App\Filament\Resources\MisCitasResource\Pages;

use App\Filament\Resources\MisCitasResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for the soldado's own SAT appointments.
 */
class ListMisCitas extends ListRecords
{
    /**
     * @var class-string<MisCitasResource>
     */
    protected static string $resource = MisCitasResource::class;
}
