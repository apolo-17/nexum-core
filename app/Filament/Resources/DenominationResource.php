<?php

namespace App\Filament\Resources;

use App\Enums\LegalNameEventTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Filament\Resources\DenominationResource\Pages;
use App\Models\LegalName;
use App\Services\Mua\MuaSubmissionService;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry as InfoTextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Filament resource for the proactive denomination pool.
 *
 * Pool denominations are standalone (no registration): pre-generated with AI,
 * reviewed, then sent to the SE so the team always has approved names in stock.
 * Only shows names without a registration; per-expedient denominations are
 * managed from their own registration.
 */
class DenominationResource extends Resource
{
    /**
     * Timezone used to display stored (UTC) timestamps in the dashboard.
     *
     * Nexum's notary and the SE portal operate in CDMX, so all dates are shown
     * in this zone instead of the app's UTC default.
     */
    private const TIMEZONE = 'America/Mexico_City';

    /**
     * @var class-string<LegalName>
     */
    protected static ?string $model = LegalName::class;

    protected static ?string $navigationLabel = 'Denominaciones (Pool)';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 8;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-sparkles';
    }

    /**
     * Restrict access to the notary team (super_admin + notario).
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasAnyRole(['super_admin', 'notario']) ?? false;
    }

    /**
     * Scope the resource to standalone pool denominations (no registration).
     *
     * @return Builder<LegalName>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('registration_id');
    }

    /**
     * Define the table listing pool denominations.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Denominación')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('company_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => strtoupper((string) $state)),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (LegalNameStatusEnum $state): string => $state->label())
                    ->color(fn (LegalNameStatusEnum $state): string => $state->color()),

                TextColumn::make('soldado.name')
                    ->label('FIEL')
                    ->placeholder('Se asigna al enviar'),

                TextColumn::make('clave_unica_denominacion')
                    ->label('Folio SE')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Generada')
                    ->dateTime('d/m/Y H:i', self::TIMEZONE)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(fn (): array => collect(LegalNameStatusEnum::cases())
                        ->mapWithKeys(fn (LegalNameStatusEnum $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make(),

                Action::make('send_to_se')
                    ->label('Enviar a la SE')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn (LegalName $record): bool => in_array(
                        $record->status,
                        [LegalNameStatusEnum::DRAFT, LegalNameStatusEnum::WAIT],
                        true,
                    ))
                    ->requiresConfirmation()
                    ->modalDescription('Se enviará la denominación al portal MUA de inmediato (si es horario hábil y hay FIEL disponible).')
                    ->action(function (LegalName $record): void {
                        self::attemptSubmit($record)->send();
                    }),
            ]);
    }

    /**
     * Push a single pool denomination to the MUA bot right now and build the
     * resulting user notification.
     *
     * Triggered manually (the mua:submit cron is disabled), so the team controls
     * exactly when denominations are sent. If the submission is deferred — outside
     * SE business hours or no FIEL with daily capacity — the name is left in WAIT
     * and the notification explains why so the operator can retry later.
     *
     * @param  LegalName  $record  The pool denomination to submit.
     * @return Notification The notification describing the outcome (caller sends it).
     */
    public static function attemptSubmit(LegalName $record): Notification
    {
        $service = app(MuaSubmissionService::class);

        try {
            $submitted = $service->trySubmit($record);
        } catch (\Throwable $exception) {
            $record->recordEvent(
                LegalNameEventTypeEnum::SUBMISSION_FAILED,
                'Error al enviar al portal MUA.',
                ['error' => $exception->getMessage()],
            );

            return Notification::make()
                ->title("«{$record->name}»: error al enviar al portal MUA.")
                ->body($exception->getMessage())
                ->danger();
        }

        if ($submitted) {
            return Notification::make()
                ->title("«{$record->name}» enviada al portal MUA.")
                ->success();
        }

        // Deferred — keep it queued as WAIT and explain the reason.
        if ($record->status !== LegalNameStatusEnum::WAIT) {
            $record->update(['status' => LegalNameStatusEnum::WAIT]);
        }

        $reason = ! $service->isBusinessHours()
            ? 'Fuera del horario hábil de la SE (Lun–Vie 09:00–16:00 CDMX).'
            : 'No hay FIEL con capacidad disponible hoy (límite 5/día por FIEL).';

        $record->recordEvent(
            LegalNameEventTypeEnum::DEFERRED,
            'Envío diferido — quedó en espera.',
            ['reason' => $reason],
        );

        return Notification::make()
            ->title("«{$record->name}»: envío diferido — quedó en espera.")
            ->body($reason.' Vuelve a intentar cuando aplique.')
            ->warning()
            ->persistent();
    }

    /**
     * Define the detail (show) view: denomination data, derived timings and the
     * full lifecycle timeline (events).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Denominación')
                ->columns(2)
                ->poll('15s')
                ->schema([
                    InfoTextEntry::make('checking_indicator')
                        ->hiddenLabel()
                        ->badge()
                        ->color('info')
                        ->icon('heroicon-o-arrow-path')
                        ->columnSpanFull()
                        ->visible(fn (LegalName $record): bool => $record->isAwaitingCheckResult())
                        ->state(fn (): string => 'Consultando estado en la SE…'),

                    InfoTextEntry::make('name')->label('Denominación'),
                    InfoTextEntry::make('company_type')
                        ->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => strtoupper((string) $state)),
                    InfoTextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (LegalNameStatusEnum $state): string => $state->label())
                        ->color(fn (LegalNameStatusEnum $state): string => $state->color()),
                    InfoTextEntry::make('soldado.name')->label('FIEL')->placeholder('Se asigna al enviar'),
                    InfoTextEntry::make('clave_unica_denominacion')->label('Folio SE')->placeholder('—'),
                    InfoTextEntry::make('portal_status')->label('Estatus en portal SE')->placeholder('—'),
                    InfoTextEntry::make('rejection_reason')->label('Motivo de rechazo')->placeholder('—'),
                ]),

            Section::make('Tiempos')
                ->columns(3)
                ->poll('15s')
                ->schema([
                    InfoTextEntry::make('created_at')->label('Creada')->dateTime('d/m/Y H:i', self::TIMEZONE),
                    InfoTextEntry::make('submitted_at')->label('Enviada')->dateTime('d/m/Y H:i', self::TIMEZONE)->placeholder('—'),
                    InfoTextEntry::make('authorization_timestamp')->label('Resuelta')->dateTime('d/m/Y H:i', self::TIMEZONE)->placeholder('—'),
                    InfoTextEntry::make('queue_duration')
                        ->label('Tiempo en cola')
                        ->placeholder('—')
                        ->state(fn (LegalName $record): ?string => self::humanDuration($record->created_at, $record->submitted_at)),
                    InfoTextEntry::make('ruling_duration')
                        ->label('Tiempo de dictamen')
                        ->placeholder('—')
                        ->state(function (LegalName $record): ?string {
                            // Once resolved, measure up to the authorization; while still
                            // in dictamen, show the running time elapsed since submission.
                            $end = $record->authorization_timestamp
                                ?? ($record->isInProcess() ? now() : null);

                            $duration = self::humanDuration($record->submitted_at, $end);

                            if ($duration !== null && $record->authorization_timestamp === null) {
                                return "{$duration} (en curso)";
                            }

                            return $duration;
                        }),
                    InfoTextEntry::make('total_duration')
                        ->label('Tiempo total')
                        ->placeholder('—')
                        ->state(fn (LegalName $record): ?string => self::humanDuration($record->created_at, $record->authorization_timestamp)),
                ]),

            Section::make('Historial')
                ->poll('15s')
                ->schema([
                    ViewEntry::make('events')
                        ->hiddenLabel()
                        ->view('filament.infolists.legal-name-timeline'),
                ]),
        ]);
    }

    /**
     * Build a human-readable duration between two moments.
     *
     * Returns null when either endpoint is missing so the infolist falls back to
     * its placeholder. Uses an absolute, two-part interval (e.g. "2 horas 5 minutos").
     *
     * @param  CarbonInterface|null  $start  Interval start.
     * @param  CarbonInterface|null  $end  Interval end.
     * @return string|null The formatted duration, or null when incomputable.
     */
    private static function humanDuration(?CarbonInterface $start, ?CarbonInterface $end): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        return $start->diffAsCarbonInterval($end)->forHumans(['parts' => 2, 'short' => false]);
    }

    /**
     * Define the resource pages.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDenominations::route('/'),
            'view' => Pages\ViewDenomination::route('/{record}'),
        ];
    }
}
