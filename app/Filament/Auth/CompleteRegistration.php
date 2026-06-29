<?php

namespace App\Filament\Auth;

use App\Filament\Resources\SoldadoResource;
use App\Models\Soldado;
use App\Models\User;
use Closure;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Onboarding page for an invited soldado: set password AND upload all their data and
 * documents in the SAME form.
 *
 * Extends Filament's secure password-reset flow (token handling, rate limiting) and adds
 * the soldado's profile fields (phone, RFC, CURP, INE, FIEL). For non-soldado users
 * (e.g. a notary using "forgot password") it behaves exactly like the normal reset page.
 */
class CompleteRegistration extends ResetPassword
{
    // The reset form binds fields to public properties (no statePath), so each profile
    // field needs its own property here.
    public ?string $phone = null;

    public ?string $rfc = null;

    public ?string $curp = null;

    public ?string $birthdate = null;

    public ?string $birthplace = null;

    public ?string $address = null;

    public mixed $ine_front_path = null;

    public mixed $ine_back_path = null;

    public mixed $certificate_file = null;

    public mixed $private_key_file = null;

    public ?string $private_key_password = null;

    /**
     * Cached resolved soldado for the email in the invitation link.
     */
    private ?Soldado $soldadoCache = null;

    private bool $soldadoResolved = false;

    /**
     * Resolve the soldado linked to the invited email, if any.
     */
    protected function getSoldado(): ?Soldado
    {
        if (! $this->soldadoResolved) {
            $email = $this->email ?? request()->query('email');
            $user = filled($email) ? User::where('email', $email)->first() : null;
            $this->soldadoCache = $user?->soldado;
            $this->soldadoResolved = true;
        }

        return $this->soldadoCache;
    }

    /**
     * Build the form: password + (for soldados) the full profile and documents.
     */
    public function form(Schema $schema): Schema
    {
        $components = [
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ];

        $soldado = $this->getSoldado();

        if ($soldado !== null) {
            $components[] = Section::make('Tus datos')
                ->description('Completa tu información para terminar tu registro.')
                ->columns(2)
                ->schema([
                    TextInput::make('phone')->label('Teléfono')->tel()->required()->maxLength(30),
                    TextInput::make('rfc')->label('RFC')->length(13)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null)
                        ->unique(table: Soldado::class, column: 'rfc', ignorable: $soldado)
                        ->validationMessages(['unique' => 'Este RFC ya está registrado para otro soldado.']),
                    TextInput::make('curp')->label('CURP')->length(18)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null),
                    DatePicker::make('birthdate')->label('Fecha de nacimiento')->native(false),
                    TextInput::make('birthplace')->label('Lugar de nacimiento')->maxLength(255),
                    TextInput::make('address')->label('Domicilio')->maxLength(255)->columnSpanFull(),
                ]);

            $components[] = Section::make('INE')
                ->columns(2)
                ->schema([
                    FileUpload::make('ine_front_path')->label('INE — anverso')
                        ->disk(config('filesystems.default'))->directory('soldados/ine')
                        ->visibility('private')->maxSize(4096),
                    FileUpload::make('ine_back_path')->label('INE — reverso')
                        ->disk(config('filesystems.default'))->directory('soldados/ine')
                        ->visibility('private')->maxSize(4096),
                ]);

            if ($soldado->available_for_mua) {
                $components[] = Section::make('FIEL (e.firma)')
                    ->description('Sube tu .cer y .key tal cual; se guardan cifrados.')
                    ->schema([
                        FileUpload::make('certificate_file')->label('Certificado (.cer)')
                            ->storeFiles(false)->maxSize(2048)
                            ->rules([fn (): Closure => self::extensionRule('cer')]),
                        FileUpload::make('private_key_file')->label('Llave privada (.key)')
                            ->storeFiles(false)->maxSize(2048)
                            ->rules([fn (): Closure => self::extensionRule('key')]),
                        TextInput::make('private_key_password')->label('Contraseña de la llave privada')
                            ->password()->revealable(),
                    ]);
            }
        }

        return $schema->components($components);
    }

    /**
     * Reset the password and, for a soldado, persist their profile and documents — in
     * one submit. Mirrors the parent flow with the profile save added to the callback.
     */
    public function resetPassword(): ?PasswordResetResponse
    {
        $data = $this->form->getState();
        $data['email'] = $this->email;
        $data['token'] = $this->token;

        $hasPanelAccess = true;

        $status = Password::broker(Filament::getAuthPasswordBroker())->reset(
            ['email' => $data['email'], 'password' => $data['password'], 'token' => $data['token']],
            function (CanResetPassword|Model|Authenticatable $user) use ($data, &$hasPanelAccess): void {
                if (($user instanceof FilamentUser) && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
                    $hasPanelAccess = false;

                    return;
                }

                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($data['password']),
                    $user->getRememberTokenName() => Str::random(60),
                ])->save();

                $this->saveSoldadoProfile($user, $data);

                event(new PasswordReset($user));
            }
        );

        if ($hasPanelAccess === false) {
            $status = Password::INVALID_USER;
        }

        if ($status === Password::PASSWORD_RESET) {
            Notification::make()->title('Registro completado. Ya puedes iniciar sesión.')->success()->send();

            return app(PasswordResetResponse::class);
        }

        Notification::make()->title(__($status))->danger()->send();

        return null;
    }

    /**
     * Persist the soldado's profile fields and FIEL from the submitted form data.
     *
     * @param  array<string, mixed>  $data
     */
    protected function saveSoldadoProfile(mixed $user, array $data): void
    {
        $soldado = $user->soldado ?? null;

        if (! $soldado instanceof Soldado) {
            return;
        }

        $soldado->update([
            'phone' => $data['phone'] ?? $soldado->phone,
            'rfc' => $data['rfc'] ?? $soldado->rfc,
            'curp' => $data['curp'] ?? $soldado->curp,
            'birthdate' => $data['birthdate'] ?? $soldado->birthdate,
            'birthplace' => $data['birthplace'] ?? $soldado->birthplace,
            'address' => $data['address'] ?? $soldado->address,
            'ine_front_path' => $data['ine_front_path'] ?? $soldado->ine_front_path,
            'ine_back_path' => $data['ine_back_path'] ?? $soldado->ine_back_path,
        ]);

        if ($soldado->available_for_mua) {
            SoldadoResource::persistCredentials($soldado, [
                'certificate' => SoldadoResource::uploadedFileToBase64($data['certificate_file'] ?? null),
                'private_key' => SoldadoResource::uploadedFileToBase64($data['private_key_file'] ?? null),
                'password' => $data['private_key_password'] ?? null,
            ]);
        }
    }

    /**
     * Reject an upload whose extension is not the given one (only when a file is present).
     *
     * @param  string  $extension  Required extension without the dot (e.g. 'cer').
     */
    private static function extensionRule(string $extension): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($extension): void {
            $file = is_array($value) ? reset($value) : $value;

            if ($file instanceof UploadedFile
                && strtolower($file->getClientOriginalExtension()) !== strtolower($extension)) {
                $fail("El archivo debe tener extensión .{$extension}.");
            }
        };
    }

    /**
     * Friendlier heading for the soldado onboarding.
     */
    public function getHeading(): string|Htmlable|null
    {
        return $this->getSoldado() !== null ? 'Completa tu registro' : parent::getHeading();
    }
}
