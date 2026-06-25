<?php

namespace App\Filament\Resources\DenominationResource\Pages;

use App\Enums\LegalNameStatusEnum;
use App\Filament\Resources\DenominationResource;
use App\Models\LegalName;
use App\Services\Denomination\DenominationGeneratorService;
use App\Services\Mua\MuaSubmissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Log;

/**
 * List page for the denomination pool, with the AI generation entry point.
 */
class ListDenominations extends ListRecords
{
    /**
     * @var class-string<DenominationResource>
     */
    protected static string $resource = DenominationResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->generateAction(),
            $this->sendAllDraftsAction(),
        ];
    }

    /**
     * Generate a batch of candidate denominations with AI and store them as drafts.
     */
    private function generateAction(): Action
    {
        return Action::make('generate')
            ->label('Generar denominaciones')
            ->icon('heroicon-o-sparkles')
            ->form([
                TextInput::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->default(10)
                    ->minValue(1)
                    ->maxValue(20)
                    ->required(),

                Select::make('company_type')
                    ->label('Tipo de sociedad')
                    ->options([
                        'srl' => 'SRL de CV',
                        'sa' => 'SA de CV',
                        'sapi' => 'SAPI de CV',
                    ])
                    ->default('srl')
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                try {
                    $names = app(DenominationGeneratorService::class)->generate((int) $data['quantity']);
                } catch (\Throwable $exception) {
                    Log::error('Denomination generation failed.', ['error' => $exception->getMessage()]);

                    Notification::make()
                        ->title('No se pudieron generar denominaciones.')
                        ->body('Revisa la configuración de Anthropic (ANTHROPIC_API_KEY).')
                        ->danger()
                        ->send();

                    return;
                }

                $created = 0;

                foreach ($names as $name) {
                    $exists = LegalName::whereNull('registration_id')
                        ->where('name', $name)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    LegalName::create([
                        'registration_id' => null,
                        'name' => $name,
                        'company_type' => $data['company_type'],
                        'priority' => 1,
                        'status' => LegalNameStatusEnum::DRAFT,
                    ]);

                    $created++;
                }

                $suggestedFiel = app(MuaSubmissionService::class)->findAvailableFiel();

                Notification::make()
                    ->title("Se generaron {$created} denominaciones (borrador).")
                    ->body('FIEL sugerida al enviar: '.($suggestedFiel?->name ?? 'ninguna disponible'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Send every reviewed draft to the SE submission queue at once.
     */
    private function sendAllDraftsAction(): Action
    {
        return Action::make('send_all_drafts')
            ->label('Enviar todos los borradores')
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription('Todas las denominaciones en borrador pasarán a la cola de envío al portal MUA.')
            ->action(function (): void {
                $count = LegalName::whereNull('registration_id')
                    ->where('status', LegalNameStatusEnum::DRAFT->value)
                    ->update(['status' => LegalNameStatusEnum::WAIT->value]);

                Notification::make()
                    ->title("{$count} denominaciones enviadas a la cola de la SE.")
                    ->success()
                    ->send();
            });
    }
}
