<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEventEnum;
use App\Models\NotificationSetting;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Central entry point for dispatching configurable event notifications.
 *
 * Resolves the recipients chosen for an event in the "Notificaciones" settings
 * module (super_admin only) and sends the given notification to them, but only if
 * the event is enabled. Callers never need to know who receives an event or
 * whether it is on — they just describe what happened.
 */
class EventNotifier
{
    /**
     * Notify every configured recipient of an event.
     *
     * No-op when the event is disabled or has no recipients selected, so callers
     * can fire events unconditionally. Delivery channels (database bell + email)
     * are declared by the notification itself.
     *
     * @param  NotificationEventEnum  $event  The business event that occurred.
     * @param  Notification  $notification  The notification to deliver.
     */
    public function notify(NotificationEventEnum $event, Notification $notification): void
    {
        $recipients = NotificationSetting::recipientsFor($event);

        if ($recipients->isEmpty()) {
            return;
        }

        NotificationFacade::send($recipients, $notification);
    }

    /**
     * Return true when the event is enabled and has at least one recipient.
     *
     * Lets a caller skip expensive work (an API call, a heavy report) that would
     * be discarded by notify() anyway. Callers that build a notification cheaply
     * should just call notify() unconditionally.
     *
     * @param  NotificationEventEnum  $event  The event about to be dispatched.
     */
    public function hasRecipients(NotificationEventEnum $event): bool
    {
        return NotificationSetting::recipientsFor($event)->isNotEmpty();
    }
}
