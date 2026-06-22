<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Models\Registration;
use App\Services\Registration\StageTransitionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Filament header action to advance a registration to the next stage.
 *
 * Displayed only when the registration is eligible for advancement.
 * Opens a modal with an optional reason field before committing the transition.
 */
class AdvanceStageAction
{
    /**
     * Build and return the configured Filament Action instance.
     *
     * @param  Registration  $registration  The current registration record.
     * @param  Authenticatable  $performedBy  The authenticated dashboard user.
     * @param  StageTransitionService  $stageTransitionService  Injected service handling the transition.
     */
    public static function make(
        Registration $registration,
        Authenticatable $performedBy,
        StageTransitionService $stageTransitionService,
    ): Action {
        return Action::make('advance_stage')
            ->label(
                fn (): string => '✓ Confirmar: '.($stageTransitionService->nextStage($registration->stage)?->label() ?? 'completado')
            )
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => $stageTransitionService->canAdvance($registration))
            ->modalHeading(fn (): string => 'Confirmar etapa: '.($stageTransitionService->nextStage($registration->stage)?->label() ?? ''))
            ->modalDescription(
                fn (): string => sprintf(
                    'La etapa **%s** quedará marcada como completada y el expediente avanzará a **%s**.',
                    $registration->stage->label(),
                    $stageTransitionService->nextStage($registration->stage)?->label() ?? '—',
                )
            )
            ->modalSubmitActionLabel('Confirmar ✓')
            ->modalIcon('heroicon-o-arrow-right-circle')
            ->form([
                Textarea::make('reason')
                    ->label('Motivo u observaciones')
                    ->placeholder('Opcional — se guarda en el historial de transiciones.')
                    ->rows(3),
            ])
            ->action(function (array $data) use ($registration, $performedBy, $stageTransitionService): void {
                $reason = filled($data['reason']) ? $data['reason'] : null;

                $stageTransitionService->advance($registration, $performedBy, $reason);

                $registration->refresh();

                Notification::make()
                    ->success()
                    ->title('Etapa avanzada')
                    ->body(
                        sprintf(
                            'Expediente %s movido a: %s.',
                            $registration->singapur_client_code,
                            $registration->stage->label(),
                        )
                    )
                    ->send();
            });
    }
}
