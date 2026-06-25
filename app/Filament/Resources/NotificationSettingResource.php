<?php

namespace App\Filament\Resources;

use App\Enums\NotificationEventEnum;
use App\Filament\Resources\NotificationSettingResource\Pages;
use App\Models\NotificationSetting;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Filament module for configuring event notifications ("Notificaciones").
 *
 * Only accessible to super_admin. Lets an administrator enable/disable each event
 * and choose which super_admin users receive it (by database bell + email). Rows
 * are self-healing: every event defined in NotificationEventEnum is guaranteed to
 * exist whenever the module is opened. Events are fixed by code, so creating and
 * deleting rows is disabled — only editing.
 */
class NotificationSettingResource extends Resource
{
    /**
     * @var class-string<NotificationSetting>
     */
    protected static ?string $model = NotificationSetting::class;

    protected static ?string $navigationLabel = 'Notificaciones';

    /**
     * Navigation group — must match parent type exactly: string | UnitEnum | null.
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 6;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-bell-alert';
    }

    /**
     * Restrict the whole module to super_admin only.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Events are defined in code, never created from the UI.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Ensure a row exists for every configured event before listing/editing.
     *
     * @return Builder<NotificationSetting>
     */
    public static function getEloquentQuery(): Builder
    {
        NotificationSetting::ensureEventsExist();

        return parent::getEloquentQuery();
    }

    /**
     * Define the edit form: master toggle + recipient selection.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Placeholder::make('event_label')
                        ->label('Evento')
                        ->content(fn (NotificationSetting $record): string => $record->event?->label() ?? '—'),

                    Placeholder::make('event_description')
                        ->label('Descripción')
                        ->content(fn (NotificationSetting $record): string => $record->event?->description() ?? ''),

                    Toggle::make('enabled')
                        ->label('Notificación activada')
                        ->helperText('Si está desactivada, nadie recibe avisos de este evento.')
                        ->inline(false),

                    CheckboxList::make('recipients')
                        ->label('Destinatarios (solo administradores)')
                        ->helperText('Marca qué administradores reciben este aviso por correo y en la campana. Solo se listan usuarios con rol Administrador.')
                        ->relationship(
                            name: 'recipients',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->role('super_admin'),
                        )
                        ->descriptions(self::recipientDescriptions())
                        ->columns(2)
                        ->bulkToggleable()
                        ->noSearchResultsMessage('No hay administradores que coincidan.')
                        ->searchable(),
                ]),
        ]);
    }

    /**
     * Define the table listing configurable events.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Evento'),

                IconColumn::make('enabled')
                    ->label('Activada')
                    ->boolean(),

                TextColumn::make('recipients_count')
                    ->label('Destinatarios')
                    ->counts('recipients')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('updated_at')
                    ->label('Actualizada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Build the id => email map used to caption each recipient checkbox.
     *
     * @return array<int, string>
     */
    private static function recipientDescriptions(): array
    {
        return User::role('super_admin')
            ->pluck('email', 'id')
            ->all();
    }

    /**
     * Define the resource pages — list and edit only (no create/view).
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationSettings::route('/'),
            'edit' => Pages\EditNotificationSetting::route('/{record}/edit'),
        ];
    }
}
