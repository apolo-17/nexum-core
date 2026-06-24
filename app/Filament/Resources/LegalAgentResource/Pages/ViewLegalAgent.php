<?php

namespace App\Filament\Resources\LegalAgentResource\Pages;

use App\Filament\Resources\LegalAgentResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail page for a legal agent — shows the profile and the actas it is assigned to.
 */
class ViewLegalAgent extends ViewRecord
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
            EditAction::make(),
        ];
    }
}
