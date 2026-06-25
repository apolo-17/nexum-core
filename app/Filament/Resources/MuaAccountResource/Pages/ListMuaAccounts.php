<?php

namespace App\Filament\Resources\MuaAccountResource\Pages;

use App\Filament\Resources\MuaAccountResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for MUA accounts (soldados FIEL).
 */
class ListMuaAccounts extends ListRecords
{
    /**
     * @var class-string<MuaAccountResource>
     */
    protected static string $resource = MuaAccountResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
