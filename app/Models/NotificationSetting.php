<?php

namespace App\Models;

use App\Enums\NotificationEventEnum;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Per-event notification configuration managed from the "Notificaciones" module.
 *
 * Each row maps a NotificationEventEnum case to a master on/off toggle and a set
 * of recipient users. Only super_admin users may be recipients — the relationship
 * and dispatch helper both enforce that, so other roles can never be notified.
 */
class NotificationSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'event',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => NotificationEventEnum::class,
            'enabled' => 'boolean',
        ];
    }

    /**
     * Users selected to receive this event's notifications.
     *
     * The query is constrained to super_admin so that, even if a recipient later
     * loses the role, they stop receiving notifications without any cleanup.
     *
     * @return BelongsToMany<User, $this>
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->role('super_admin');
    }

    /**
     * Human-readable label derived from the event enum.
     *
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->event?->label() ?? (string) $this->getRawOriginal('event'),
        );
    }

    /**
     * Ensure a settings row exists for every event defined in the enum.
     *
     * Self-healing: called by the settings UI and the dispatcher so newly added
     * events appear automatically without a manual migration or seeder run. New
     * rows default to enabled with no recipients until a super_admin configures
     * them.
     */
    public static function ensureEventsExist(): void
    {
        foreach (NotificationEventEnum::cases() as $event) {
            static::firstOrCreate(
                ['event' => $event->value],
                ['enabled' => true],
            );
        }
    }

    /**
     * Resolve the active recipients for an event, or an empty collection when the
     * event is disabled or unconfigured.
     *
     * @param  NotificationEventEnum  $event  The event being dispatched.
     * @return Collection<int, User> Super_admin recipients to notify.
     */
    public static function recipientsFor(NotificationEventEnum $event): Collection
    {
        $setting = static::firstOrCreate(
            ['event' => $event->value],
            ['enabled' => true],
        );

        if (! $setting->enabled) {
            return collect();
        }

        return $setting->recipients()->get();
    }
}
