{{--
    Acta constitutiva rendered as a legal HTML document.
    Mirrors the exact content of storage/docs/sa.docx — same 39 articles,
    same transitory articles, same GENERALES section.

    Variables:
      $data      array  Compiled template_data from ActaPreparationService::compile()
      $editable  bool   When true, injectable fields become contenteditable spans with
                        color-coded highlights by data source. Default: false.
                        Sources: mua (yellow) | ia (green) | socio (purple) | manual (blue)
--}}

@php
    use Carbon\Carbon;

    $editable       = $editable ?? false;

    // ── Single-value fields ────────────────────────────────────────────────────
    $denominacion   = trim($data['autorizacion_denominacion'] ?? '');
    $legalName      = strtoupper($denominacion ?: '[DENOMINACIÓN PENDIENTE]').' S.A. DE C.V.';
    $domicilio      = $data['domicilio_social']   ?? '';
    $comisario      = $data['comisario']          ?? '';
    $comisarioRfc   = $data['comisario_rfc']      ?? '';

    // company_activity may have 1–3 newline-separated parts (activity / description / products)
    $activity       = trim($data['company_activity'] ?? '');
    $actParts       = array_values(array_filter(array_map('trim', explode("\n", $activity))));
    $actPart1       = $actParts[0] ?? $activity;
    $actPart2       = $actParts[1] ?? $actPart1;
    $actPart3       = $actParts[2] ?? $actPart1;

    // Capital social
    $capitalSocial  = (int)($data['capital_social'] ?? 50000);
    $totalSharesFmt = number_format($capitalSocial);
    $valueFmt       = '$'.number_format($capitalSocial, 2).' M.N.';

    // ── Per-socio data ─────────────────────────────────────────────────────────
    $socios         = array_values($data['socios'] ?? []);
    $repLegal       = collect($socios)->first(fn($s) => !empty($s['is_legal_representative']))
                      ?? ($socios[0] ?? []);
    $repNombre      = strtoupper($repLegal['socio_nombre'] ?? '');
    $secretario     = isset($socios[1]) ? strtoupper($socios[1]['socio_nombre']) : $repNombre;

    // ── $ef() — wrap editable fields ──────────────────────────────────────────
    /**
     * Wrap a field value in a colour-coded contenteditable span when in editable mode.
     * In read-only mode returns the escaped string unchanged.
     *
     * @param  string  $field     template_data key
     * @param  string  $source    'mua' | 'ia' | 'socio' | 'manual'
     * @param  mixed   $value     Current display value
     * @param  int     $socioIdx  0-based socio index for nested keys; -1 otherwise
     */
    $ef = function (string $field, string $source, mixed $value, int $socioIdx = -1) use ($editable): string {
        $str = (string) ($value ?? '');
        if (! $editable) {
            return e($str);
        }
        $dataField = $socioIdx >= 0 ? "socios.{$socioIdx}.{$field}" : $field;
        return '<span class="ef ef-'.$source.'" data-field="'.e($dataField).'" contenteditable="true" spellcheck="false">'.e($str).'</span>';
    };
@endphp

