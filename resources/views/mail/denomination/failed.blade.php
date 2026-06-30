<x-mail::message>
# Error al enviar denominación

El bot **no pudo registrar** una denominación en el portal de la Secretaría de Economía. No es un rechazo de la SE: fue un fallo técnico del envío.

<x-mail::table>
| Dato              | Detalle           |
|:------------------|:------------------|
| **Denominación**  | {{ $name }}       |
| **Ubicación**     | {{ $expedient }}  |
| **Motivo**        | {{ $reason ?? 'No especificado' }} |
</x-mail::table>

La denominación regresó a la cola (**en espera**). Puedes reenviarla manualmente desde el panel cuando el problema esté resuelto.

<x-mail::button :url="$url" color="error">
Ver en el panel
</x-mail::button>

Gracias,<br>
El equipo de {{ config('app.name') }}
</x-mail::message>
