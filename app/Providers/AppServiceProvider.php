<?php

namespace App\Providers;

use App\Docs\OpenApiLocalizer;
use App\Http\Middleware\EnsureCanViewApiDocs;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

        // Suppress Scramble's single default doc route. Must run in register() so the
        // flag is set before ScrambleServiceProvider::boot() reads it; our bilingual
        // ES/EN variants are registered in boot() via configureApiDocs().
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->brandPasswordResetEmail();
        $this->activateUserOnPasswordReset();
        $this->configureApiDocs();
    }

    /**
     * Register the bilingual (ES/EN) Scramble API documentation.
     *
     * Instead of the single default doc, we register two API variants — "es" and
     * "en" — each covering the same V3 routes but localized by OpenApiLocalizer.
     * Each variant exposes only its JSON document (docs/api/{locale}.json); the
     * shared UI at /docs/api (see routes/web.php) loads both and lets the reader
     * toggle language in real time. The default routes are suppressed so no
     * untranslated doc is exposed.
     *
     * Access in production is gated by the `viewApiDocs` ability (super_admin or
     * the read-only `developer` role); in local everything is open (see Scramble's
     * RestrictedDocsAccess). The `developer` role grants the docs page only — it is
     * deliberately excluded from User::canAccessPanel(), so a developer can read the
     * API reference but cannot enter the Filament admin or edit any data.
     */
    private function configureApiDocs(): void
    {
        Gate::define('viewApiDocs', static fn (?User $user): bool => (bool) $user?->hasAnyRole(['super_admin', 'developer']));

        Scramble::ignoreDefaultRoutes();

        foreach (['es', 'en'] as $locale) {
            Scramble::registerApi($locale, ['middleware' => ['web', EnsureCanViewApiDocs::class]])
                ->routes(static fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), 'api/v3'))
                ->expose(ui: false, document: "docs/api/{$locale}.json")
                ->afterOpenApiGenerated(new OpenApiLocalizer($locale));
        }
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
