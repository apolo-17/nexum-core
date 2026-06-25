<?php

/*
|--------------------------------------------------------------------------
| Documentación de la API — Español (idioma por defecto)
|--------------------------------------------------------------------------
|
| Textos que App\Docs\OpenApiLocalizer inyecta en el documento OpenAPI
| generado por Scramble para la variante en español (/docs/api/es.json).
|
| Las operaciones se identifican por su clave canónica "<método> <ruta>"
| (sin el prefijo "api/" y sin la barra inicial), p. ej.:
|   "post v3/webhook/singapur".
|
| La llave EN equivalente vive en lang/en/api.php — mantener ambas en sync.
|
*/

return [

    'info' => [
        'title' => 'Nexum Core — API de Integración China',
        'description' => <<<'MD'
API del backend de **Backend Bridge Incorporation** para la constitución de
empresas mexicanas a partir de expedientes enviados desde China a través del
relay interno **"Singapur"**.

Incluye los webhooks de entrada (expedientes KYC y dictámenes de denominación),
los endpoints públicos de consulta del portal **MUA** (Secretaría de Economía) y
los endpoints autenticados para el equipo de notaría.

### Autenticación
- **`bearerAuth`** — JWT (`Authorization: Bearer <token>`) para los endpoints del equipo de notaría.
- **`singapurSecret`** — secreto compartido (`X-Nexum-Secret`) para el webhook del relay de China.
- **`muaBotSignature`** — firma HMAC-SHA256 (`X-Signature`) para el callback del bot MUA.
- **`muaBotApiKey`** — llave de API (`X-Bot-Api-Key`) para el poll del bot MUA.
- **`docusignSignature`** — firma HMAC (`X-DocuSign-Signature-1`) para el webhook de DocuSign.
MD,
    ],

    'tags' => [
        'Webhooks (China / Singapur)' => 'Endpoints de entrada que recibe Nexum desde el relay de China y servicios externos.',
        'MUA / Denominaciones' => 'Consulta de disponibilidad y resolución de denominaciones sociales en el portal de la Secretaría de Economía.',
        'Autenticación' => 'Emisión y gestión de tokens JWT para los consumidores de la API.',
        'Expedientes' => 'Lectura y avance del pipeline de los expedientes de constitución.',
    ],

    'operations' => [

        // -- Webhooks de China / externos ------------------------------------
        'post v3/webhook/singapur' => [
            'tag' => 'Webhooks (China / Singapur)',
            'summary' => 'Recibir expediente de China (relay Singapur)',
            'description' => <<<'MD'
Punto de entrada principal del relay de China. Recibe el expediente KYC completo
de una empresa por constituir, con los documentos en **base64 inline** dentro del
JSON (no hay descarga servidor-a-servidor).

El endpoint valida el secreto compartido del header `X-Nexum-Secret`, garantiza
**idempotencia** por el campo `id` (UUID del paquete) y encola el procesamiento en
segundo plano, respondiendo de inmediato.

**Respuestas:** `202` aceptado · `401` secreto inválido · `409` evento ya recibido · `422` falta `id`.
MD,
        ],

        'post v3/webhook/docusign' => [
            'tag' => 'Webhooks (China / Singapur)',
            'summary' => 'Webhook de DocuSign Connect',
            'description' => <<<'MD'
Recibe las notificaciones de cambio de estado de los sobres (envelopes) de
DocuSign Connect para la etapa de firma de socios. Valida la firma **HMAC-SHA256**
del header `X-DocuSign-Signature-1` y encola el procesamiento dentro del límite de
5 segundos que exige DocuSign.

**Respuestas:** `202` aceptado · `401` HMAC inválido o ausente.
MD,
        ],

        'post v3/webhook/mua-bot' => [
            'tag' => 'Webhooks (China / Singapur)',
            'summary' => 'Callback del bot MUA (dictamen de denominación)',
            'description' => <<<'MD'
El bot MUA (microservicio Python) llama a este endpoint cuando la Secretaría de
Economía resuelve una denominación social (**aprobada** o **rechazada**).

Cuando es aprobada, el bot envía la **constancia de autorización de denominación
social** en base64; Nexum la guarda en R2, crea el `Document` y marca la
`LegalName` como aprobada (rechazando automáticamente las demás del expediente).

Seguridad: firma **HMAC-SHA256** (`X-Signature`) con protección anti-replay
(ventana de 5 minutos vía `timestamp`).

**Respuestas:** `200` registrado · `401` firma inválida / expirada · `404` denominación no encontrada · `422` estado inválido · `500` fallo al procesar.
MD,
        ],

        // -- MUA / Denominaciones --------------------------------------------
        'post v3/legal-name/check-availability' => [
            'tag' => 'MUA / Denominaciones',
            'summary' => 'Verificar disponibilidad de denominación (público)',
            'description' => <<<'MD'
Consulta si una denominación social propuesta está disponible en el portal del MUA.

Es **público** (sin JWT) a propósito, para que el relay de China pueda consultarlo
directamente mientras el cliente chino elige los nombres de su empresa.

**Respuestas:** `200` con `available: true|false` · `503` si el portal del MUA no está disponible (`available: null`).
MD,
        ],

        // -- Bot MUA: poll de pendientes -------------------------------------
        'get v3/mua-bot/pending' => [
            'tag' => 'MUA / Denominaciones',
            'summary' => 'Denominaciones pendientes para el bot MUA',
            'description' => <<<'MD'
Devuelve las denominaciones en estado `PENDING` o `PROCESS` que ya tienen una
cuenta FIEL asignada, junto con las credenciales (certificado, llave y contraseña
en base64) para que el bot se autentique en el portal de la SE.

Seguridad: llave de API compartida en el header `X-Bot-Api-Key`.

**Respuestas:** `200` con la lista · `401` no autorizado.
MD,
        ],

        // -- Autenticación ----------------------------------------------------
        'post v3/auth/login' => [
            'tag' => 'Autenticación',
            'summary' => 'Iniciar sesión (JWT)',
            'description' => 'Autentica con email y contraseña y devuelve un token JWT de tipo bearer junto con su tiempo de expiración.',
        ],
        'get v3/auth/me' => [
            'tag' => 'Autenticación',
            'summary' => 'Perfil del usuario autenticado',
            'description' => 'Devuelve los datos del usuario asociado al token JWT actual, incluyendo su rol.',
        ],
        'post v3/auth/logout' => [
            'tag' => 'Autenticación',
            'summary' => 'Cerrar sesión',
            'description' => 'Invalida el token JWT actual.',
        ],
        'post v3/auth/refresh' => [
            'tag' => 'Autenticación',
            'summary' => 'Refrescar token',
            'description' => 'Invalida el token JWT actual y devuelve uno nuevo.',
        ],

        // -- Expedientes ------------------------------------------------------
        'get v3/registrations' => [
            'tag' => 'Expedientes',
            'summary' => 'Listar expedientes',
            'description' => 'Devuelve una lista paginada de expedientes ordenados del más reciente al más antiguo. Soporta `?per_page=N` (máximo 100).',
        ],
        'get v3/registrations/{singapurClientCode}' => [
            'tag' => 'Expedientes',
            'summary' => 'Consultar expediente por código de cliente',
            'description' => 'Devuelve un expediente identificado por su código de cliente Singapur (p. ej. `000001`) con sus accionistas, denominaciones y documentos.',
        ],
        'post v3/registrations/{singapurClientCode}/advance' => [
            'tag' => 'Expedientes',
            'summary' => 'Avanzar expediente de etapa',
            'description' => 'Avanza el expediente a la siguiente etapa del pipeline, registrando la transición de forma auditable. Acepta un `reason` opcional en el cuerpo.',
        ],
        'post v3/registrations/{registration}/legal-names' => [
            'tag' => 'MUA / Denominaciones',
            'summary' => 'Agregar denominación a un expediente',
            'description' => 'Agrega una propuesta de denominación social (máximo 4 por expediente; bloqueado si ya existe una aprobada).',
        ],
        'delete v3/registrations/{registration}/legal-names/{legalName}' => [
            'tag' => 'MUA / Denominaciones',
            'summary' => 'Eliminar denominación de un expediente',
            'description' => 'Elimina una propuesta de denominación (debe quedar un mínimo de 3; no se permite si está en proceso o aprobada).',
        ],
    ],
];
