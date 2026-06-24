<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for team members (users).
 */
class ListUsers extends ListRecords
{
    /**
     * @var class-string<UserResource>
     */
    protected static string $resource = UserResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Invitar usuario'),
        ];
    }
}