@if($editable)
<style>
.ef { border-radius: 2px; padding: 0 3px; cursor: text; display: inline; }
.ef:hover  { opacity: .85; }
.ef:focus  { outline: none; }
.ef-mua    { background: #fef9c3; border-bottom: 2px solid #ca8a04; }
.ef-mua:focus  { box-shadow: 0 0 0 2px #fef08a; }
.ef-ia     { background: #dcfce7; border-bottom: 2px solid #16a34a; }
.ef-ia:focus   { box-shadow: 0 0 0 2px #bbf7d0; }
.ef-socio  { background: #ede9fe; border-bottom: 2px solid #7c3aed; }
.ef-socio:focus { box-shadow: 0 0 0 2px #ddd6fe; }
.ef-manual { background: #e0f2fe; border-bottom: 2px solid #0284c7; }
.ef-manual:focus { box-shadow: 0 0 0 2px #bae6fd; }
</style>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- DOCUMENTO LEGAL                                                            --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div style="font-family:'Times New Roman',Georgia,serif;font-size:12pt;line-height:2;color:#111;max-width:780px;margin:0 auto;background:#fff;padding:48px 56px;">

{{-- Encabezado --}}
<p style="text-align:center;font-weight:bold;letter-spacing:4px;margin-bottom:4px;">
    ——————————"E S T A T U T O S"——————————
</p>
<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin-bottom:4px;">
    ——————————————CAPÍTULO PRIMERO——————————————
</p>
<p style="text-align:center;font-weight:bold;letter-spacing:1px;margin-bottom:20px;">
    ———— DENOMINACIÓN, OBJETO, DOMICILIO, DURACIÓN Y EXTRANJERÍA ————
</p>

{{-- ARTÍCULO PRIMERO — Denominación --}}
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO PRIMERO.-</strong>
    La sociedad se denomina "<strong>{!! $ef('autorizacion_denominacion', 'mua', $legalName) !!}</strong>",
    esta denominación al emplearse irá siempre seguida de las palabras "SOCIEDAD ANÓNIMA DE CAPITAL VARIABLE",
    o simplemente de sus abreviaturas "S.A. DE C.V.". - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

{{-- ARTÍCULO SEGUNDO — Objeto --}}
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO SEGUNDO.-</strong>
    La sociedad tiene por objeto:
    {{-- company_activity may contain newline-separated parts; edit as a single block --}}
    {!! $ef('company_activity', 'manual', $activity ?: '[OBJETO SOCIAL PENDIENTE]') !!}.
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - A).- La compra, venta, importación, exportación, producción, distribución y en general,
    la comercialización de toda clase de productos alimenticios y subproductos orgánicos, tales como semillas, granos,
    oleaginosas, frutas, verduras, hortalizas, animales, sus derivados y otros. - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - B) La transportación de todos los productos, materias primas, herramientas, maquinaria, implementos y
    equipo necesario relacionado con los objetos anteriores. - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - Con fundamento en lo dispuesto en el segundo párrafo del artículo cuarto de la Ley General de
    Sociedades Mercantiles, la sociedad tendrá capacidad para realizar todos los actos de comercio necesarios para
    cumplir sus fines, entendiéndose como tales los que requiera para la consecución de su objeto social. Sin
    limitación a lo anterior, la sociedad podrá realizar los siguientes actos: - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - I.- Constituir y participar en el capital social de otras sociedades civiles o mercantiles, nacionales
    o extranjeras, al momento de su constitución o en cualquier momento posterior, así como tomar parte en su
    administración, disolución y liquidación. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - II.- Emitir, girar, endosar, aceptar, librar, avalar, descontar, suscribir y negociar toda clase de
    títulos de crédito, sin que se ubique en los supuestos de la fracción décima quinta del artículo doscientos
    noventa del Código Penal Federal. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - III.- Adquirir por cualquier título, dar o tomar en arrendamiento, y disponer de todos los bienes
    muebles que fueren necesarios o convenientes para la consecución del objeto social. - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - IV.- Adquirir por cualquier título, dar o tomar en arrendamiento y disponer de todos los bienes
    inmuebles que fueren necesarios o convenientes para la consecución del objeto social. - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - V.- Adquirir y explotar patentes, certificados de invención, diseños y modelos industriales, marcas,
    denominaciones de origen, nombres comerciales, slogans, franquicias y derechos de autor. - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - VI.- Otorgar y obtener todo tipo de financiamientos permitidos por la ley. - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - VII.- Otorgar todo tipo de garantías reales o personales, así como obligarse solidariamente, en
    relación con obligaciones propias o a cargo de terceros. - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - VIII.- En general, la realización y la celebración de toda clase de actos, operaciones, convenios y
    contratos, ya sean administrativos, civiles o mercantiles, necesarios para el cumplimiento del objeto social.
    - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

{{-- ARTÍCULO TERCERO — Domicilio --}}
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO TERCERO.-</strong>
    El domicilio de la sociedad es
    {!! $ef('domicilio_social', 'manual', $domicilio ?: '[DOMICILIO PENDIENTE]') !!},
    pudiendo establecer agencias o sucursales en cualquier otro lugar de la República Mexicana o del Extranjero,
    si así lo determinare la Asamblea General de Accionistas. - - - - - - - - - - - - - - - - - - - - - - - -
</p>

{{-- ARTÍCULO CUARTO — Duración --}}
<p style="text-align:justify;margin-bottom:20px;">
    - - - - <strong>ARTÍCULO CUARTO.-</strong>
    La duración de la sociedad será <strong>INDEFINIDA</strong>. - - - - - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">——————————————CAPÍTULO SEGUNDO——————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">——————CAPITAL SOCIAL, ACCIONES Y TRANSMISIÓN DE ACCIONES——————</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO QUINTO.-</strong>
    El capital social es variable. El capital social fijo es de
    <strong>{{ $totalSharesFmt }}</strong>, MONEDA NACIONAL. El capital social máximo es ilimitado. - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO SEXTO.-</strong>
    El capital social fijo de <strong>{{ $totalSharesFmt }}</strong>, MONEDA NACIONAL, estará representado por
    <strong>{{ $totalSharesFmt }}</strong> ACCIONES, con valor nominal de <strong>{{ $valueFmt }}</strong>, cada una.
    El capital variable estará representado por acciones que tendrán derecho a retiro en los términos del artículo
    doscientos veinte de la Ley de la materia. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO SÉPTIMO.-</strong>
    Las acciones en que se divide el capital social serán nominativas y estarán representadas por títulos que
    contendrán los requisitos establecidos por el artículo ciento veinticinco de la Ley General de Sociedades
    Mercantiles y demás aplicables. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO OCTAVO.-</strong>
    La sociedad considerará como dueño de las acciones a quien aparezca como tal en el libro de registro de
    acciones que llevará la sociedad. A petición de cualquier interesado, se inscribirán en dicho libro las
    transmisiones de acciones que se efectúen. En términos de lo dispuesto por el artículo ciento veintinueve de
    la Ley General de Sociedades Mercantiles, de la inscripción a que se refiere el párrafo anterior deberá
    publicarse un aviso en el sistema electrónico establecido por la Secretaría de Economía. Todo accionista por
    el hecho de serlo, se somete y queda sujeto a las estipulaciones de estos estatutos y a las resoluciones
    legalmente aprobadas por la Asamblea de Accionistas y el Administrador Único o Consejo de Administración.
    - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO NOVENO.-</strong>
    Todo aumento o disminución del capital social, deberá inscribirse en el libro de registro que al efecto
    llevará la sociedad.
    <br>
    - - - - A).- <strong>AUMENTO DE CAPITAL.-</strong> No podrá decretarse un aumento del capital si no están
    totalmente suscritas y pagadas todas las acciones emitidas con anterioridad por la sociedad. Cuando se
    aumente el capital se emitirán las acciones correspondientes al aumento y en este caso las acciones deberán
    ser ofrecidas primeramente a los accionistas en proporción a sus acciones, salvo acuerdo en contrario de la
    Asamblea. El derecho de preferencia de cada accionista deberá ejercitarse dentro de los quince días siguientes
    a la publicación correspondiente que ordena la Ley.
    <br>
    - - - - B).- <strong>REDUCCIÓN DE CAPITAL.-</strong> La reducción del capital social se efectuará por
    amortización de acciones íntegramente pagadas y mediante reembolso a los accionistas. La designación de las
    acciones afectadas a la reducción se hará por acuerdo unánime de los accionistas, o en su defecto, por
    sorteo. El capital variable de la sociedad es susceptible de aumentos y disminuciones sin necesidad de
    reformar los Estatutos Sociales y con la única formalidad de que sean aprobados por la Asamblea General de
    Accionistas. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO DÉCIMO.-</strong>
    En el caso a que se refiere el inciso B) del artículo anterior, hecha la designación de las acciones, se
    publicará un aviso en términos de lo que establece la Ley General de Sociedades Mercantiles. Ninguna
    reducción del capital fijo tendrá efecto sino después de tres meses de efectuada dicha publicación, durante
    cuyo lapso los acreedores de la sociedad podrán oponerse a ella. - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO DÉCIMO PRIMERO.-</strong>
    Los accionistas gozarán del derecho de preferencia para adquirir acciones de la sociedad que cualquier
    accionista desee transmitir, en proporción al número de acciones que cada uno posea, conforme al
    procedimiento siguiente:
    <br>
    - - - - I.- En caso de que algún accionista desee enajenar todas o parte de las acciones de las que sea
    titular, dará aviso por escrito al Órgano de Administración indicando el número y precio de las acciones que
    pretende enajenar y el nombre del adquirente propuesto.
    <br>
    - - - - II.- Una vez que reciba dicho escrito, el Órgano de Administración, dentro de un plazo de cinco días
    naturales, dará un aviso acerca de la oferta a todos los accionistas de la sociedad por cualquier medio
    fehaciente.
    <br>
    - - - - III.- Los accionistas gozarán de un plazo de quince días naturales contados a partir de la fecha del
    aviso señalado en el inciso anterior para ejercer su derecho de preferencia mediante notificación escrita
    al Órgano de Administración.
    <br>
    - - - - IV.- En caso de que más de un accionista manifieste su intención de adquirir las acciones ofrecidas,
    dichas acciones serán adquiridas por los accionistas interesados en proporción al número de acciones que
    cada uno de ellos posea.
    <br>
    - - - - V.- Al concluir el plazo a que se refiere el inciso III anterior, si los accionistas no han ejercido
    su derecho de preferencia para adquirir parte o la totalidad de las acciones ofrecidas, el accionista
    enajenante podrá proceder a realizar la transmisión de las acciones respecto de las cuales no se hubiere
    ejercido el derecho de preferencia. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:20px;">
    - - - - <strong>ARTÍCULO DÉCIMO SEGUNDO.-</strong>
    Los comparecientes convienen en que forme parte de los presentes estatutos, el CONVENIO DE ADMISIÓN DE
    EXTRANJEROS a que se refiere el artículo quince de la Ley de Inversión Extranjera, mediante el cual se
    establece que los socios extranjeros actuales y futuros se considerarán como nacionales respecto de dichas
    acciones y no invocarán la protección de sus gobiernos bajo la pena de perder en beneficio de la Nación
    las participaciones sociales que hubieren adquirido. - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">————————————— CAPÍTULO TERCERO  —————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">——————————————ADMINISTRACIÓN——————————————</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO DÉCIMO TERCERO.-</strong>
    La administración de la sociedad estará a cargo de un Administrador Único o de un Consejo de Administración
    de dos o más miembros, según lo determine la Asamblea General de Accionistas. La persona o personas
    designadas para tales cargos no requieren ser accionistas. - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO DÉCIMO CUARTO.-</strong>
    El Administrador Único o los Administradores que la Asamblea designe para formar el Consejo de Administración,
    durarán en su cargo indefinidamente y hasta que haya otro nombramiento o se resuelva su remoción o renuncia,
    pudiendo ser reelectos. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO DÉCIMO QUINTO.-</strong>
    El Administrador Único o el Consejo de Administración, en su caso, tendrá las siguientes facultades:
    <br>
    - - - - I.- Realizar todas las operaciones inherentes al objeto de la sociedad, exceptuándose aquellas que
    por Ley o por estos estatutos corresponden sólo a las asambleas de accionistas.
    <br>
    - - - - II.- Celebrar, modificar, novar y rescindir toda clase de contratos y convenios y en general ejecutar
    todos los actos que se relacionen directa o indirectamente con el objeto de la sociedad.
    <br>
    - - - - III.- Adquirir bienes muebles y los inmuebles que permitan las Leyes.
    <br>
    - - - - IV.- Renunciar al domicilio de la sociedad y someterla a otra jurisdicción.
    <br>
    - - - - V.- Nombrar y remover factores, agentes y empleados de la sociedad y atribuirles facultades,
    obligaciones y remuneraciones.
    <br>
    - - - - VI.- Establecer sucursales y agencias en cualesquiera lugares de la República o del extranjero y
    suprimirlas.
    <br>
    - - - - VII.- Las demás que le corresponden por la Ley o según estos estatutos.
    <br>
    - - - - VIII.- En general, y sin perjuicio de las facultades anteriores, estará investido de los poderes
    que se indican a continuación:
    <br>
    - - - - A).- <strong>PODER GENERAL PARA ACTOS DE ADMINISTRACIÓN</strong>, con todas las facultades
    administrativas, en los términos del segundo párrafo del artículo dos mil quinientos cincuenta y cuatro del
    Código Civil para el Distrito Federal y del correlativo de los demás Códigos Civiles de los Estados de la
    República y del Federal.
    <br>
    - - - - B).- <strong>PODER EN MATERIA LABORAL</strong>, por lo que el órgano de administración queda
    investido de la representación legal de la empresa y por ello facultado para celebrar arreglos
    conciliatorios, contestar las demandas, articular y absolver posiciones, y oponer excepciones. Asimismo,
    podrá comparecer ante las Juntas de Conciliación y Arbitraje y demás autoridades del trabajo, ya sean
    Federales o Locales.
    <br>
    - - - - C).- <strong>PODER GENERAL PARA ACTOS DE DOMINIO</strong>, por lo que el órgano de administración
    tendrá todas las facultades de dueño, tanto en lo relativo a los bienes de la sociedad como para hacer toda
    clase de gestiones a fin de defenderlos.
    <br>
    - - - - D).- <strong>PODER PARA SUSCRIBIR Y OTORGAR TODA CLASE DE TÍTULOS DE CRÉDITO</strong> en los
    términos del artículo noveno de la Ley General de Títulos y Operaciones de Crédito.
    <br>
    - - - - E).- <strong>PODER PARA OTORGAR PODERES GENERALES Y ESPECIALES Y PARA REVOCAR UNOS Y OTROS</strong>,
    dentro de los poderes conferidos a dicho órgano de administración. - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO DÉCIMO SEXTO.-</strong>
    Cuando la Asamblea General determine que la sociedad sea administrada por un Consejo de Administración,
    éste deberá funcionar como sigue:
    <br>
    - - - - I.- Estará compuesto por dos o más consejeros propietarios. La propia asamblea podrá nombrar también
    un consejero suplente por cada consejero propietario.
    <br>
    - - - - II.- Los consejeros suplentes entrarán en ejercicio cuando sean llamados por el Consejo, al faltar
    temporal o definitivamente los consejeros propietarios respectivos.
    <br>
    - - - - III.- Los consejeros propietarios y suplentes serán designados por la asamblea por simple mayoría
    de votos de los accionistas que representen las acciones en circulación.
    <br>
    - - - - IV.- Actuará como Presidente del Consejo, el consejero que sea designado para ese cargo en la
    Asamblea General Ordinaria de Accionistas.
    <br>
    - - - - V.- El Consejo se reunirá en el domicilio de la sociedad, cuando menos cada año en sesión ordinaria
    y en sesión extraordinaria siempre que sea citado por el Presidente del propio Consejo.
    <br>
    - - - - VI.- De cada sesión del Consejo se levantará un acta en la que se consignarán las resoluciones
    aprobadas, la cual será firmada por el que haya presidido la sesión y por el Secretario de la misma.
    <br>
    - - - - VII.- Las resoluciones tomadas fuera de sesión de Consejo, por unanimidad de sus miembros, tendrán,
    para todos los efectos legales, la misma validez que si hubieren sido adoptadas en sesión de Consejo.
    <br>
    - - - - VIII.- Las copias certificadas o extractos de las actas del Consejo que sea necesario extender por
    cualquier motivo, serán autorizadas por el Secretario del propio Consejo.
    <br>
    - - - - IX.- El cargo de consejero es compatible con el de Gerente. - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">———————————————CAPÍTULO CUARTO———————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">————————————————DE LOS GERENTES————————————————</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO DÉCIMO SÉPTIMO.-</strong>
    La sociedad podrá tener Gerentes Generales o Especiales, que deberán ser nombrados por la Asamblea General
    de Accionistas, por el Administrador Único o por el Consejo de Administración. Asimismo, el órgano de
    administración o la Asamblea General podrán designar Directores, cuyo nombramiento, atribuciones y revocación
    de su cargo, se sujetará a lo previsto para los Gerentes. - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO DÉCIMO OCTAVO.-</strong>
    En el ejercicio de sus respectivas facultades, llevarán la firma social el Administrador Único, el Consejo
    de Administración, su Presidente, los consejeros delegados, los Gerentes, y los apoderados que al efecto
    se designen. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:20px;">
    - - - - <strong>ARTÍCULO DÉCIMO NOVENO.-</strong>
    El Administrador Único o cada uno de los consejeros en su caso y el Gerente, al aceptar los cargos
    conferidos a su favor, protestarán desempeñarlos fiel y diligentemente. - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">———————————————CAPÍTULO QUINTO———————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">—————————————————DE LA VIGILANCIA—————————————————</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO.-</strong>
    La Asamblea General nombrará uno o más Comisarios propietarios y además podrá también nombrar uno o más
    suplentes. Todo accionista o grupo de accionistas que representen cuando menos el veinticinco por ciento del
    capital social podrán designar un Comisario. Las funciones del Comisario serán las que señala la Ley General
    de Sociedades Mercantiles. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:20px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO PRIMERO.-</strong>
    El o los Comisarios designados al aceptar el cargo conferido a su favor, protestarán desempeñarlo fiel y
    diligentemente sin necesidad de caucionar su manejo. - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">———————————————CAPÍTULO SEXTO———————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">——————————————DE LAS ASAMBLEAS——————————————</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO SEGUNDO.-</strong>
    El órgano supremo de la sociedad es la Asamblea General de Accionistas, la que podrá tomar toda clase de
    resoluciones y designar y remover a cualquier funcionario. Las resoluciones de las Asambleas Generales de
    Accionistas serán obligatorias para los ausentes o disidentes, salvo el derecho de oposición que establece
    la Ley General de Sociedades Mercantiles. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO TERCERO.-</strong>
    Las Asambleas Generales de Accionistas podrán ser Ordinarias o Extraordinarias, debiendo reunirse unas y
    otras en el domicilio social, salvo caso fortuito o fuerza mayor. En las Asambleas Ordinarias deberán
    tratarse los asuntos a que se refiere el artículo ciento ochenta y uno de la Ley General de Sociedades
    Mercantiles y cualquier otro que no sea de los reservados a las Extraordinarias. Las Asambleas Generales
    Extraordinarias serán las que se reúnan para tratar cualquiera de los asuntos a que se refiere el artículo
    ciento ochenta y dos de la citada Ley. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO CUARTO.-</strong>
    Las Asambleas Ordinarias se reunirán, por lo menos una vez al año dentro de los cuatro primeros meses del
    ejercicio social. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO QUINTO.-</strong>
    La convocatoria para las Asambleas será hecha por el Consejo de Administración, el Administrador Único o el
    Comisario, por medio de la publicación de un aviso en términos del artículo ciento ochenta y seis de la Ley
    General de Sociedades Mercantiles. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO SEXTO.-</strong>
    Las resoluciones de las Asambleas que se celebren sin cumplir los requisitos a que se refiere el artículo
    anterior, serán nulas, a menos que en el momento de tomarse se encuentre representada la totalidad de las
    acciones. Tampoco será necesaria la publicación previa de la convocatoria si se trata de la continuación de
    una Asamblea legalmente instalada. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO SÉPTIMO.-</strong>
    Los accionistas podrán concurrir a la Asamblea y votar en ella, personalmente o por medio de apoderados,
    que podrán ser nombrados por simple carta poder. El Administrador Único, los miembros del Consejo de
    Administración o los Comisarios, no podrán representar a los accionistas de la sociedad en las Asambleas.
    - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO OCTAVO.-</strong>
    Los títulos de las acciones, si así se requiere en la convocatoria, deberán ser depositados en el lugar que
    señale la misma, o a falta de designación, en las oficinas de la sociedad, cuando menos el día anterior al
    de la Asamblea. Cuando el tenedor de las acciones resida fuera de la República Mexicana, el depósito de los
    títulos podrá hacerse en un Banco u otro establecimiento que señale la convocatoria. - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO VIGÉSIMO NOVENO.-</strong>
    Para que la Asamblea General Ordinaria de Accionistas se considere legalmente instalada, deberá estar
    representada en ella, por lo menos la mitad del capital social, y sus resoluciones serán válidas cuando
    se tomen por mayoría de los votos presentes. Si no se reúne el quórum requerido, se hará segunda convocatoria
    y la Asamblea se considerará legalmente instalada cualquiera que sea el número de acciones representadas,
    tomándose igualmente las resoluciones por mayoría de los votos presentes. - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO.-</strong>
    Las Asambleas Generales Extraordinarias requerirán la representación de las tres cuartas partes del capital
    social y sus resoluciones sólo serán válidas cuando se tomen por el voto favorable de las acciones que
    representen la mitad del capital social. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO PRIMERO.-</strong>
    Si la Asamblea General Ordinaria no pudiere celebrarse el día señalado para la reunión, se hará una segunda
    convocatoria, con la expresión de esta circunstancia y se celebrará la Asamblea cualquiera que sea el número
    de acciones que estén representadas, siendo válidas las resoluciones que se tomen por mayoría de votos
    presentes. Tratándose de Asambleas Extraordinarias también se hará una segunda convocatoria en los términos
    establecidos. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO SEGUNDO.-</strong>
    El procedimiento de las Asambleas Generales de Accionistas será el siguiente:
    <br>
    - - - - I.- Serán presididas por el Administrador Único, o el Presidente del Consejo de Administración, en
    su caso, y actuará como Secretario el Secretario del propio Consejo.
    <br>
    - - - - II.- El Presidente nombrará a uno o más escrutadores para verificar el número de acciones
    representadas en la Asamblea y para hacer el recuento de las votaciones.
    <br>
    - - - - III.- Si se encuentra presente el quórum respectivo, el Presidente declarará legalmente instalada
    la Asamblea y se procederá al desahogo del Orden del Día.
    <br>
    - - - - IV.- De cada Asamblea, se levantará un acta que se asentará en el libro respectivo, y deberá ser
    firmada por lo menos por el Presidente y el Secretario de la Asamblea, así como por los Comisarios que
    hubieren asistido. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:20px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO TERCERO.-</strong>
    Las resoluciones tomadas fuera de Asamblea, por unanimidad de los accionistas que representen la totalidad
    de las acciones con derecho a voto, o de la categoría especial de acciones de que se trate, tendrán, para
    todos los efectos legales, la misma validez que si hubieren sido adoptadas reunidos en Asamblea General.
    - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">——————————————CAPÍTULO SÉPTIMO——————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">——————————————EJERCICIOS SOCIALES——————————————</p>

