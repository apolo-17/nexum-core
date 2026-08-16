{{-- SAT "relación de socios" preview modal --}}
{{-- Shown inside GenerateSatRelationAction::modalContent() before generating the .xlsx --}}
{{--
    Variables:
      $relation  array  Output of SatShareholderRelationService::compile():
                        razon_social, denominacion, company_type,
                        capital_social, total_partes, rows[]
--}}

@php
    $rows = $relation['rows'] ?? [];
    $missingDenominacion = empty($relation['denominacion']);
    $missingNombre = collect($rows)->contains(fn ($r) => empty($r['nombre']));
    $sinSocios = count($rows) === 0;
    $hasIssue = $missingDenominacion || $missingNombre || $sinSocios;
@endphp

<div class="text-sm" style="padding:2px 2px 0; display:flex; flex-direction:column; gap:16px;">

    {{-- ================================================================== --}}
    {{-- Empresa                                                             --}}
    {{-- ================================================================== --}}
    <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06);">
        <div style="background:#611232; color:#fff; padding:11px 18px; font-weight:700; font-size:13px; letter-spacing:.01em;">
            Relación de socios para el SAT — Inscripción de persona moral
        </div>
        <div style="padding:18px; display:grid; grid-template-columns:2fr 1fr 1fr; gap:16px;">
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Razón social</div>
                <div style="font-weight:700; color:#111827; font-size:14px;">
                    {{ $relation['razon_social'] ?: '⚠️ Sin denominación aprobada' }}
                </div>
            </div>
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Capital social</div>
                <div style="font-weight:600; color:#111827;">${{ number_format($relation['capital_social'] ?? 0) }} MXN</div>
            </div>
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Total partes sociales</div>
                <div style="font-weight:600; color:#111827;">{{ number_format($relation['total_partes'] ?? 0) }}</div>
            </div>
        </div>
    </div>

    {{-- ================================================================== --}}
    {{-- Tabla de socios                                                     --}}
    {{-- ================================================================== --}}
    <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06);">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12.5px;">
                <thead>
                    <tr style="background:#611232; color:#fff; text-align:left;">
                        <th style="padding:9px 12px; font-weight:700;">No.</th>
                        <th style="padding:9px 12px; font-weight:700;">Nombre completo</th>
                        <th style="padding:9px 12px; font-weight:700;">RFC</th>
                        <th style="padding:9px 12px; font-weight:700;">CURP</th>
                        <th style="padding:9px 12px; font-weight:700;">Carácter</th>
                        <th style="padding:9px 12px; font-weight:700; text-align:right;">Partes sociales</th>
                        <th style="padding:9px 12px; font-weight:700; text-align:right;">%</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr style="border-top:1px solid #f0f0f0;">
                            <td style="padding:9px 12px; text-align:center; color:#111827;">{{ $r['no'] }}</td>
                            <td style="padding:9px 12px; font-weight:600; color:{{ $r['nombre'] ? '#111827' : '#b45309' }};">
                                {{ $r['nombre'] ?: '⚠️ Sin nombre — revisar socio' }}
                            </td>
                            <td style="padding:9px 12px; font-family:monospace; color:#111827;">{{ $r['rfc'] }}</td>
                            <td style="padding:9px 12px; font-family:monospace; color:#111827;">{{ $r['curp'] ?: '—' }}</td>
                            <td style="padding:9px 12px; color:#111827;">{{ $r['cargo'] }}</td>
                            <td style="padding:9px 12px; text-align:right; color:#111827;">{{ number_format($r['partes']) }}</td>
                            <td style="padding:9px 12px; text-align:right; color:#111827;">{{ rtrim(rtrim(number_format($r['porcentaje'], 2), '0'), '.') }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding:16px; text-align:center; color:#b45309;">
                                ⚠️ Este expediente no tiene socios registrados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows)
                    <tfoot>
                        <tr style="border-top:2px solid #611232; background:#faf5f7; font-weight:700;">
                            <td colspan="5" style="padding:9px 12px; text-align:right; color:#611232;">Total</td>
                            <td style="padding:9px 12px; text-align:right; color:#611232;">{{ number_format(collect($rows)->sum('partes')) }}</td>
                            <td style="padding:9px 12px; text-align:right; color:#611232;">{{ rtrim(rtrim(number_format(collect($rows)->sum('porcentaje'), 2), '0'), '.') }}%</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- ================================================================== --}}
    {{-- Nota RFC genérico                                                   --}}
    {{-- ================================================================== --}}
    <div style="font-size:11px; color:#6b7280; line-height:1.5;">
        Los socios extranjeros usan el RFC genérico de persona física
        <strong style="font-family:monospace;">EXTF900101NI1</strong> y el CURP genérico de extranjero,
        conforme al requisito 6 del acuse del SAT (Anexo 2 RMF).
    </div>

    {{-- ================================================================== --}}
    {{-- Avisos de datos incompletos                                         --}}
    {{-- ================================================================== --}}
    @if ($hasIssue)
        <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px 18px; font-size:12px; color:#92400e; line-height:1.6;">
            <div style="font-weight:700; margin-bottom:6px;">⚠️ Revisa antes de generar</div>
            <div style="display:flex; flex-direction:column; gap:3px; color:#78350f;">
                @if ($missingDenominacion)
                    <div>· Falta la <strong>denominación aprobada</strong> — la razón social saldrá vacía. Apruébala en la pestaña <em>Denominaciones</em>.</div>
                @endif
                @if ($missingNombre)
                    <div>· Uno o más socios <strong>sin nombre</strong> — verifica que se haya extraído del pasaporte o captúralo en <em>Socios</em>.</div>
                @endif
                @if ($sinSocios)
                    <div>· El expediente <strong>no tiene socios</strong> registrados.</div>
                @endif
            </div>
        </div>
    @endif

</div>
