@php
    /** @var \App\Models\Registration $record */
    $record = $getRecord();
    $ex = $record->acta_extraction ?? null;
    $rec = $ex['reconciliation'] ?? null;
@endphp

<div style="font-size:13px;">
    @if(! $ex)
        <div style="color:#6b7280;">
            Aún no se ha leído el acta con IA. Usa el botón <strong>“Extraer socios del acta (IA)”</strong> arriba.
        </div>
    @elseif(! ($ex['ok'] ?? false))
        <div style="color:#b91c1c;">
            ⚠️ El documento no se validó como acta protocolizada.
            @if($ex['reason_if_not'] ?? null) <div style="margin-top:4px;">Motivo: {{ $ex['reason_if_not'] }}</div> @endif
        </div>
    @else
        <div style="color:#6b7280;margin-bottom:10px;">
            Leído el {{ \Carbon\Carbon::parse($ex['extracted_at'])->timezone('America/Mexico_City')->format('d/m/Y H:i') }}
            @if($ex['denominacion'] ?? null) · <strong>{{ $ex['denominacion'] }}</strong> @endif
            @if(($ex['escritura']['numero'] ?? null)) · Esc. {{ $ex['escritura']['numero'] }} @endif
        </div>

        <div style="font-weight:600;margin-bottom:4px;">Apoderados fiscales (para citas del SAT)</div>
        <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:12px;">
            @forelse(($rec['apoderados_matched'] ?? []) as $a)
                <div style="display:flex;gap:8px;align-items:center;">
                    <span style="color:#16a34a;">✅</span>
                    <span style="font-weight:500;">{{ $a['nombre'] ?: '(sin nombre en acta)' }}</span>
                    <span style="color:#6b7280;">RFC {{ $a['rfc'] }} · ligado al expediente</span>
                    @unless($a['name_matches']) <span style="color:#ca8a04;font-size:12px;">(el nombre no coincide del todo con el soldado — verificar)</span> @endunless
                </div>
            @empty
                <div style="color:#6b7280;">Ningún apoderado del acta coincidió con un soldado del sistema.</div>
            @endforelse

            @foreach(($rec['apoderados_not_found'] ?? []) as $a)
                <div style="display:flex;gap:8px;align-items:center;">
                    <span style="color:#dc2626;">❗</span>
                    <span style="font-weight:500;">{{ $a['nombre'] ?: '(sin nombre)' }}</span>
                    <span style="color:#b91c1c;">RFC {{ $a['rfc'] ?: 'sin RFC legible' }} — no está en el sistema (darlo de alta como soldado)</span>
                </div>
            @endforeach
        </div>

        <div style="font-weight:600;margin-bottom:4px;">Socios / gerentes (cotejo por nombre)</div>
        <div style="display:flex;flex-direction:column;gap:4px;">
            @forelse(($rec['socios_check'] ?? []) as $s)
                <div style="display:flex;gap:8px;align-items:center;">
                    <span>{{ $s['en_sistema'] ? '✅' : '❔' }}</span>
                    <span>{{ $s['nombre'] }}</span>
                    <span style="color:#6b7280;">{{ $s['en_sistema'] ? 'coincide con un socio del expediente' : 'no encontrado entre los socios del expediente' }}</span>
                </div>
            @empty
                <div style="color:#6b7280;">No se extrajeron socios.</div>
            @endforelse
        </div>
    @endif
</div>
