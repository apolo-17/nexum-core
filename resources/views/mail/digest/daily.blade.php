@php
    /**
     * Daily expedient digest.
     *
     * Every figure comes from DailyDigestService; the greeting and summary come
     * from DailyDigestNarrator when it is available, and fall back to static text
     * when it is not — the report must go out either way.
     */
    $totals = $digest['totals'];

    // Pipes would break the markdown tables below.
    $clean = fn (?string $value): string => str_replace('|', '/', (string) $value);
@endphp

<x-mail::message>
# Reporte diario de expedientes

@if ($briefing)
{{ $briefing['greeting'] }}

{{ $briefing['summary'] }}
@else
Buenos días. Este es el resumen diario del estado de los expedientes de constitución, con el corte de las {{ $digest['as_of']->format('H:i') }} de hoy. Abajo verás qué requiere atención, qué expedientes llevan más tiempo detenidos en su etapa y qué se movió desde el corte anterior.
@endif

<x-mail::table>
| Indicador            | Total                       |
|:---------------------|----------------------------:|
| Activos              | {{ $totals['active'] }}     |
| Atrasados            | {{ $totals['overdue'] }}    |
| Avanzaron de etapa   | {{ $totals['advanced'] }}   |
| Nuevos de China      | {{ $totals['new'] }}        |
| Completados          | {{ $totals['completed'] }}  |
@if ($totals['on_hold'] > 0)
| En pausa             | {{ $totals['on_hold'] }}    |
@endif
</x-mail::table>

"Atrasados" son los que rebasaron el umbral de su etapa. Las tres cifras de abajo cubren desde el corte del {{ $digest['since']->locale('es')->isoFormat('dddd D [de] MMMM') }}.

@if ($briefing && $briefing['priorities'] !== [])
<x-mail::panel>
**Prioridades de hoy**

@foreach ($briefing['priorities'] as $priority)
{{ $loop->iteration }}. {{ $priority }}
@endforeach
</x-mail::panel>
@endif

@if (! empty($digest['citas_nuevas']))
## Citas conseguidas 🎉

Citas que el SAT agendó desde el corte anterior:

| Empresa | Soldado | Trámite | Fecha de la cita | Se consiguió en |
|---------|---------|---------|------------------|-----------------|
@foreach ($digest['citas_nuevas'] as $c)
| {{ $clean($c['company']) }} | {{ $clean($c['soldado']) }} | {{ $c['tipo'] }} | {{ $c['fecha'] }} | {{ $c['dias'] !== null ? $c['dias'].' día'.($c['dias'] === 1 ? '' : 's') : '—' }} |
@endforeach

@endif
## Requieren atención

@if ($digest['alerts']['items'] === [])
Ningún expediente rebasó su umbral hoy y no hay denominaciones rechazadas, citas del SAT sin fecha ni tareas vencidas.
@else
<x-mail::table>
| Empresa                                | Situación                                            |
|:---------------------------------------|:-----------------------------------------------------|
@foreach ($digest['alerts']['items'] as $alert)
| {{ $alert['severity'] === 'overdue' ? '**'.$clean($alert['company']).'**' : $clean($alert['company']) }} | {{ $clean($alert['reason']) }} |
@endforeach
</x-mail::table>

Las empresas **en negritas** rebasaron el umbral de su etapa; las demás son avisos.

@if ($digest['alerts']['overflow'] > 0)
Hay {{ $digest['alerts']['overflow'] }} avisos más que no caben en este correo. Revísalos en el panel.
@endif
@endif

@if ($digest['oldest'] !== [])
## Los más viejos en su etapa

<x-mail::table>
| Empresa                                | Etapa                        | Días     |
|:---------------------------------------|:-----------------------------|---------:|
@foreach ($digest['oldest'] as $row)
| {{ $clean($row['company']) }} | {{ $row['stage']->label() }} | {{ $row['days_in_stage'] }} / {{ $row['days_total'] }} |
@endforeach
</x-mail::table>

La columna de días son dos cifras: cuánto lleva atorado en su etapa actual y, después de la diagonal, cuánto lleva esperando el cliente desde que China envió el expediente.
@endif

@if ($digest['distribution'] !== [])
## Dónde está la cartera

<x-mail::table>
| Etapa                    | Expedientes | Días promedio |
|:-------------------------|------------:|--------------:|
@foreach ($digest['distribution'] as $stage)
| {{ $stage['label'] }} | {{ $stage['count'] }} | {{ $stage['avg_days'] }}{{ $stage['over_threshold'] ? ' ⚠' : '' }} |
@endforeach
</x-mail::table>

Un ⚠ marca las etapas cuyo promedio ya rebasó su umbral: ahí está el cuello de botella.
@endif

## Movimiento desde el corte anterior

@if ($digest['movements'] === [])
Ningún expediente cambió de etapa.
@else
@foreach ($digest['movements'] as $movement)
- **{{ $clean($movement['company']) }}** pasó a {{ $movement['to'] }}
@endforeach
@endif

<x-mail::button :url="$dashboardUrl" color="primary">
Abrir Nexum Core
</x-mail::button>

<x-mail::subcopy>
Recibes este correo porque estás en los destinatarios del evento **Reporte diario de expedientes**. Para cambiar quién lo recibe o desactivarlo, entra a Configuración → Notificaciones en el panel.
</x-mail::subcopy>
</x-mail::message>