<p style="text-align:justify;margin-bottom:20px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO CUARTO.-</strong>
    El ejercicio social empezará el primero de enero y terminará el día treinta y uno de diciembre de cada año.
    Cada ejercicio social comprenderá un período de doce meses. - - - - - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">—————————————— CAPÍTULO OCTAVO ——————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:4px;">———————— DISTRIBUCIÓN DE UTILIDADES Y PÉRDIDAS, ————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">————————————————FONDO DE RESERVA————————————————</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO QUINTO.-</strong>
    Las utilidades que se obtuvieren en cada ejercicio social, de acuerdo con los estados financieros, se
    distribuirán de la manera siguiente:
    <br>
    - - - - I.- Un cinco por ciento será separado para formar y reconstituir en su caso, el fondo de reserva,
    hasta que importe la quinta parte del capital social.
    <br>
    - - - - II.- Se separará la cantidad que designe la Asamblea para remunerar al Administrador Único, o a los
    miembros del Consejo, según el caso, y al Comisario o Comisarios.
    <br>
    - - - - III.- Se aplicarán las cantidades que la Asamblea determine para la formación de fondos y previsión.
    <br>
    - - - - IV.- El remanente se distribuirá entre los accionistas en proporción al importe exhibido de sus
    acciones. Las utilidades no serán repartibles sino hasta que se encuentren convertidas en efectivo disponible
    y así lo acuerde la Asamblea. En todo caso se estará a lo dispuesto por el artículo décimo noveno de la
    Ley General de Sociedades Mercantiles. No se concede participación alguna en las utilidades a los fundadores,
    quienes sólo como accionistas tendrán derecho a percibir los dividendos correspondientes a las acciones que
    tuvieren. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:20px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO SEXTO.-</strong>
    Las pérdidas, si las hubiere, se distribuirán entre los accionistas en la misma proporción señalada para
    el reparto de utilidades en el inciso IV del artículo anterior. - - - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">———————————————CAPÍTULO NOVENO———————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">—————————————DISOLUCIÓN Y LIQUIDACIÓN—————————————</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO SÉPTIMO.-</strong>
    La sociedad se disolverá anticipadamente si así lo resolviere la Asamblea General Extraordinaria de
    Accionistas, y en los demás casos establecidos en el artículo doscientos veintinueve de la Ley General de
    Sociedades Mercantiles. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:20px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO OCTAVO.-</strong>
    La Asamblea que acuerde o reconozca la disolución de la sociedad, elegirá uno o más liquidadores, quienes
    practicarán la liquidación con sujeción a la Ley, y tendrán las facultades que en su caso les confiera la
    Asamblea. Mientras no haya sido inscrito en el Registro Público de Comercio el nombramiento de los
    liquidadores y éstos no hayan entrado en funciones, los administradores continuarán en el desempeño de sus
    funciones. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:20px 0 4px;">————————————————CAPÍTULO DÉCIMO————————————————</p>
