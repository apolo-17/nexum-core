<?php

namespace App\Filament\Resources;

use App\Enums\LegalNameStatusEnum;
use App\Filament\Resources\DenominationResource\Pages;
use App\Models\LegalName;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
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
                    ->color(fn (LegalNameStatusEnum $state): string => match ($state) {
                        LegalNameStatusEnum::DRAFT => 'gray',
                        LegalNameStatusEnum::APPROVED => 'success',
                        LegalNameStatusEnum::REJECTED => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('muaAccount.name')
                    ->label('FIEL')
                    ->placeholder('Se asigna al enviar'),

                TextColumn::make('clave_unica_denominacion')
                    ->label('Folio SE')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Generada')
                    ->date('d/m/Y')
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
                Action::make('send_to_se')
                    ->label('Enviar a la SE')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn (LegalName $record): bool => $record->status === LegalNameStatusEnum::DRAFT)
                    ->requiresConfirmation()
                    ->modalDescription('La denominación pasará a la cola de envío al portal MUA en la próxima ventana hábil.')
                    ->action(function (LegalName $record): void {
                        $record->update(['status' => LegalNameStatusEnum::WAIT]);

                        Notification::make()
                            ->title('Denominación enviada a la cola de la SE.')
                            ->success()
                            ->send();
                    }),
            ]);
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
        ];
    }
}
