<?php

namespace App\Providers;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;

/**
 * Application-level service provider for cross-cutting registrations.
 *
 * Horizon is excluded from auto-discovery (composer.json dont-discover) to prevent
 * the phpredis extension from being initialized during the test suite, which causes
 * a fatal PHP process crash on PHP 8.5. We register it here manually for every
 * non-testing environment so production, staging, and local remain unaffected.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Horizon is not auto-discovered (see composer.json dont-discover).
        // Register it manually in all environments except testing, where its
        // Redis queue connector would trigger phpredis initialization and crash.
        if (! $this->app->environment('testing')) {
            $this->app->register(HorizonServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->brandPasswordResetEmail();
        $this->activateUserOnPasswordReset();
    }

    /**
     * Replace the default Laravel/Filament password-reset email with a branded
     * Spanish version. The closure receives the broker token and rebuilds the
     * Filament reset URL, so the link still lands on the panel's branded
     * "restablecer contraseña" page.
     */
    private function brandPasswordResetEmail(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = Filament::getResetPasswordUrl($token, $notifiable);
            $name = $notifiable->name ?? '';

            return (new MailMessage)
                ->subject('Restablece tu contraseña — Nexum Core')
                ->greeting(trim('Hola '.$name))
                ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta en Nexum Core.')
                ->action('Restablecer contraseña', $url)
                ->line('Este enlace caduca en 60 minutos. Si no solicitaste el cambio, ignora este correo.');
        });
    }

    /**
     * Mark a user as activated the first time they set a password through the
     * reset/invitation link. We reuse the existing email_verified_at column as
     * the "Pendiente vs Activo" flag so no extra migration is required.
     */
    private function activateUserOnPasswordReset(): void
    {
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User && $event->user->email_verified_at === null) {
                $event->user->forceFill(['email_verified_at' => now()])->save();
            }
        });
    }
}