<p style="text-align:center;font-weight:bold;margin-bottom:20px;">—————————————SOMETIMIENTO DE JURISDICCIÓN—————————————</p>

<p style="text-align:justify;margin-bottom:28px;">
    - - - - <strong>ARTÍCULO TRIGÉSIMO NOVENO.-</strong>
    Los fundadores, respecto a la interpretación y cumplimiento de los pactos contenidos en la presente
    escritura, se someten expresamente a la jurisdicción de los Tribunales del domicilio de la sociedad,
    renunciando al fuero que por razón de su domicilio presente o futuro pudiera corresponderles. - - - - - -
</p>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- ARTÍCULOS TRANSITORIOS                                                    --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<p style="text-align:center;font-weight:bold;letter-spacing:2px;margin:24px 0;">
    ——————————————ARTÍCULOS TRANSITORIOS——————————————
</p>

{{-- PRIMERO — Suscripción de capital --}}
<p style="text-align:justify;margin-bottom:8px;">
    - - - - <strong>PRIMERO.-</strong>
    Los fundadores suscriben y pagan las acciones que representan el capital social fijo, en la siguiente
    proporción:
</p>
@foreach($socios as $i => $socio)
@php
    $aportacion = (int)round($capitalSocial * ($socio['socio_participacion'] / 100));
