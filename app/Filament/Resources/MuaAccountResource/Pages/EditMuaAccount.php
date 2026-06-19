<?php

namespace App\Filament\Resources\MuaAccountResource\Pages;

use App\Filament\Resources\MuaAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit page for a MUA account (soldado FIEL).
 */
class EditMuaAccount extends EditRecord
{
    /**
     * @var class-string<MuaAccountResource>
     */
    protected static string $resource = MuaAccountResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return list<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
