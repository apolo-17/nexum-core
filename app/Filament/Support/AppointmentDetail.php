<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\AppointmentTypeEnum;
use App\Enums\DocumentTypeEnum;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\SatModule;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;

/**
 * One source of truth for the "SAT appointment detail" view.
 *
 * The same layout — appointment data, soldado, documents to download, and the timeline —
 * is shown in three places: the expedient's appointments table, the soldado's "Mis
 * Citas", and the super_admin dashboard. Defining it once here keeps them consistent.
 */
class AppointmentDetail
{
    /**
     * Timezone the SAT operates in — all appointment times are shown in CDMX.
     */
    public const TIMEZONE = 'America/Mexico_City';

    /**
     * The infolist sections for an appointment detail modal/page.
     *
     * @return array<int, Section>
     */
    public static function sections(): array
    {
        return [
            Section::make('La cita')
                ->columns(3)
                ->schema([
                    TextEntry::make('type')->label('Trámite')
                        ->state(fn (Appointment $r): string => $r->type->label()),
                    TextEntry::make('status')->label('Estado')->badge()
                        ->state(fn (Appointment $r): string => $r->status->label())
                        ->color(fn (Appointment $r): string => $r->status->color()),
                    TextEntry::make('scheduled_at')->label('Fecha asignada por el SAT')
                        // scheduled_at ya está en hora local de CDMX (acuse del SAT); no reconvertir.
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('El SAT aún no asigna fecha'),
                    TextEntry::make('office')->label('Sucursal')
                        ->state(fn (Appointment $r): string => self::officeName($r))
                        ->placeholder('—')->columnSpan(2),
                    TextEntry::make('email_alias')->label('Correo del pool')
                        ->placeholder('Sin asignar')
                        ->helperText('Ahí llega el código que el bot lee en cada revisión.'),
                    TextEntry::make('formed_at')->label('Formada')
                        ->dateTime('d/m/Y H:i', self::TIMEZONE)->placeholder('—'),
                    TextEntry::make('last_review_at')->label('Última revisión del bot')
                        ->dateTime('d/m/Y H:i', self::TIMEZONE)->placeholder('Sin revisar'),
                ]),

            Section::make('Soldado que asiste')
                ->columns(3)
                ->schema([
                    TextEntry::make('soldado.name')->label('Nombre')->placeholder('Sin asignar'),
                    TextEntry::make('soldado.rfc')->label('RFC')
                        ->placeholder('⚠️ Sin RFC')
                        ->helperText('El SAT identifica la cita de inscripción con este RFC.'),
                    TextEntry::make('soldado.curp')->label('CURP')->placeholder('—'),
                    TextEntry::make('soldado.email')->label('Correo')->placeholder('—')->copyable(),
                    TextEntry::make('soldado.phone')->label('Teléfono')->placeholder('—'),
                ]),

            Section::make('Documentación')
                ->description('Lo que se necesita para la cita.')
                ->columns(2)
                ->schema([
                    TextEntry::make('acuse')->hiddenLabel()
                        ->state(fn (Appointment $r): string => filled($r->acknowledgment_path)
                            ? 'Acuse de la cita'
                            : 'Acuse — llega cuando el SAT asigna la cita')
                        ->badge()
                        ->color(fn (Appointment $r): string => filled($r->acknowledgment_path) ? 'success' : 'gray')
                        ->suffixAction(
                            Action::make('descargarAcuse')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->visible(fn (Appointment $r): bool => filled($r->acknowledgment_path))
                                ->url(fn (Appointment $r): string => route(
                                    'admin.appointments.acknowledgment.download',
                                    ['appointment' => $r],
                                ))
                                ->openUrlInNewTab(),
                        ),

                    TextEntry::make('comprobante')->hiddenLabel()
                        ->state(fn (Appointment $r): string => self::proofDocument($r) !== null
                            ? 'Comprobante de domicilio fiscal'
                            : 'Sin comprobante de domicilio fiscal')
                        ->badge()
                        ->color(fn (Appointment $r): string => self::proofDocument($r) !== null ? 'success' : 'gray')
                        ->suffixAction(
                            Action::make('descargarComprobante')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->visible(fn (Appointment $r): bool => self::proofDocument($r) !== null)
                                ->url(fn (Appointment $r): ?string => ($doc = self::proofDocument($r)) !== null
                                    ? route('admin.documents.relay-download', ['document' => $doc])
                                    : null)
                                ->openUrlInNewTab(),
                        ),

                    // Relación de socios (.xlsx) — solo en la cita de RFC (inscripción PM).
                    // Un clic: genera con los datos del expediente y descarga (sin ZIP).
                    TextEntry::make('relacion')->hiddenLabel()
                        ->visible(fn (Appointment $r): bool => $r->type === AppointmentTypeEnum::RFC)
                        ->state(fn (Appointment $r): string => self::relationDocument($r) !== null
                            ? 'Relación de socios (.xlsx)'
                            : 'Relación de socios — genera y descarga →')
                        ->badge()
                        ->color(fn (Appointment $r): string => self::relationDocument($r) !== null ? 'success' : 'info')
                        ->suffixAction(
                            Action::make('generarDescargarRelacion')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->tooltip('Generar y descargar la relación de socios (.xlsx)')
                                ->visible(fn (Appointment $r): bool => (bool) $r->registration?->shareholders()->exists())
                                ->action(function (Appointment $r) {
                                    try {
                                        $document = resolve(\App\Services\Registration\SatShareholderRelationService::class)
                                            ->generate($r->registration);
                                    } catch (\Throwable $e) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Error al generar la relación de socios')
                                            ->body($e->getMessage())
                                            ->danger()
                                            ->send();

                                        return null;
                                    }

                                    // Descarga real (Content-Disposition: attachment).
                                    return redirect()->route('admin.documents.relay-download', ['document' => $document]);
                                }),
                        ),
                ]),

            Section::make('Historial')
                ->description('Cada paso: cuándo se pidió, se formó y el SAT asignó fecha.')
                ->poll('15s')
                ->schema([
                    ViewEntry::make('events')
                        ->hiddenLabel()
                        ->view('filament.infolists.appointment-timeline'),
                ]),
        ];
    }

    /**
     * Whether the appointment has its full documentation (acuse + tax-address proof).
     *
     * @param  Appointment  $appointment  The appointment to check.
     * @return array{complete: bool, label: string, color: string} A badge-ready summary.
     */
    public static function documentation(Appointment $appointment): array
    {
        $hasAcuse = filled($appointment->acknowledgment_path);
        $hasProof = self::proofDocument($appointment) !== null;

        if ($hasAcuse && $hasProof) {
            return ['complete' => true, 'label' => 'Completa', 'color' => 'success'];
        }

        $missing = [];
        if (! $hasAcuse) {
            $missing[] = 'acuse';
        }
        if (! $hasProof) {
            $missing[] = 'comprobante';
        }

        return ['complete' => false, 'label' => 'Falta '.implode(' y ', $missing), 'color' => 'warning'];
    }

    /**
     * Human-readable office name: the bot stores the SAT module id, not its name.
     *
     * @param  Appointment  $appointment  The appointment whose office is shown.
     */
    public static function officeName(Appointment $appointment): string
    {
        $office = (string) ($appointment->office ?? '');

        if ($office === '') {
            return '';
        }

        if (ctype_digit($office)) {
            return SatModule::where('sat_id', (int) $office)->value('name') ?? $office;
        }

        return $office;
    }

    /**
     * The company's Mexican proof-of-address document, if uploaded.
     *
     * @param  Appointment  $appointment  The appointment whose company is checked.
     */
    public static function proofDocument(Appointment $appointment): ?Document
    {
        return $appointment->registration?->documents()
            ->where('type', DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value)
            ->latest()
            ->first();
    }

    /**
     * The SAT shareholder relation (.xlsx) generated for this expedient, if any.
     *
     * @param  Appointment  $appointment  The appointment whose company is checked.
     */
    public static function relationDocument(Appointment $appointment): ?Document
    {
        return $appointment->registration?->documents()
            ->where('type', DocumentTypeEnum::SAT_SHAREHOLDER_RELATION->value)
            ->latest()
            ->first();
    }
}
