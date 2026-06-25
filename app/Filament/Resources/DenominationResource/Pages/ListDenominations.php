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
            $this->submitPendingAction(),
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
     * Push every not-yet-submitted pool denomination (DRAFT or WAIT) to the bot.
     *
     * Submits each one immediately via the resource helper and reports a summary
     * (sent / deferred / errors). Deferred names stay in WAIT so they can be
     * retried once business hours / FIEL capacity allow.
     */
    private function submitPendingAction(): Action
    {
        return Action::make('submit_pending')
            ->label('Enviar pendientes a la SE')
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription('Se enviarán al portal MUA todas las denominaciones en borrador o en espera (si es horario hábil y hay FIEL disponible).')
            ->action(function (): void {
                $pending = LegalName::whereNull('registration_id')
                    ->whereIn('status', [
                        LegalNameStatusEnum::DRAFT->value,
                        LegalNameStatusEnum::WAIT->value,
                    ])
                    ->get();

                if ($pending->isEmpty()) {
                    Notification::make()
                        ->title('No hay denominaciones pendientes de envío.')
                        ->info()
                        ->send();

                    return;
                }

                $sent = 0;
                $deferred = 0;
                $errors = 0;
                $reason = null;

                foreach ($pending as $name) {
                    $service = app(MuaSubmissionService::class);

                    try {
                        if ($service->trySubmit($name)) {
                            $sent++;

                            continue;
                        }
                    } catch (\Throwable $exception) {
                        Log::error('Pool denomination submission failed.', [
                            'legal_name_id' => $name->id,
                            'error' => $exception->getMessage(),
                        ]);
                        $errors++;

                        continue;
                    }

                    if ($name->status !== LegalNameStatusEnum::WAIT) {
                        $name->update(['status' => LegalNameStatusEnum::WAIT]);
                    }
                    $deferred++;
                    $reason ??= ! $service->isBusinessHours()
                        ? 'Fuera del horario hábil de la SE (Lun–Vie 09:00–16:00 CDMX).'
                        : 'No hay FIEL con capacidad disponible hoy (límite 5/día por FIEL).';
                }

                $body = "Enviadas: {$sent} · Diferidas: {$deferred} · Errores: {$errors}.";

                if ($deferred > 0 && $reason !== null) {
                    $body .= " {$reason}";
                }

                Notification::make()
                    ->title('Envío de denominaciones procesado.')
                    ->body($body)
                    ->status($errors > 0 || $deferred > 0 ? 'warning' : 'success')
                    ->send();
            });
    }
}
