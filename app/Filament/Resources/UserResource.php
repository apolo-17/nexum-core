<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

/**
 * Filament resource for managing notary team members (users).
 *
 * Only accessible to super_admin. Lets an administrator invite new members
 * (name + email + role), edit their role, and re-send the activation email.
 * New users receive a welcome email with a link to set their own password;
 * until they do, they show as "Pendiente de activación".
 */
class UserResource extends Resource
{
    /**
     * @var class-string<User>
     */
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Usuarios';

    /**
     * Navigation group — must match parent type exactly: string | UnitEnum | null.
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 5;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-users';
    }

    /**
     * The roles a super_admin may assign, mapped to human-readable labels.
     *
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            'super_admin' => 'Administrador',
            'notario' => 'Notario',
            'asistente_notario' => 'Asistente de notario',
        ];
    }

    /**
     * Restrict the whole resource to super_admin only.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Define the form for inviting / editing a team member.
     *
     * No password field: the invitee sets their own password through the
     * emailed activation link.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del usuario')
                ->description('Al crear el usuario se le enviará un correo de bienvenida con un enlace para definir su contraseña.')
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Correo electrónico')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Select::make('role')
                        ->label('Rol')
                        ->options(self::roleOptions())
                        ->required()
                        ->native(false),
                ])->columns(2),
        ]);
    }

    /**
     * Define the table listing team members.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),

                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::roleOptions()[$state] ?? $state),

                TextColumn::make('email_verified_at')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (User $record): string => $record->email_verified_at === null ? 'Pendiente de activación' : 'Activo')
                    ->color(fn (User $record): string => $record->email_verified_at === null ? 'warning' : 'success'),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make(),

                Action::make('resend_invitation')
                    ->label('Reenviar invitación')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->visible(fn (User $record): bool => $record->email_verified_at === null)
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        self::sendInvitation($record);

                        Notification::make()
                            ->title('Invitación reenviada correctamente.')
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->visible(fn (User $record): bool => $record->id !== Auth::id()),
            ]);
    }

    /**
     * Issue a fresh activation token and email the invitation to the user.
     *
     * Reuses the password-reset broker so the link lands on Filament's branded
     * "set password" page.
     *
     * @param  User  $user  The user to (re-)invite.
     */
    public static function sendInvitation(User $user): void
    {
        $token = Password::broker()->createToken($user);

        $user->notify(new AccountInvitationNotification(
            $token,
            self::roleOptions()[$user->roles->first()?->name] ?? null,
        ));
    }

    /**
     * Define the resource pages.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
