<x-mail::message>
# Falló el procesamiento de un expediente

China envió un expediente pero **Nexum Core** no pudo darlo de alta correctamente. Es necesaria una revisión manual.

<x-mail::panel>
**Error:** {{ $errorMessage }}
</x-mail::panel>

<x-mail::table>
| Dato              | Detalle           |
|:------------------|:------------------|
| **ID del evento** | {{ $eventId }}    |
| **Origen**        | {{ $source }}     |
</x-mail::table>

Revisa los registros de webhooks y la cola (Horizon) para reintentar el procesamiento.

Gracias,<br>
El equipo de {{ config('app.name') }}
</x-mail::message>
