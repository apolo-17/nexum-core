<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Handles the creation of a new registration expedient from the dashboard.
 */
class CreateRegistration extends CreateRecord
{
    protected static string $resource = RegistrationResource::class;

    /**
     * Return the URL to redirect to after a successful creation.
     *
     * @return string
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
