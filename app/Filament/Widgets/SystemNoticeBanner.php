<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * A one-time, dismissible notice at the top of the dashboard announcing the acta AI-extraction
 * change to the client. Once the user clicks "Autorizo", it disappears for good and we keep the
 * timestamp (users.system_notice_ack_at) as a record that they gave the go-ahead.
 */
class SystemNoticeBanner extends Widget
{
    protected string $view = 'filament.widgets.system-notice-banner';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    /**
     * Only the decision-maker (super_admin) sees it, and only until acknowledged.
     */
    public static function canView(): bool
    {
        $user = Auth::user();

        return ($user?->hasRole('super_admin') ?? false) && $user?->system_notice_ack_at === null;
    }

    /**
     * Whether to still render (used inside the view after an in-place acknowledge).
     */
    public function isVisible(): bool
    {
        return Auth::user()?->system_notice_ack_at === null;
    }

    /**
     * Record the go-ahead and make the banner disappear permanently.
     */
    public function acknowledge(): void
    {
        Auth::user()?->forceFill(['system_notice_ack_at' => now()])->save();
    }
}
