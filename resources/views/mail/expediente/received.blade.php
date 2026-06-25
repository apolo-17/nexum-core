<x-mail::message>
# Nuevo expediente recibido

China envió un nuevo expediente y se dio de alta correctamente en **Nexum Core**.

<x-mail::table>
| Dato        | Detalle              |
|:------------|:---------------------|
| **Código**  | {{ $clientCode }}    |
| **Empresa** | {{ $companyName }}   |
</x-mail::table>

<x-mail::button :url="$url" color="primary">
Ver expediente
</x-mail::button>

Revisa el expediente para iniciar la validación de identidad.

Gracias,<br>
El equipo de {{ config('app.name') }}
</x-mail::message>
