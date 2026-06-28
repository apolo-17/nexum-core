<?php

namespace App\Filament\Resources\SoldadoResource\Pages;

use App\Filament\Resources\SoldadoResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for soldados.
 */
class ListSoldados extends ListRecords
{
    /**
     * @var class-string<SoldadoResource>
     */
    protected static string $resource = SoldadoResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Registrar soldado'),
        ];
    }
}
