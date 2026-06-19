<?php

namespace App\Filament\Resources\MuaAccountResource\Pages;

use App\Filament\Resources\MuaAccountResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for a new MUA account (soldado FIEL).
 */
class CreateMuaAccount extends CreateRecord
{
    /**
     * @var class-string<MuaAccountResource>
     */
    protected static string $resource = MuaAccountResource::class;
}