@endphp
<p style="text-align:justify;margin-bottom:4px;padding-left:24px;">
    - - - - <strong>{!! $ef('socio_nombre', 'socio', strtoupper($socio['socio_nombre']), $i) !!}</strong>
    suscribe y paga
    <strong>{{ number_format($aportacion) }}</strong> ACCIONES
    que representan <strong>${{ number_format($aportacion) }}.00 PESOS, MONEDA NACIONAL</strong>.
</p>
@endforeach
<p style="text-align:justify;margin-bottom:14px;">
    TOTAL: <strong>{{ $totalSharesFmt }}</strong> ACCIONES QUE REPRESENTAN
    <strong>{{ $valueFmt }} PESOS, MONEDA NACIONAL</strong> - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:20px;">
    - - - - Todos los accionistas manifiestan haber pagado el monto de sus respectivas aportaciones en moneda
    nacional. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

{{-- SEGUNDO — Acuerdos --}}
<p style="text-align:justify;margin-bottom:8px;">
    - - - - <strong>SEGUNDO.-</strong>
    Los socios fundadores por unanimidad, toman los siguientes:
</p>
<p style="text-align:center;font-weight:bold;margin-bottom:12px;">——————————A C U E R D O S——————————</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - A).- La sociedad será administrada por un Consejo de Administración, designándose al efecto a las
    personas cuyos cargos y nombres se indican a continuación:
    <br><br>
    - - - - PRESIDENTE: <strong>{!! $ef('socio_nombre', 'socio', $repNombre, 0) !!}</strong>
    <br>
    - - - - SECRETARIO: <strong>{!! $ef('socio_nombre', 'socio', $secretario, count($socios) > 1 ? 1 : 0) !!}</strong>
    <br><br>
    - - - - El Consejo de Administración designado tendrá en el desempeño de su cargo todas las facultades y
    obligaciones que la Ley y esta escritura confieren e imponen a los de su clase, principalmente las que se le
    confieren en el artículo Décimo Quinto de la parte dispositiva de los estatutos sociales. Las personas
    designadas aceptan los cargos conferidos a su favor y protestan desempeñarlos fiel y diligentemente. - - -
