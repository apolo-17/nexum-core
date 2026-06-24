<?php

namespace App\Filament\Resources\LegalAgentResource\Pages;

use App\Filament\Resources\LegalAgentResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for a legal agent (representative or commissary).
 */
class CreateLegalAgent extends CreateRecord
{
    /**
     * @var class-string<LegalAgentResource>
     */
    protected static string $resource = LegalAgentResource::class;
}
