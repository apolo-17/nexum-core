{{-- Acta constitutiva draft preview modal --}}
{{-- Shown inside PrepareActaAction::modalContent() at ACTA_PREPARATION stage --}}
{{--
    Variables:
      $data  array  Compiled template data from ActaPreparationService::compile()
--}}

<div class="text-sm" style="padding: 2px 2px 0; display: flex; flex-direction: column; gap: 16px;">

    {{-- ================================================================== --}}
    {{-- Empresa                                                             --}}
    {{-- ================================================================== --}}
    <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06);">
        <div style="background:#185FA5; color:#fff; padding:11px 18px; font-weight:700; font-size:13px; letter-spacing:.01em;">
            Datos de la Empresa
        </div>
        <div style="padding:18px; display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Denominación social</div>
                <div style="font-weight:700; color:#111827; font-size:14px;">
                    {{ $data['autorizacion_denominacion'] ?: '⚠️ Sin denominación aprobada' }}
                </div>
            </div>
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Tipo de sociedad</div>
                <div style="font-weight:600; color:#111827;">{{ $data['company_type'] ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Folio MUA</div>
                <div style="font-weight:600; color:#111827; font-family:monospace;">
                    {{ $data['folio_denominacion'] ?: '⚠️ Sin folio MUA' }}
                </div>
            </div>
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Capital social</div>
                <div style="font-weight:600; color:#111827;">${{ number_format($data['capital_social'] ?? 0, 2) }} MXN</div>
            </div>
            <div style="grid-column:1/-1;">
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Objeto social</div>
                <div style="font-weight:500; color:#111827; white-space:pre-line; line-height:1.5;">
                    {{ $data['company_activity'] ?: '⚠️ Sin objeto social — actualizar en el expediente' }}
                </div>
            </div>
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Domicilio social</div>
                <div style="font-weight:500; color:#111827;">{{ $data['domicilio_social'] ?? '—' }}</div>
            </div>
        </div>
    </div>

    {{-- ================================================================== --}}
    {{-- Comisario                                                           --}}
    {{-- ================================================================== --}}
    <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06);">
        <div style="background:#374151; color:#fff; padding:11px 18px; font-weight:700; font-size:13px;">
            Comisario
        </div>
        <div style="padding:18px; display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px;">
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Nombre</div>
                <div style="font-weight:600; color:#111827;">{{ $data['comisario'] ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">RFC</div>
                <div style="font-weight:700; color:#111827; font-family:monospace;">{{ $data['comisario_rfc'] ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;">Extranjero</div>
                <div style="font-weight:500; color:#111827;">{{ $data['comisario_extranjero'] ? 'Sí' : 'No' }}</div>
            </div>
        </div>
    </div>

    {{-- ================================================================== --}}
    {{-- Socios — acordeón                                                  --}}
    {{-- ================================================================== --}}
    <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06);">

        {{-- Header de la sección --}}
        <div style="background:#059669; color:#fff; padding:11px 18px; font-weight:700; font-size:13px;">
            Socios
            <span style="font-weight:400; opacity:.8; font-size:12px; margin-left:6px;">
                — {{ count($data['socios']) }} {{ count($data['socios']) === 1 ? 'socio' : 'socios' }}
            </span>
        </div>

        {{-- Lista de socios como acordeón --}}
        <div style="padding: 14px; display: flex; flex-direction: column; gap: 10px; background:#f9fafb;">

            @foreach ($data['socios'] as $i => $socio)

            {{-- Detectar si hay campos incompletos en este socio --}}
            @php
                $missingFields = [];
                if (! $socio['socio_tipo_identificacion_numero']) { $missingFields[] = 'N.° identificación'; }
                if (! $socio['socio_fecha_nacimiento'])           { $missingFields[] = 'Fecha de nacimiento'; }
                if (! $socio['socio_estado_nacimiento'])          { $missingFields[] = 'Lugar de nacimiento'; }
                if (! $socio['socio_direccion'])                  { $missingFields[] = 'Domicilio'; }
                $hasMissing = count($missingFields) > 0;
            @endphp

            <div
                x-data="{ open: false }"
                style="border:1px solid {{ $hasMissing ? '#fde68a' : '#d1fae5' }}; border-radius:8px; overflow:hidden;"
            >
                {{-- Header del socio (clickeable) --}}
                <button
                    type="button"
                    @click="open = !open"
                    style="width:100%; text-align:left; padding:11px 16px; background:{{ $hasMissing ? '#fffbeb' : '#ecfdf5' }}; display:flex; justify-content:space-between; align-items:center; cursor:pointer; border:none; font-size:13px; outline:none;"
                >
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span style="font-weight:700; color:{{ $hasMissing ? '#92400e' : '#065f46' }};">
                            Socio {{ $i + 1 }} — {{ $socio['socio_nombre'] }}
                        </span>
                        @if ($socio['is_legal_representative'])
                            <span style="font-size:10px; background:{{ $hasMissing ? '#d97706' : '#059669' }}; color:#fff; padding:2px 9px; border-radius:99px; font-weight:600;">
                                Representante legal
                            </span>
                        @endif
                        @if ($hasMissing)
                            <span style="font-size:10px; background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding:2px 8px; border-radius:99px; font-weight:600;">
                                ⚠️ {{ count($missingFields) }} campo{{ count($missingFields) > 1 ? 's' : '' }} faltante{{ count($missingFields) > 1 ? 's' : '' }}
                            </span>
                        @endif
                    </div>
                    {{-- Unicode chevron — avoids SVG sizing issues caused by Tailwind/Filament global CSS --}}
                    <span
                        :style="open ? 'transform:rotate(180deg)' : 'transform:rotate(0deg)'"
                        style="display:inline-block; flex-shrink:0; font-size:16px; line-height:1; color:{{ $hasMissing ? '#d97706' : '#059669' }}; transition:transform .2s ease; user-select:none; margin-left:8px;"
                    >▾</span>
                </button>

                {{-- Cuerpo expandible --}}
                <div
                    x-show="open"
                    x-transition
                    style="padding:16px; display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; background:white; border-top:1px solid {{ $hasMissing ? '#fde68a' : '#d1fae5' }};"
                >

                    {{-- Identidad --}}
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Nacionalidad</div>
                        <div style="font-weight:500; color:#111827;">{{ $socio['socio_nacionalidad'] ?: '⚠️ No disponible' }}</div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Sexo</div>
                        <div style="font-weight:500; color:#111827;">{{ $socio['socio_sexo'] === 'F' ? 'Femenino' : 'Masculino' }}</div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Estado civil</div>
                        <div style="font-weight:500; color:#111827;">{{ ucfirst($socio['socio_estado_civil'] ?? '—') }}</div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Fecha de nacimiento</div>
                        <div style="font-weight:500; color:{{ $socio['socio_fecha_nacimiento'] ? '#111827' : '#b45309' }};">
                            {{ $socio['socio_fecha_nacimiento'] ?: '⚠️ Sin fecha' }}
                        </div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Lugar de nacimiento</div>
                        <div style="font-weight:500; color:{{ $socio['socio_estado_nacimiento'] ? '#111827' : '#b45309' }};">
                            {{ $socio['socio_estado_nacimiento'] ?: '⚠️ No disponible' }}
                        </div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Ocupación</div>
                        <div style="font-weight:500; color:#111827;">{{ ucfirst($socio['socio_ocupacion'] ?? '—') }}</div>
                    </div>

                    {{-- Separador --}}
                    <div style="grid-column:1/-1; height:1px; background:#f3f4f6; margin:2px 0;"></div>

                    {{-- Identificación --}}
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Tipo de identificación</div>
                        <div style="font-weight:500; color:#111827;">{{ ucfirst($socio['socio_tipo_identificacion'] ?? '—') }}</div>
                    </div>
                    <div style="grid-column:span 2;">
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Número de identificación</div>
                        <div style="font-weight:700; color:{{ $socio['socio_tipo_identificacion_numero'] ? '#111827' : '#b45309' }}; font-family:monospace; font-size:13px;">
                            {{ $socio['socio_tipo_identificacion_numero'] ?: '⚠️ No extraído — revisar documentos KYC' }}
                        </div>
                    </div>

                    {{-- Separador --}}
                    <div style="grid-column:1/-1; height:1px; background:#f3f4f6; margin:2px 0;"></div>

                    {{-- Fiscal --}}
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">RFC (extranjero)</div>
                        <div style="font-weight:700; color:#111827; font-family:monospace; font-size:12px;">{{ $socio['socio_rfc'] ?? '—' }}</div>
                    </div>
                    <div style="grid-column:span 2;">
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">CURP (genérico extranjero)</div>
                        <div style="font-weight:500; color:#111827; font-family:monospace; font-size:12px;">{{ $socio['socio_curp'] ?? '—' }}</div>
                    </div>

                    {{-- Separador --}}
                    <div style="grid-column:1/-1; height:1px; background:#f3f4f6; margin:2px 0;"></div>

                    {{-- Domicilio --}}
                    <div style="grid-column:span 3;">
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">
                            Domicilio — del comprobante de domicilio del socio
                        </div>
                        <div style="font-weight:500; color:{{ $socio['socio_direccion'] ? '#111827' : '#b45309' }};">
                            {{ $socio['socio_direccion'] ?: '⚠️ No extraído — el comprobante de este socio aún no fue aprobado o analizado' }}
                        </div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">País de residencia</div>
                        <div style="font-weight:500; color:#111827;">{{ $socio['pais_residencia'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Participación</div>
                        <div style="font-weight:700; color:#111827; font-size:14px;">{{ $socio['socio_participacion'] }}%</div>
                    </div>
                    @if ($socio['socio_regimen_patrimonial'])
                    <div>
                        <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px;">Régimen patrimonial</div>
                        <div style="font-weight:500; color:#111827;">{{ ucfirst($socio['socio_regimen_patrimonial']) }}</div>
                    </div>
                    @endif

                </div>
            </div>

            @endforeach

        </div>
    </div>

    {{-- ================================================================== --}}
    {{-- Nota de campos faltantes — solo si hay algo incompleto             --}}
    {{-- ================================================================== --}}
    @php
        $totalMissing = collect($data['socios'])->filter(fn ($s) =>
            ! $s['socio_tipo_identificacion_numero'] ||
            ! $s['socio_fecha_nacimiento'] ||
            ! $s['socio_estado_nacimiento'] ||
            ! $s['socio_direccion']
        )->count();
        $missingDenominacion = ! $data['autorizacion_denominacion'];
        $missingObjeto = ! $data['company_activity'];
        $hasAnyIssue = $totalMissing > 0 || $missingDenominacion || $missingObjeto;
    @endphp

    @if ($hasAnyIssue)
    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px 18px; font-size:12px; color:#92400e; line-height:1.6;">
        <div style="font-weight:700; margin-bottom:6px;">⚠️ Campos incompletos en este borrador</div>
        <div style="color:#78350f;">Puedes guardar y <strong>regenerar el borrador</strong> después de completar:</div>
        <div style="margin-top:6px; display:flex; flex-direction:column; gap:3px;">
            @if ($missingDenominacion)
                <div>· Aprobar una <strong>denominación social</strong> en la pestaña <em>Denominaciones</em>.</div>
            @endif
            @if ($missingObjeto)
                <div>· Llenar el <strong>objeto social</strong> en los datos del expediente.</div>
            @endif
            @if ($totalMissing > 0)
                <div>· Aprobar los <strong>documentos KYC</strong> de {{ $totalMissing }} socio(s) — se activa la extracción automática por IA.</div>
                <div style="margin-left:12px; font-size:11px; color:#92400e;">↳ Campos que faltan: número de identificación, fecha/lugar de nacimiento, domicilio del comprobante.</div>
            @endif
        </div>
    </div>
    @endif

</div>