</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - B).- Designan como <strong>COMISARIO</strong> de la sociedad a
    <strong>{!! $ef('comisario', 'manual', $comisario ?: '[COMISARIO PENDIENTE]') !!}</strong>,
    quien según manifiestan los comparecientes, no se encuentra en ninguno de los supuestos a que se refiere el
    artículo ciento sesenta y cinco de la Ley General de Sociedades Mercantiles, que le impida desempeñar dicho
    cargo, quien tendrá las facultades que le otorgan los artículos 164 al 171 de la misma Ley. - - - - - - -
</p>

<p style="text-align:justify;margin-bottom:8px;">
    - - - - C).- Otorgar a favor de <strong>{!! $ef('socio_nombre', 'socio', $repNombre, 0) !!}</strong>
    para que los ejerzan conjunta o separadamente, los siguientes poderes:
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - a).- <strong>PODER GENERAL PARA PLEITOS Y COBRANZAS</strong>, con todas las facultades generales
    y las especiales que requieran cláusula especial conforme a la Ley, en los términos del primer párrafo del
    artículo dos mil quinientos cincuenta y cuatro del Código Civil para el Distrito Federal. Los apoderados
    quedan investidos de la representación legal de la empresa y por ello facultados para celebrar arreglos
    conciliatorios, contestar las demandas, articular y absolver posiciones, y oponer excepciones. Asimismo,
    podrán comparecer ante las Juntas de Conciliación y Arbitraje y demás autoridades del trabajo. - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - b).- <strong>PODER GENERAL PARA ACTOS DE ADMINISTRACIÓN</strong>, con todas las facultades
    administrativas, en los términos del segundo párrafo del artículo dos mil quinientos cincuenta y cuatro del
    Código Civil para el Distrito Federal y del correlativo de la ley donde se ejercite este poder. - - - - -
