<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 8:00 daily digest with the state of every active expedient.
 *
 * Email only, on purpose: this arrives every weekday whether or not anything
 * happened, so routing it to the Filament bell as well would bury the event-driven
 * alerts that actually need a click. Recipients and the on/off toggle live in the
 * "Notificaciones" settings module — see EventNotifier.
 *
 * Both the figures and the narrative are computed once, before dispatch, so every
 * recipient reads the same report and the AI briefing costs one API call per day
 * rather than one per person.
 */
class DailyDigestReport extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $digest  Payload from DailyDigestService::build().
     * @param  array{greeting: string, summary: string, priorities: array<int, string>}|null  $briefing
     *                                                                                                   Narrative from DailyDigestNarrator, or null when it was unavailable.
     */
    public function __construct(
        private readonly array $digest,
        private readonly ?array $briefing = null,
    ) {}

    /**
     * Declare the delivery channels.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the branded digest email.
     *
     * @param  mixed  $notifiable  The recipient User model.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->markdown('mail.digest.daily', [
                'digest' => $this->digest,
                'briefing' => $this->briefing,
                'dashboardUrl' => rtrim((string) config('app.url'), '/').'/admin',
            ]);
    }

    /**
     * Put the number that matters in the subject line, so the report can be
     * triaged from the inbox without opening it.
     */
    private function subject(): string
    {
        $totals = $this->digest['totals'];
        $date = $this->digest['as_of']->locale('es')->isoFormat('D MMM');

        $headline = $totals['overdue'] > 0
            ? "{$totals['overdue']} atrasados"
            : 'todo al día';

        return "Estado de expedientes — {$headline}, {$totals['active']} activos ({$date})";
    }
}
