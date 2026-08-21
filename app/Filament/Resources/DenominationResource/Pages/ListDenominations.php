<?php

namespace App\Filament\Resources\DenominationResource\Pages;

use App\Enums\LegalNameEventTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Filament\Resources\DenominationResource;
use App\Filament\Widgets\MuaCapacityOverview;
use App\Jobs\SubmitDenominationToMuaNowJob;
use App\Models\LegalName;
use App\Services\Denomination\DenominationGeneratorService;
use App\Services\Mua\MuaSubmissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
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
     * Widgets shown above the table.
     *
     * Free soldiers is the ceiling on how many denominations can be sent right now
     * (the SE allows one in-process per RFC), so it belongs on screen rather than
     * only inside the send confirmation — asking "is everyone busy?" should not
     * require opening a dialog that sends things.
     *
     * @return list<class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MuaCapacityOverview::class,
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

                    $legalName = LegalName::create([
                        'registration_id' => null,
                        'name' => $name,
                        'company_type' => $data['company_type'],
                        'priority' => 1,
                        'status' => LegalNameStatusEnum::DRAFT,
                    ]);

                    $legalName->recordEvent(
                        LegalNameEventTypeEnum::CREATED,
                        'Generada por IA (borrador).',
                        ['company_type' => $data['company_type'], 'origin' => 'ai_pool'],
                    );

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
            // Show the capacity BEFORE confirming: how many soldiers can take work
            // right now, and how many are already holding a denomination. Without
            // this the operator cannot tell "everyone is busy" from "we only have
            // two soldiers configured".
            ->modalDescription(function (): string {
                $service = app(MuaSubmissionService::class);
                $pending = $this->pendingPoolQuery()->count();
                $fiel = $service->fielAvailability();

                $lines = [
                    "Denominaciones pendientes: {$pending}.",
                    "Soldados listos para MUA: {$fiel['ready']} · libres ahora: {$fiel['free']} · ocupados: {$fiel['busy']}.",
                ];

                if ($fiel['free'] > 0) {
                    $lines[] = 'Se enviarán '.min($pending, $fiel['free'])
                        .' de inmediato ('.implode(', ', $fiel['free_names']).'); el resto queda en cola.';
                } else {
                    $lines[] = 'Ningún soldado está libre: la SE permite una denominación en proceso por RFC. '
                        .'Las denominaciones quedarán en espera hasta que se libere alguno.';
                }

                return implode(' ', $lines);
            })
            ->action(function (): void {
                $service = app(MuaSubmissionService::class);
                $pending = $this->pendingPoolQuery()->get();

                if ($pending->isEmpty()) {
                    Notification::make()
                        ->title('No hay denominaciones pendientes de envío.')
                        ->info()
                        ->send();

                    return;
                }

                // Pre-check: if there is no complete FIEL at all, nothing can be sent.
                // Alert clearly and list every denomination that stayed unsent.
                if (! $service->hasAnyCompleteFiel()) {
                    $names = $pending->pluck('name')->implode(', ');

                    Notification::make()
                        ->title('No se registró ninguna denominación — sin FIEL disponible.')
                        ->body('No hay ninguna FIEL completa (certificado .cer, llave privada .key y contraseña). '
                            ."No se enviaron: {$names}. Configura una FIEL en el módulo de Soldados y vuelve a intentar.")
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $fiel = $service->fielAvailability();

                // Queue every submission instead of running them here. Each one blocks
                // on the bot for up to 30 s, so sending a batch inline pinned the web
                // request for minutes until PHP killed it mid-list — the names it never
                // reached showed no reason at all. Queued, the modal closes instantly
                // and each denomination reports its own outcome on its timeline.
                foreach ($pending as $name) {
                    if ($name->status !== LegalNameStatusEnum::WAIT) {
                        $name->update(['status' => LegalNameStatusEnum::WAIT]);
                    }

                    $name->recordEvent(
                        LegalNameEventTypeEnum::QUEUED,
                        'En cola de envío al portal MUA.',
                    );

                    SubmitDenominationToMuaNowJob::dispatch($name->id);
                }

                $queued = $pending->count();
                $willSend = min($queued, $fiel['free']);
                $body = "Se encolaron {$queued} denominaciones. "
                    ."Soldados listos: {$fiel['ready']} · libres: {$fiel['free']} · ocupados: {$fiel['busy']}.";

                if ($fiel['free'] === 0) {
                    $body .= ' Ningún soldado está libre ahora mismo, así que quedarán en espera '
                        .'hasta que una denominación en proceso se apruebe o se rechace.';
                } elseif ($queued > $fiel['free']) {
                    $body .= " Solo {$willSend} saldrán ahora (una por soldado); las demás esperan turno.";
                }

                Notification::make()
                    ->title('Envío encolado.')
                    ->body($body)
                    ->status($fiel['free'] === 0 ? 'warning' : 'success')
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Query for pool denominations that have not been sent to the SE yet.
     *
     * Pool names only (no registration): drafts awaiting review and names already
     * queued. Filtering on status alone is deliberate — soldado_id now records which
     * FIEL last attempted a name, so excluding non-null would skip exactly the ones
     * that failed once and need resending.
     *
     * @return Builder<LegalName>
     */
    private function pendingPoolQuery(): Builder
    {
        return LegalName::whereNull('registration_id')
            ->whereIn('status', [
                LegalNameStatusEnum::DRAFT->value,
                LegalNameStatusEnum::WAIT->value,
            ]);
    }
}
