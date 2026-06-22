{{--
    Acta constitutiva rendered as a legal HTML document.
    Designed to be displayed in a Filament modal via ViewActaRenderAction
    and later used as the source for PDF generation before DocuSign.

    Variables:
      $data  array  Compiled template data from ActaPreparationService::compile()
--}}

@php
    use Carbon\Carbon;
    $hoy       = Carbon::now()->locale('es');
    $dia       = $hoy->day;
    $mes       = mb_strtolower($hoy->translatedFormat('F'));
    $año       = $hoy->year;

    $denominacion  = filled($data['autorizacion_denominacion']) ? $data['autorizacion_denominacion'] : '[DENOMINACIÓN PENDIENTE]';
    $folio         = $data['folio_denominacion']  ?? '';
    $fechaDenom    = $data['fecha_denominacion']   ?? '';
    $tipoSociedad  = strtoupper($data['company_type'] ?? 'SA DE CV');
    $objeto        = $data['company_activity']     ?? '';
    $capital       = (float)($data['capital_social'] ?? 50000);
    $capitalFmt    = '$'.number_format($capital, 2).' M.N.';
    $numAcciones   = (int)$capital;   // valor nominal $1.00 por acción
    $domicilio     = $data['domicilio_social']     ?? 'la Ciudad de México';
    $comisario     = $data['comisario']            ?? '';
    $comisarioRfc  = $data['comisario_rfc']        ?? '';
    $socios        = $data['socios']               ?? [];
    $repLegal      = collect($socios)->first(fn ($s) => $s['is_legal_representative']) ?? ($socios[0] ?? []);
@endphp

