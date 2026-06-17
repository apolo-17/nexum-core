<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Lists all registration expedients with filters and search.
 */
class ListRegistrations extends ListRecords
{
    protected static string $resource = RegistrationResource::class;

    /**
     * Return the header actions available on the list page.
     *
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo expediente'),
        ];
    }
}
