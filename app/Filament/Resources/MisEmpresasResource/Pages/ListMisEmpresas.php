<?php

namespace App\Filament\Resources\MisEmpresasResource\Pages;

use App\Filament\Resources\MisEmpresasResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for the companies the soldado acts in.
 */
class ListMisEmpresas extends ListRecords
{
    /**
     * @var class-string<MisEmpresasResource>
     */
    protected static string $resource = MisEmpresasResource::class;
}