<div style="font-family: 'Times New Roman', Georgia, serif; font-size: 12px; line-height: 1.85; color: #111; padding: 48px 56px; max-width: 800px; margin: 0 auto; background: #fff;">

    {{-- ================================================================ --}}
    {{-- ENCABEZADO                                                        --}}
    {{-- ================================================================ --}}
    <div style="text-align:center; margin-bottom:36px; border-bottom:2px solid #1a1a1a; padding-bottom:22px;">
        <div style="font-size:10px; letter-spacing:3px; color:#666; text-transform:uppercase; margin-bottom:10px;">
            Acta Constitutiva de Sociedad Mercantil
        </div>
        <div style="font-size:20px; font-weight:bold; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;">
            {{ $denominacion }}
        </div>
        <div style="font-size:13px; font-weight:bold; color:#444; letter-spacing:1px;">
            {{ $tipoSociedad }}
        </div>
        @if($folio)
        <div style="font-size:10px; color:#666; margin-top:8px;">
            Folio MUA: <strong>{{ $folio }}</strong>
            @if($fechaDenom) — Autorizado el {{ $fechaDenom }} @endif
        </div>
        @endif
    </div>

    {{-- ================================================================ --}}
    {{-- LUGAR, FECHA Y APERTURA                                           --}}
    {{-- ================================================================ --}}
    <p style="text-align:justify; margin-bottom:18px;">
        En la Ciudad de México, a los <strong>{{ $dia }}</strong> días del mes de
        <strong>{{ $mes }}</strong> del año <strong>{{ $año }}</strong>,
        los comparecientes que al final suscriben el presente instrumento,
        todos mayores de edad y en pleno ejercicio de sus derechos civiles,
        acuerdan constituir una Sociedad Anónima de Capital Variable al tenor
        de la Ley General de Sociedades Mercantiles vigente, de conformidad
        con las siguientes:
    </p>

    {{-- ================================================================ --}}
    {{-- COMPARECEN                                                        --}}
    {{-- ================================================================ --}}
    <h3 style="text-align:center; font-size:12px; letter-spacing:4px; margin:28px 0 16px; border-top:1px solid #ccc; border-bottom:1px solid #ccc; padding:7px 0;">
        C O M P A R E C E N
    </h3>

    @foreach($socios as $i => $socio)
    @php
        $esFemenino = $socio['socio_sexo'] === 'F';
        $elLa = $esFemenino ? 'la' : 'el';
        $señor = $esFemenino ? 'la C.' : 'el C.';
        $casado = str_contains(strtolower($socio['socio_estado_civil'] ?? ''), 'casad');
    @endphp
    <p style="text-align:justify; margin-bottom:14px; padding-left:20px; border-left:3px solid #e0e0e0;">
        <strong>{{ $señor }} {{ $socio['socio_nombre'] }}</strong>,
        de nacionalidad <strong>{{ $socio['socio_nacionalidad'] }}</strong>,
        de estado civil <strong>{{ $socio['socio_estado_civil'] ?? '—' }}</strong>@if($casado && $socio['socio_regimen_patrimonial'])
        bajo el régimen de <strong>{{ $socio['socio_regimen_patrimonial'] }}</strong>@endif,
        de ocupación <strong>{{ $socio['socio_ocupacion'] ?? 'empresario' }}</strong>,
        @if(filled($socio['socio_fecha_nacimiento']))
        nacido {{ $esFemenino ? 'el' : 'el' }} <strong>{{ $socio['socio_fecha_nacimiento'] }}</strong>
        @endif
        @if(filled($socio['socio_estado_nacimiento']))
        en <strong>{{ $socio['socio_estado_nacimiento'] }}</strong>@endif,
        con domicilio en
        @if(filled($socio['socio_direccion']))
        <strong>{{ $socio['socio_direccion'] }}</strong>@else<span style="color:#c0392b;"><strong>[DOMICILIO PENDIENTE]</strong></span>@endif,
        identificado{{ $esFemenino ? 'a' : '' }} con
        <strong>{{ $socio['socio_tipo_identificacion'] ?? 'pasaporte' }}</strong>
        número
        @if(filled($socio['socio_tipo_identificacion_numero']))
        <strong style="font-family:monospace;">{{ $socio['socio_tipo_identificacion_numero'] }}</strong>@else<span style="color:#c0392b;"><strong>[NÚM. IDENTIFICACIÓN PENDIENTE]</strong></span>@endif,
        RFC <strong style="font-family:monospace;">{{ $socio['socio_rfc'] ?? '—' }}</strong>,
        CURP <strong style="font-family:monospace;">{{ $socio['socio_curp'] ?? '—' }}</strong>.
        @if($socio['is_legal_representative'])
        <em>(Representante Legal de la Sociedad)</em>
        @endif
    </p>
    @endforeach

    {{-- ================================================================ --}}
    {{-- BASES CONSTITUTIVAS                                               --}}
    {{-- ================================================================ --}}
    <h3 style="text-align:center; font-size:12px; letter-spacing:4px; margin:28px 0 20px; border-top:1px solid #ccc; border-bottom:1px solid #ccc; padding:7px 0;">
        B A S E S &nbsp; C O N S T I T U T I V A S
    </h3>

    {{-- PRIMERA — Denominación --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>PRIMERA.— DENOMINACIÓN.—</strong>
        La sociedad se constituye bajo la denominación de
        <strong>{{ $denominacion }}</strong>,
        la cual irá siempre acompañada de las palabras
        <em>"SOCIEDAD ANÓNIMA DE CAPITAL VARIABLE"</em> o de su abreviatura <em>"S.A. de C.V."</em>
        @if($folio)
        El uso de dicha denominación fue autorizado mediante folio MUA
        <strong>{{ $folio }}</strong>
        @if($fechaDenom) con fecha <strong>{{ $fechaDenom }}</strong>@endif
        por la Secretaría de Economía.
        @endif
    </p>

    {{-- SEGUNDA — Objeto --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>SEGUNDA.— OBJETO SOCIAL.—</strong>
        La sociedad tendrá como objeto principal:
        @if(filled($objeto))
        <em>{{ $objeto }}</em>.
        @else
        <span style="color:#c0392b;"><strong>[OBJETO SOCIAL PENDIENTE — completar en el expediente]</strong></span>.
        @endif
        Para la consecución de su objeto la sociedad podrá realizar toda clase de
        actos de comercio relacionados directa o indirectamente con el mismo, así como
        adquirir bienes muebles e inmuebles, contratar servicios, otorgar garantías y
        ejecutar cualquier acto jurídico necesario.
    </p>

    {{-- TERCERA — Domicilio --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>TERCERA.— DOMICILIO SOCIAL.—</strong>
        El domicilio social se establece en <strong>{{ $domicilio }}</strong>,
        sin que ello sea limitativo para que la sociedad establezca agencias, sucursales,
        oficinas o representaciones en cualquier lugar de la República Mexicana o del extranjero.
    </p>

    {{-- CUARTA — Duración --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>CUARTA.— DURACIÓN.—</strong>
        La duración de la sociedad será <strong>indefinida</strong>.
    </p>

    {{-- QUINTA — Capital social --}}
    <p style="text-align:justify; margin-bottom:10px;">
        <strong>QUINTA.— CAPITAL SOCIAL.—</strong>
        El capital social mínimo fijo sin derecho a retiro es de
        <strong>{{ $capitalFmt }}</strong>,
        dividido en <strong>{{ number_format($numAcciones) }} acciones</strong>
        nominativas comunes con valor nominal de <strong>$1.00 (UN PESO 00/100 M.N.)</strong>
        cada una, totalmente suscritas y pagadas en efectivo en la proporción siguiente:
    </p>

    {{-- Tabla de suscripción --}}
    <table style="width:100%; border-collapse:collapse; margin:12px 0 20px; font-size:11px;">
        <thead>
            <tr style="background:#f0f0f0;">
                <th style="border:1px solid #aaa; padding:7px 10px; text-align:left;">Nombre del Socio</th>
                <th style="border:1px solid #aaa; padding:7px 10px; text-align:center; white-space:nowrap;">Participación</th>
                <th style="border:1px solid #aaa; padding:7px 10px; text-align:center; white-space:nowrap;">Acciones suscritas</th>
                <th style="border:1px solid #aaa; padding:7px 10px; text-align:right; white-space:nowrap;">Aportación</th>
            </tr>
        </thead>
        <tbody>
            @foreach($socios as $socio)
            @php
                $aportacion = $capital * ($socio['socio_participacion'] / 100);
                $acciones   = (int)$aportacion;
            @endphp
            <tr>
                <td style="border:1px solid #aaa; padding:6px 10px;">{{ $socio['socio_nombre'] }}</td>
                <td style="border:1px solid #aaa; padding:6px 10px; text-align:center;">{{ number_format($socio['socio_participacion'], 2) }}%</td>
                <td style="border:1px solid #aaa; padding:6px 10px; text-align:center;">{{ number_format($acciones) }}</td>
                <td style="border:1px solid #aaa; padding:6px 10px; text-align:right;">${{ number_format($aportacion, 2) }}</td>
            </tr>
            @endforeach
            <tr style="font-weight:bold; background:#f8f8f8;">
                <td style="border:1px solid #aaa; padding:6px 10px;">T O T A L</td>
                <td style="border:1px solid #aaa; padding:6px 10px; text-align:center;">100.00%</td>
                <td style="border:1px solid #aaa; padding:6px 10px; text-align:center;">{{ number_format($numAcciones) }}</td>
                <td style="border:1px solid #aaa; padding:6px 10px; text-align:right;">{{ $capitalFmt }}</td>
            </tr>
        </tbody>
    </table>

    {{-- SEXTA — Administración --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>SEXTA.— ADMINISTRACIÓN.—</strong>
        La gestión y representación de la sociedad estará a cargo de un
        <strong>Administrador Único</strong> o de un Consejo de Administración,
        según determine la Asamblea General de Socios.
        El primer Administrador Único de la sociedad será
        @if($repLegal)
        <strong>{{ $repLegal['socio_nombre'] }}</strong>,
        RFC <strong style="font-family:monospace;">{{ $repLegal['socio_rfc'] ?? '—' }}</strong>,
        @else
        <span style="color:#c0392b;">[REPRESENTANTE LEGAL PENDIENTE]</span>,
        @endif
        quien contará con las más amplias facultades de dominio, administración y
        representación legal previstas en los artículos 2554 y 2555 del Código Civil
        Federal, así como poder para pleitos y cobranzas, actos de administración y
        actos de dominio.
    </p>

    {{-- SÉPTIMA — Vigilancia --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>SÉPTIMA.— VIGILANCIA.—</strong>
        La vigilancia de la sociedad estará a cargo de un <strong>Comisario</strong>.
        El primer Comisario designado es
        <strong>{{ $comisario }}</strong>,
        RFC <strong style="font-family:monospace;">{{ $comisarioRfc }}</strong>,
        quien ejercerá el cargo con las facultades y obligaciones que establecen
        los artículos 164 al 171 de la Ley General de Sociedades Mercantiles.
    </p>

    {{-- OCTAVA — Utilidades --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>OCTAVA.— DISTRIBUCIÓN DE UTILIDADES.—</strong>
        Las utilidades netas anuales se distribuirán entre los socios en proporción
        al número de acciones que cada uno posea, previa la separación del cinco por
        ciento para constituir o reconstituir el fondo de reserva legal hasta que
        éste represente la quinta parte del capital social, conforme al artículo
        20 de la Ley General de Sociedades Mercantiles.
    </p>

    {{-- NOVENA — Disolución --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>NOVENA.— DISOLUCIÓN Y LIQUIDACIÓN.—</strong>
        La sociedad se disolverá en los casos previstos por los artículos 229 y 230
        de la Ley General de Sociedades Mercantiles. Una vez disuelta, la liquidación
        se llevará a cabo conforme al Capítulo XI de dicha Ley, y los liquidadores
        designados por la Asamblea General de Socios procederán a concluir las
        operaciones sociales pendientes.
    </p>

    {{-- DÉCIMA — Asamblea --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>DÉCIMA.— ASAMBLEA GENERAL DE SOCIOS.—</strong>
        La Asamblea General de Socios es el órgano supremo de la sociedad.
        Se reunirá ordinariamente al menos una vez al año dentro de los cuatro
        meses siguientes al cierre del ejercicio social para aprobar el balance
        general, el estado de resultados y el informe del Administrador Único.
        Las asambleas extraordinarias se convocarán cuando los asuntos a tratar
        así lo requieran.
    </p>

    {{-- DÉCIMA PRIMERA — Legislación --}}
    <p style="text-align:justify; margin-bottom:16px;">
        <strong>DÉCIMA PRIMERA.— LEGISLACIÓN APLICABLE.—</strong>
        Para todo lo no previsto en el presente instrumento, las partes se sujetan
        a las disposiciones de la Ley General de Sociedades Mercantiles, el Código
        de Comercio, el Código Civil Federal y demás disposiciones aplicables
        vigentes en los Estados Unidos Mexicanos.
    </p>

    {{-- Cierre --}}
    <p style="text-align:justify; margin-bottom:32px;">
        Habiendo leído el presente instrumento y estando conformes con su contenido
        y alcance legal, los comparecientes lo firman en la Ciudad de México,
        a los <strong>{{ $dia }}</strong> días del mes de <strong>{{ $mes }}</strong>
        del año <strong>{{ $año }}</strong>.
    </p>

    {{-- ================================================================ --}}
    {{-- FIRMAS                                                            --}}
    {{-- ================================================================ --}}
    <div style="margin-top:50px; display:grid; grid-template-columns:repeat({{ min(count($socios), 2) }}, 1fr); gap:40px 60px;">
        @foreach($socios as $socio)
        <div style="text-align:center;">
            <div style="height:56px; border-bottom:1px solid #333; margin-bottom:10px;"></div>
            <div style="font-weight:bold; font-size:11px; text-transform:uppercase; letter-spacing:.5px;">
                {{ $socio['socio_nombre'] }}
            </div>
            <div style="font-size:10px; color:#555; margin-top:3px; font-family:monospace;">
                RFC: {{ $socio['socio_rfc'] ?? '—' }}
            </div>
            <div style="font-size:10px; color:#777; margin-top:2px;">
                {{ number_format($socio['socio_participacion'], 2) }}% del capital social
                @if($socio['is_legal_representative'])
                <br><em>(Representante Legal)</em>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    {{-- Comisario --}}
    <div style="margin-top:48px; display:flex; justify-content:center;">
        <div style="text-align:center; width:280px;">
            <div style="height:56px; border-bottom:1px solid #333; margin-bottom:10px;"></div>
            <div style="font-weight:bold; font-size:11px; text-transform:uppercase; letter-spacing:.5px;">
                {{ $comisario }}
            </div>
            <div style="font-size:10px; color:#555; margin-top:3px; font-family:monospace;">
                RFC: {{ $comisarioRfc }}
            </div>
            <div style="font-size:10px; color:#777; margin-top:2px;">
                <em>Comisario</em>
            </div>
        </div>
    </div>

    {{-- Pie --}}
    <div style="margin-top:48px; border-top:1px solid #ddd; padding-top:12px; text-align:center; font-size:10px; color:#888;">
        Borrador generado automáticamente por Nexum Core —
        {{ \Carbon\Carbon::parse($data['compiled_at'] ?? now())->format('d/m/Y H:i') }} —
        Expediente {{ $data['singapur_client_code'] ?? '' }}
    </div>

</div>
