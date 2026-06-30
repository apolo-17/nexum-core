<x-mail::message>
# Denominación registrada en la SE

El bot registró correctamente una denominación en el portal de la Secretaría de Economía.

<x-mail::table>
| Dato              | Detalle           |
|:------------------|:------------------|
| **Denominación**  | {{ $name }}       |
| **Ubicación**     | {{ $expedient }}  |
</x-mail::table>

Queda en dictamen de la SE. Te avisaremos cuando se resuelva.

<x-mail::button :url="$url" color="primary">
Ver en el panel
</x-mail::button>

Gracias,<br>
El equipo de {{ config('app.name') }}
</x-mail::message>
