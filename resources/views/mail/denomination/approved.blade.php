<x-mail::message>
# Denominación aprobada por la SE

La Secretaría de Economía **autorizó** una denominación. La constancia ya fue recibida y guardada.

<x-mail::table>
| Dato              | Detalle           |
|:------------------|:------------------|
| **Denominación**  | {{ $name }}       |
| **Ubicación**     | {{ $expedient }}  |
</x-mail::table>

Ya puedes avanzar el trámite con esta denominación.

<x-mail::button :url="$url" color="success">
Ver en el panel
</x-mail::button>

Gracias,<br>
El equipo de {{ config('app.name') }}
</x-mail::message>
