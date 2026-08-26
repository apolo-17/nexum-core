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
                // Every name we already hold, in ANY state and whether or not it is tied
                // to an expedient. Checking only the pool is what let GUANG HUA COMERCIAL
                // through: it lived on a registration, so the pool query never saw it.
                $existing = LegalName::pluck('name')->all();

                try {
                    $names = app(DenominationGeneratorService::class)
                        ->generate((int) $data['quantity'], $existing);
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
                $repeated = [];

                // Second line of defence, normalised: the model is told what to avoid,
                // but a near-miss on casing/accents/spacing is still the same name to
                // the SE, and requesting one it already granted us is refused outright.
                $taken = collect($existing)
                    ->mapWithKeys(fn (string $n): array => [$this->normalizeName($n) => true])
                    ->all();

                foreach ($names as $name) {
                    if (isset($taken[$this->normalizeName($name)])) {
                        $repeated[] = $name;

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

                $body = 'FIEL sugerida al enviar: '.($suggestedFiel?->name ?? 'ninguna disponible').'.';

                if ($repeated !== []) {
                    $body .= ' Se descartaron '.count($repeated).' por repetir denominaciones que ya tenemos: '
                        .implode(', ', $repeated).'.';
                }

                Notification::make()
                    ->title("Se generaron {$created} denominaciones (borrador).")
                    ->body($body)
                    ->status($repeated !== [] ? 'warning' : 'success')
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

                // Nobody free: do NOT queue. The SE allows one in-process denomination
                // per RFC, so every job would just defer and the operator would be told
                // "encolado" for work that cannot start. Say so plainly instead.
                if ($fiel['free'] === 0) {
                    $enProceso = LegalName::whereIn('status', [
                        LegalNameStatusEnum::SUBMITTING->value,
                        LegalNameStatusEnum::PENDING->value,
                        LegalNameStatusEnum::PROCESS->value,
                    ])->count();

                    Notification::make()
                        ->title('No hay soldados disponibles — no se envió nada.')
                        ->body("Los {$fiel['ready']} soldados con FIEL están ocupados: la SE permite una "
                            .'denominación en proceso por RFC. '
                            ."Se liberará uno en cuanto la SE resuelva alguna de las {$enProceso} en dictamen. "
                            .'Las denominaciones siguen en cola; vuelve a intentar entonces.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

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

                if ($queued > $fiel['free']) {
                    $body .= " Solo {$willSend} saldrán ahora (una por soldado); las demás esperan turno.";
                }

                Notification::make()
                    ->title('Envío encolado.')
                    ->body($body)
                    ->status($queued > $fiel['free'] ? 'warning' : 'success')
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Reduce a denomination to a form where near-misses compare equal.
     *
     * The SE treats names differing only in casing, accents or spacing as the same
     * one, so comparing raw strings would let "Guang Hua Comercial" slip past an
     * existing "GUANG HUA COMERCIAL".
     *
     * @param  string  $name  Raw denomination.
     * @return string Upper-cased, unaccented, single-spaced form.
     */
    private function normalizeName(string $name): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name);

        return trim((string) preg_replace('/\s+/', ' ', mb_strtoupper($ascii)));
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
