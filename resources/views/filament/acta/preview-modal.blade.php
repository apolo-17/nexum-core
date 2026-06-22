{{-- Acta constitutiva draft preview modal --}}
{{-- Shown inside PrepareActaAction::modalContent() at ACTA_PREPARATION stage --}}
<div class="space-y-6 text-sm" style="padding: 0 4px;">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Sección: Empresa --}}
    {{-- ------------------------------------------------------------------ --}}
    <div style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
        <div style="background:#185FA5; color:#fff; padding:10px 16px; font-weight:600; font-size:13px;">
            Datos de la Empresa
        </div>
        <div style="padding:16px; display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Denominación</div>
                <div style="font-weight:600; color:#111827;">
                    {{ $data['autorizacion_denominacion'] ?: '⚠️ Sin denominación aprobada' }}
                </div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Tipo de sociedad</div>
                <div style="font-weight:600; color:#111827;">{{ $data['company_type'] ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Folio MUA (clave única)</div>
                <div style="font-weight:600; color:#111827;">
                    {{ $data['folio_denominacion'] ?: '⚠️ Sin folio MUA' }}
                </div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Capital social</div>
                <div style="font-weight:600; color:#111827;">
                    ${{ number_format($data['capital_social'] ?? 0, 2) }} MXN
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Objeto social</div>
                <div style="font-weight:500; color:#111827; white-space:pre-line;">
                    {{ $data['company_activity'] ?: '⚠️ Sin objeto social — actualizar en el expediente' }}
                </div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Domicilio social</div>
                <div style="font-weight:500; color:#111827;">{{ $data['domicilio_social'] ?? '—' }}</div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Sección: Comisario --}}
    {{-- ------------------------------------------------------------------ --}}
    <div style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
        <div style="background:#374151; color:#fff; padding:10px 16px; font-weight:600; font-size:13px;">
            Comisario
        </div>
        <div style="padding:16px; display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Nombre</div>
                <div style="font-weight:600; color:#111827;">{{ $data['comisario'] ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">RFC</div>
                <div style="font-weight:600; color:#111827; font-family:monospace;">{{ $data['comisario_rfc'] ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Extranjero</div>
                <div style="font-weight:500; color:#111827;">{{ $data['comisario_extranjero'] ? 'Sí' : 'No' }}</div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Sección: Socios (uno por socio) --}}
    {{-- ------------------------------------------------------------------ --}}
    @foreach ($data['socios'] as $i => $socio)
    <div style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
        <div style="background:#059669; color:#fff; padding:10px 16px; font-weight:600; font-size:13px; display:flex; justify-content:space-between; align-items:center;">
            <span>Socio {{ $i + 1 }} — {{ $socio['socio_nombre'] }}</span>
            @if ($socio['is_legal_representative'])
                <span style="font-size:11px; background:rgba(255,255,255,0.25); padding:2px 8px; border-radius:12px;">
                    Representante legal
                </span>
            @endif
        </div>
        <div style="padding:16px; display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">

            {{-- Identidad --}}
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Nacionalidad</div>
                <div style="font-weight:500; color:#111827;">{{ $socio['socio_nacionalidad'] ?: '⚠️ No disponible' }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Sexo</div>
                <div style="font-weight:500; color:#111827;">{{ $socio['socio_sexo'] === 'F' ? 'Femenino' : 'Masculino' }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Estado civil</div>
                <div style="font-weight:500; color:#111827;">{{ ucfirst($socio['socio_estado_civil'] ?? '—') }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Fecha de nacimiento</div>
                <div style="font-weight:500; color:#111827;">{{ $socio['socio_fecha_nacimiento'] ?: '⚠️ Sin fecha' }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Lugar de nacimiento</div>
                <div style="font-weight:500; color:#111827;">{{ $socio['socio_estado_nacimiento'] ?: '⚠️ No disponible' }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Ocupación</div>
                <div style="font-weight:500; color:#111827;">{{ ucfirst($socio['socio_ocupacion'] ?? '—') }}</div>
            </div>

            {{-- Documento de identidad --}}
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Tipo de identificación</div>
                <div style="font-weight:500; color:#111827;">{{ ucfirst($socio['socio_tipo_identificacion'] ?? '—') }}</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Número de identificación</div>
                <div style="font-weight:600; color:#111827; font-family:monospace;">
                    {{ $socio['socio_tipo_identificacion_numero'] ?: '⚠️ No extraído — revisar documentos KYC' }}
                </div>
            </div>

            {{-- Fiscal --}}
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">RFC (extranjero)</div>
                <div style="font-weight:600; color:#111827; font-family:monospace;">{{ $socio['socio_rfc'] ?? '—' }}</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">CURP (genérico extranjero)</div>
                <div style="font-weight:500; color:#111827; font-family:monospace;">{{ $socio['socio_curp'] ?? '—' }}</div>
            </div>

            {{-- Dirección y participación --}}
            <div style="grid-column:span 3;">
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Domicilio (del comprobante)</div>
                <div style="font-weight:500; color:#111827;">
                    {{ $socio['socio_direccion'] ?: '⚠️ No extraído — revisar comprobante de domicilio' }}
                </div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">País de residencia</div>
                <div style="font-weight:500; color:#111827;">{{ $socio['pais_residencia'] ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Participación</div>
                <div style="font-weight:600; color:#111827;">{{ $socio['socio_participacion'] }}%</div>
            </div>
            @if ($socio['socio_regimen_patrimonial'])
            <div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Régimen patrimonial</div>
                <div style="font-weight:500; color:#111827;">{{ ucfirst($socio['socio_regimen_patrimonial']) }}</div>
            </div>
            @endif
        </div>
    </div>
    @endforeach

    {{-- ------------------------------------------------------------------ --}}
    {{-- Nota de campos faltantes --}}
    {{-- ------------------------------------------------------------------ --}}
    <div style="background:#fffbeb; border:1px solid #f59e0b; border-radius:8px; padding:14px 16px; font-size:12px; color:#92400e;">
        <strong>⚠️ Campos marcados con ⚠️</strong> no están disponibles aún.<br>
        Puedes guardar el borrador y <strong>volver a generarlo</strong> después de:
        <ul style="margin:6px 0 0 16px; padding:0;">
            <li>Aprobar los documentos KYC pendientes (activa la extracción por IA).</li>
            <li>Corregir datos del socio en la pestaña <em>Accionistas</em>.</li>
            <li>Aprobar la denominación social en <em>Denominaciones</em>.</li>
        </ul>
    </div>

</div>
