<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Enums\DocumentTypeEnum;
use App\Filament\Resources\RegistrationResource;
use App\Models\Registration;
use Filament\Actions\Action;

/**
 * Header action that navigates to the inline acta editor page.
 *
 * This is the primary acta CTA in the ViewRegistration header (Propuesta B).
 * It opens EditActaInlinePage where the notary can review, edit, and download
 * the acta constitutiva — all in one place, without extra modal steps.
 *
 * Replaces the former trio of ViewActaRenderAction, EditActaDraftAction, and
 * GenerateActaDocxAction which have been removed from the header to keep the
 * interface clean and lawyer-friendly.
 *
 * Visibility: requires an ACTA_DRAFT with compiled template_data to exist.
 */
class EditActaInlineAction
{
    /**
     * Build the Filament Action instance for the ViewRegistration header.
     *
     * @param  Registration  $registration  The expedient being viewed.
     */
    public static function make(Registration $registration): Action
    {
        return Action::make('editActaInline')
            ->label('Revisar acta')
            ->color('primary')
            ->icon('heroicon-o-document-text')
            // Navigate to the dedicated editor page — no modal, the editor is full-width.
            ->url(
                fn (): string => RegistrationResource::getUrl(
                    'edit-acta-inline',
                    ['record' => $registration],
                )
            )
            // Visible whenever a compiled ACTA_DRAFT exists for this expedient.
            ->visible(
                fn (): bool => $registration->documents()
                    ->where('type', DocumentTypeEnum::ACTA_DRAFT)
                    ->whereNotNull('template_data')
                    ->exists()
            );
    }
}
