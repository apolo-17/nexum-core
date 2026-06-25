<?php

namespace App\Filament\Resources\NotificationSettingResource\Pages;

use App\Filament\Resources\NotificationSettingResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit page for a single notification event: toggle + recipient selection.
 *
 * No delete action — events are fixed by code and must always remain configurable.
 */
class EditNotificationSetting extends EditRecord
{
    /**
     * @var class-string<NotificationSettingResource>
     */
    protected static string $resource = NotificationSettingResource::class;

    /**
     * Send the super_admin back to the list after saving.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
