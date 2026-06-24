<?php

namespace App\Filament\Resources\LegalAgentResource\Pages;

use App\Filament\Resources\LegalAgentResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit page for a legal agent (representative or commissary).
 */
class EditLegalAgent extends EditRecord
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
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
