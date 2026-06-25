<?php

namespace App\Filament\Resources\NotificationSettingResource\Pages;

use App\Filament\Resources\NotificationSettingResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for the notification settings module.
 *
 * No "create" action: events are defined in NotificationEventEnum, not by users.
 */
class ListNotificationSettings extends ListRecords
{
    /**
     * @var class-string<NotificationSettingResource>
     */
    protected static string $resource = NotificationSettingResource::class;
}
