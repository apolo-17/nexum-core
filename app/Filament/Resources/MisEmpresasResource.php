<?php

namespace App\Filament\Resources;

use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Filament\Resources\MisEmpresasResource\Pages;
use App\Models\Registration;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only resource showing the logged-in soldado the companies they registered.
 *
 * Scoped to registrations where the current user's soldado acts in the acta (legal
 * representative or commissary). Each company needs an RFC and a FIEL appointment —
 * the soldado tracks those from "Mis citas".
 */
class MisEmpresasResource extends Resource
{
    /**
     * @var class-string<Registration>
     */
    protected static ?string $model = Registration::class;

    protected static ?string $navigationLabel = 'Mis empresas';

    protected static ?string $modelLabel = 'Empresa';

    protected static ?string $pluralModelLabel = 'Mis empresas';

    protected static string|\UnitEnum|null $navigationGroup = 'Mi panel';

    protected static ?int $navigationSort = 2;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-building-office-2';
    }

    /**
     * Restrict access to soldados only.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('soldado') ?? false;
    }

    /**
     * This is a read-only view for soldados — no creation.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Scope to registrations the current user's soldado acts in.
     *
     * @return Builder<Registration>
     */
    public static function getEloquentQuery(): Builder
    {
        $soldadoId = Auth::user()?->soldado?->id;

        return parent::getEloquentQuery()
            ->whereHas('soldados', fn (Builder $query): Builder => $query->where('soldados.id', $soldadoId ?? ''))
            ->with('primaryLegalName');
    }

    /**
     * Define the table of the soldado's companies.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('primaryLegalName.name')
                    ->label('Empresa')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('company_type')
                    ->label('Tipo')
                    ->placeholder('—'),

                TextColumn::make('stage')
                    ->label('Etapa')
                    ->badge()
                    ->formatStateUsing(fn (RegistrationStageEnum $state): string => $state->label()),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (RegistrationStatusEnum $state): string => $state->label()),

                TextColumn::make('created_at')
                    ->label('Ingreso')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Define the resource pages — list only (read-only).
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMisEmpresas::route('/'),
        ];
    }
}
