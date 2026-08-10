<?php

namespace App\Filament\Resources\MisCitasResource\Pages;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Enums\DocumentTypeEnum;
use App\Filament\Resources\MisCitasResource;
use App\Models\Appointment;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Detail view of a single SAT appointment for the soldado who attends it.
 *
 * Shows the appointment data, the soldado's own data, and the two files they need on
 * the day: the acuse (once the SAT assigns the appointment) and the company's proof of
 * tax address. Plus the timeline, so they can see the bot has been working on it.
 */
class ViewMiCita extends ViewRecord
{
    protected static string $resource = MisCitasResource::class;

    /**
     * Timezone the SAT operates in — appointments are shown in CDMX time.
     */
    private const TIMEZONE = 'America/Mexico_City';

    /**
     * Build the detail layout.
     */
    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('La cita')
                ->columns(3)
                ->schema([
                    TextEntry::make('registration.primaryLegalName.name')
                        ->label('Empresa')->placeholder('—')->columnSpan(2),
                    TextEntry::make('type')->label('Trámite')
                        ->state(fn (Appointment $r): string => $r->type->label()),
                    TextEntry::make('status')->label('Estado')->badge()
                        ->state(fn (Appointment $r): string => $r->status->label())
                        ->color(fn (Appointment $r): string => $r->status->color()),
                    TextEntry::make('scheduled_at')->label('Fecha y hora')
                        // scheduled_at ya está en hora local de CDMX (acuse del SAT); no reconvertir.
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('El SAT aún no asigna fecha'),
                    TextEntry::make('office')->label('Sucursal del SAT')->placeholder('—'),
                ]),

            Section::make('Tus datos')
                ->columns(3)
                ->schema([
                    TextEntry::make('soldado.name')->label('Nombre')->placeholder('—'),
                    TextEntry::make('soldado.rfc')->label('RFC')->placeholder('—'),
                    TextEntry::make('soldado.curp')->label('CURP')->placeholder('—'),
                    TextEntry::make('soldado.email')->label('Correo')->placeholder('—'),
                    TextEntry::make('soldado.phone')->label('Teléfono')->placeholder('—'),
                ]),

            Section::make('Documentos')
                ->description('Lo que necesitas llevar a la cita.')
                ->columns(2)
                ->schema([
                    TextEntry::make('acuse')->hiddenLabel()
                        ->state(fn (Appointment $r): string => filled($r->acknowledgment_path)
                            ? 'Acuse de la cita disponible'
                            : 'El acuse llega cuando el SAT asigna la cita')
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
                        ->state(fn (Appointment $r): string => $this->proofDocument($r) !== null
                            ? 'Comprobante de domicilio fiscal disponible'
                            : 'Sin comprobante de domicilio fiscal')
                        ->badge()
                        ->color(fn (Appointment $r): string => $this->proofDocument($r) !== null ? 'success' : 'gray')
                        ->suffixAction(
                            Action::make('descargarComprobante')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->visible(fn (Appointment $r): bool => $this->proofDocument($r) !== null)
                                ->url(fn (Appointment $r): ?string => ($doc = $this->proofDocument($r)) !== null
                                    ? route('admin.documents.relay-download', ['document' => $doc])
                                    : null)
                                ->openUrlInNewTab(),
                        ),
                ]),

            Section::make('Historial')
                ->description('Lo que el bot ha hecho con tu cita.')
                ->poll('15s')
                ->schema([
                    ViewEntry::make('events')
                        ->hiddenLabel()
                        ->view('filament.infolists.appointment-timeline'),
                ]),
        ]);
    }

    /**
     * The company's Mexican proof-of-address document, if uploaded.
     *
     * @param  Appointment  $appointment  The appointment whose company is checked.
     */
    private function proofDocument(Appointment $appointment): ?Document
    {
        return $appointment->registration?->documents()
            ->where('type', DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value)
            ->latest()
            ->first();
    }
}
