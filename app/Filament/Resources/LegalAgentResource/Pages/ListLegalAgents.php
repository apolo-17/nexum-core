<?php

namespace App\Filament\Resources\LegalAgentResource\Pages;

use App\Filament\Resources\LegalAgentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for the legal agents catalog.
 */
class ListLegalAgents extends ListRecords
{
    /**
     * @var class-string<LegalAgentResource>
     */
    protected static string $resource = LegalAgentResource::class;

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