</p>
<p style="text-align:justify;margin-bottom:14px;">
    - - - - c).- <strong>PODER PARA ABRIR, MANEJAR Y CANCELAR CUENTAS BANCARIAS</strong>, así como para
    <strong>GIRAR CHEQUES EN CONTRA DE LAS MISMAS</strong>. - - - - - - - - - - - - - - - - - - - - - - - -
</p>

<p style="text-align:justify;margin-bottom:14px;">
    - - - - D).- Autorizan a <strong>{!! $ef('socio_nombre', 'socio', $repNombre, 0) !!}</strong> para que
    conjunta o separadamente, tramiten y reciban del órgano desconcentrado de la Secretaría de Hacienda y
    Crédito Público denominado "Servicio de Administración Tributaria": (i) la Cédula de Identificación
    Fiscal o Constancia de Situación Fiscal de la persona moral que en este acto se constituye y (ii) el
    Certificado de Firma Electrónica Avanzada de la misma (e.firma), para lo cual, de conformidad con lo
    dispuesto por el artículo diecinueve A del Código Fiscal de la Federación, queda facultado para ejecutar
    todos los actos necesarios para ello. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

{{-- E — RFC por socio --}}
<p style="text-align:justify;margin-bottom:8px;">
    - - - - <strong>E).-</strong> Los comparecientes manifiestan:
</p>
@foreach($socios as $i => $socio)
<p style="text-align:justify;margin-bottom:4px;padding-left:24px;">
    - - - - a).- Que la clave del Registro Federal de Contribuyentes de
    <strong>{!! $ef('socio_nombre', 'socio', strtoupper($socio['socio_nombre']), $i) !!}</strong>
    es
    <strong style="font-family:monospace;">{!! $ef('socio_rfc', 'ia', strtoupper($socio['socio_rfc'] ?? ''), $i) !!}</strong>.
</p>
@endforeach

{{-- ARTÍCULO 2554 --}}
<p style="text-align:center;font-weight:bold;margin:20px 0 8px;">
    ————————ARTÍCULO DOS MIL QUINIENTOS CINCUENTA Y CUATRO————————
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - "En todos los poderes generales para pleitos y cobranzas, bastará que se diga que se otorga con
    todas las facultades generales y las especiales que requieran cláusula especial conforme a la ley, para que
    se entiendan conferidos sin limitación alguna. - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - En los poderes generales para administrar bienes, bastará expresar que se dan con ese carácter,
    para que el apoderado tenga toda clase de facultades administrativas. - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - En los poderes generales para ejercer actos de dominio, bastará que se den con ese carácter para que
    el apoderado tenga todas las facultades de dueño, tanto en lo relativo a los bienes, como para hacer toda
    clase de gestiones a fin de defenderlos. - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>
<p style="text-align:justify;margin-bottom:8px;">
    - - - - Cuando se quisieren limitar, en los tres casos antes mencionados las facultades de los apoderados,
    se consignarán las limitaciones o los poderes serán especiales. Los notarios insertarán este artículo en
    los testimonios de los poderes que otorguen". - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
</p>

{{-- GENERALES --}}
<p style="text-align:center;font-weight:bold;letter-spacing:3px;margin:20px 0 8px;">
    ———————————G E N E R A L E S———————————
</p>
<p style="text-align:justify;margin-bottom:12px;">
    - - - - Los comparecientes, bajo protesta de decir verdad, por sus generales declararon ser:
</p>

@foreach($socios as $i => $socio)
@php
    $esFem  = ($socio['socio_sexo'] ?? 'M') === 'F';
    $casado = str_contains(strtolower($socio['socio_estado_civil'] ?? ''), 'casad');
@endphp
<p style="text-align:justify;margin-bottom:14px;padding-left:20px;border-left:3px solid #e5e7eb;">
    - - - -
    <strong>{!! $ef('socio_nombre', 'socio', strtoupper($socio['socio_nombre']), $i) !!}</strong>,
    {!! $ef('socio_nacionalidad', 'ia', $socio['socio_nacionalidad'] ?? 'de nacionalidad pendiente', $i) !!},
    originari{{ $esFem ? 'a' : 'o' }} de
    {!! $ef('socio_estado_nacimiento', 'ia', $socio['socio_estado_nacimiento'] ?? '[LUGAR PENDIENTE]', $i) !!},
    donde nació el día
    {!! $ef('socio_fecha_nacimiento', 'ia', $socio['socio_fecha_nacimiento'] ?? '[FECHA PENDIENTE]', $i) !!},
    {!! $ef('socio_estado_civil', 'socio', $socio['socio_estado_civil'] ?? '', $i) !!}@if($casado && ($socio['socio_regimen_patrimonial'] ?? ''))
    bajo el régimen de {!! $ef('socio_regimen_patrimonial', 'socio', $socio['socio_regimen_patrimonial'], $i) !!}@endif,
    con domicilio en
    {!! $ef('socio_direccion', 'ia', $socio['socio_direccion'] ?? '[DOMICILIO PENDIENTE]', $i) !!},
    con clave del Registro Federal de Contribuyentes "<strong style="font-family:monospace;">{!! $ef('socio_rfc', 'ia', strtoupper($socio['socio_rfc'] ?? ''), $i) !!}</strong>",
    CURP "<strong style="font-family:monospace;">{!! $ef('socio_curp', 'ia', strtoupper($socio['socio_curp'] ?? ''), $i) !!}</strong>",
    identificad{{ $esFem ? 'a' : 'o' }} con
    {!! $ef('socio_tipo_identificacion', 'ia', $socio['socio_tipo_identificacion'] ?? 'pasaporte', $i) !!}
    número <strong style="font-family:monospace;">{!! $ef('socio_tipo_identificacion_numero', 'ia', $socio['socio_tipo_identificacion_numero'] ?? '', $i) !!}</strong>.
    @if($socio['is_legal_representative'])
    <em>(Representante Legal)</em>
    @endif
</p>
@endforeach

{{-- Pie --}}
<p style="margin-top:40px;border-top:1px solid #ddd;padding-top:10px;text-align:center;font-size:10pt;color:#888;">
    Borrador generado automáticamente — Nexum Core —
    {{ \Carbon\Carbon::parse($data['compiled_at'] ?? now())->format('d/m/Y H:i') }} —
    Expediente {{ $data['singapur_client_code'] ?? '' }}
</p>

</div>
