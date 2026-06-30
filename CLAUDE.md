# Nexum Core — Guía para Agentes de Código

Este documento es la fuente de verdad para cualquier agente (Claude, Cursor, etc.) que abra este repositorio. Lee esto antes de tocar cualquier archivo.

---

## 1. Qué es este proyecto

**Nexum Core** es el backend Laravel de la empresa **Backend Bridge Incorporation**. Su función principal es gestionar el proceso de constitución de empresas mexicanas (SA de CV, SRL de CV, SAPI de CV) para clientes chinos.

El flujo completo es:
1. China envía un expediente vía webhook (relay "Singapur") con documentos KYC en base64
2. Nexum recibe, almacena y crea un expediente en el dashboard Filament
3. El equipo de notaría (super_admin, notario, asistente_notario) avanza el expediente por el pipeline
4. El pipeline incluye denominación social (MUA/SE), firma DocuSign, constitución, domicilio fiscal, RFC, e.firma
5. Al completarse, la empresa china queda operativa en México

**URL producción:** `https://nexumcore.app` (desplegado en Laravel Cloud)

---

## 2. Stack tecnológico

| Capa | Tecnología |
|------|-----------|
| Framework | Laravel 13 + PHP 8.3 |
| Dashboard admin | Filament v5 (Filament/filament ^5.6) |
| Auth API | JWT (tymon/jwt-auth ^2.3) |
| Roles y permisos | Spatie laravel-permission ^8.0 |
| Cola de trabajos | Laravel Horizon (Redis) |
| Almacenamiento de docs | Cloudflare R2 (S3-compatible) |
| Email | Resend (resend/resend-php ^1.3, transporte `resend` nativo de Laravel) |
| API Docs | Scramble (dedoc/scramble) |
| CI local | Laravel Sail (Docker) |

---

## 3. Estructura del proyecto

```
app/
├── Console/Commands/
│   ├── SubmitDenominationsToMuaCommand.php   # Cron: envía denominaciones al bot MUA
│   └── PollMuaStatusCommand.php              # Cron: consulta resultados del bot MUA
├── DTOs/
│   ├── SingapurSubmissionDTO.php             # Payload del webhook de China
│   ├── SingapurFileDTO.php                   # Archivo individual (base64 inline)
│   └── SingapurShareholderDTO.php            # Accionista del expediente
├── Enums/
│   ├── RegistrationStageEnum.php             # ★ Pipeline: 9 etapas del proceso
│   ├── RegistrationStatusEnum.php            # ACTIVE|ON_HOLD|CANCELLED|COMPLETED
│   ├── DocumentTypeEnum.php
│   ├── EfirmaAppointmentStatusEnum.php
│   ├── LegalNameStatusEnum.php               # WAIT|PROCESS|APPROVED|REJECTED
│   ├── ShareholderRoleEnum.php
│   ├── NotificationEventEnum.php             # Catálogo de eventos notificables (extensible)
│   └── TaskPriorityEnum.php
├── Filament/
│   ├── Resources/
│   │   ├── RegistrationResource.php          # ★ Recurso central del dashboard
│   │   │   ├── Pages/ViewRegistration.php    # Vista con pipeline horizontal
│   │   │   ├── Actions/AdvanceStageAction.php # ★ "✓ Confirmar etapa" — avanza pipeline
│   │   │   ├── Actions/RequestEfirmaAppointmentAction.php
│   │   │   ├── Actions/ConfirmEfirmaOutcomeAction.php
│   │   │   └── RelationManagers/             # Shareholders, LegalNames, Documents, etc.
│   │   ├── MuaAccountResource.php            # Gestión de cuentas FIEL para MUA
│   │   └── NotificationSettingResource.php   # ★ Módulo "Notificaciones" (toggle + destinatarios, solo super_admin)
│   └── Widgets/
│       ├── AdminStatsOverview.php            # KPIs para super_admin
│       ├── NotarioStatsOverview.php          # KPIs para notario
│       ├── AsistenteStatsOverview.php        # KPIs para asistente_notario
│       └── StageDistributionChart.php        # Gráfica de distribución por etapa
├── Http/Controllers/
│   ├── Api/V3/
│   │   ├── AuthController.php                # Login JWT
│   │   ├── WebhookController.php             # ★ Recibe webhooks de China
│   │   ├── LegalNameController.php           # CRUD denominaciones sociales
│   │   └── MuaController.php                 # Check disponibilidad MUA
│   └── Admin/
│       └── DocumentRelayDownloadController.php # Descarga docs desde R2
├── Jobs/
│   └── ProcessSingapurWebhookJob.php         # Job asíncrono del webhook
├── Models/
│   ├── Registration.php                      # Expediente central
│   ├── Document.php                          # Archivo del expediente
│   ├── LegalName.php                         # Denominación social propuesta
│   ├── Shareholder.php                       # Accionista
│   ├── MuaAccount.php                        # Cuenta FIEL para portal SE
│   ├── MuaCredential.php                     # Credenciales cifradas de MuaAccount
│   ├── Note.php                              # Nota del expediente
│   ├── StageTransition.php                   # Auditoría de cambios de etapa
│   ├── NotificationSetting.php               # Config por evento: toggle + destinatarios (solo super_admin)
│   └── Task.php                              # Tarea asignada al expediente
├── Notifications/
│   ├── NewExpedienteReceived.php             # Expediente recibido OK (campana + email branded)
│   ├── ExpedienteReceptionFailed.php         # Falló el procesamiento del webhook (campana + email)
│   ├── AccountInvitationNotification.php     # Invitación de usuario (email)
│   └── DenominationResolvedNotification.php  # Resultado de dictamen MUA (aprobado/rechazado)
└── Services/
    ├── Notifications/
    │   └── EventNotifier.php                 # ★ Envía notificaciones según NotificationSetting (toggle + destinatarios)
    ├── Registration/
    │   ├── RegistrationUpsertService.php     # ★ Crea/actualiza expediente desde DTO
    │   └── StageTransitionService.php        # ★ Máquina de estados del pipeline
    ├── Singapur/
    │   └── SingapurRelayService.php          # Descarga bulk ZIPs del relay (no webhook normal)
    └── Mua/
        └── CheckMuaAvailabilityService.php   # Verifica disponibilidad del portal SE
```

---

## 4. Pipeline de etapas (RegistrationStageEnum)

Nueve etapas secuenciales — el notario las confirma una a una con el botón **"✓ Confirmar etapa"**. Sin gates duros: el sistema no valida requisitos previos, el equipo decide cuándo avanzar.

| # | Valor DB | Label | Notas |
|---|----------|-------|-------|
| 1 | `data_received` | Datos recibidos | Webhook de China procesado |
| 2 | `identity_validation` | Validación de identidad | Pasaportes y documentos KYC |
| 3 | `legal_name` | Denominación social | Proceso MUA / portal SE |
| 4 | `partner_signature` | Firma de socios | **DocuSign** — pendiente de integrar |
| 5 | `incorporation` | Constitución de empresa | Acta constitutiva |
| 6 | `tax_address` | Domicilio fiscal | Registro ante SAT |
| 7 | `sat_registration` | Alta en el SAT | RFC |
| 8 | `efirma_appointment` | Cita e.firma SAT | Certificados .cer y .key |
| 9 | `completed` | Empresa operativa | Estado final |

**Eliminado:** `bank_account` (apertura de cuenta bancaria) — quitado del flujo activo.

---

## 5. Flujo del webhook de China (Singapur relay)

```
China/Singapur → POST /api/v3/webhook/singapur
  → WebhookController → valida X-Nexum-Secret (HMAC)
  → ProcessSingapurWebhookJob (cola)
    → SingapurSubmissionParser → SingapurSubmissionDTO
    → RegistrationUpsertService::upsert($dto)
      → crea/actualiza Registration
      → crea LegalName inicial (priority 1)
      → sincroniza Shareholders
      → por cada archivo: decodifica base64 → Storage::put() en R2 → crea Document
    → EventNotifier->notify(EXPEDIENTE_RECEIVED, new NewExpedienteReceived(...))
       (campana + email branded; sólo a los destinatarios configurados y si el evento está activo)
  → si el job falla (failed()): EventNotifier->notify(EXPEDIENTE_RECEIVED, new ExpedienteReceptionFailed(...))
```

**Notificaciones (módulo configurable):** el envío ya no es "a todos los super_admin". `EventNotifier` consulta `NotificationSetting` para el evento `EXPEDIENTE_RECEIVED`: respeta el toggle on/off y la lista de destinatarios (siempre filtrada a super_admin). Se configura en el dashboard → **Configuración → Notificaciones** (`NotificationSettingResource`, sólo super_admin). Las notificaciones son `ShouldQueue` → corren como su propio job en la cola `default` de Horizon. El email usa el tema branded `nexum` (`resources/views/vendor/mail/`). Eventos nuevos se agregan en `NotificationEventEnum` (las filas se auto-crean). Seeder: `NotificationSettingsSeeder` (incluido en `DatabaseSeeder`).

**IMPORTANTE:** Los archivos llegan como base64 en el JSON (`content` field). No hay descarga server-to-server desde China. `SINGAPUR_API_URL` y `SINGAPUR_BEARER_TOKEN` son opcionales y sólo se usan en `SingapurRelayService` para descargas bulk.

### Estructura del JSON de China

> ⚠️ **Contrato real** (definido en `SingapurSubmissionParser` + `SingapurFileDTO`).
> Los datos de la empresa y los accionistas van **dentro de un objeto `fields`**
> con llaves **planas e indexadas** (`naturalShareholderName1`, …), **no** en un
> array `shareholders[]` ni en `company_name`/`company_type` de nivel superior.
> Para el documento listo para compartir con el relay, ver `docs/webhook-singapur.md`.

```json
{
  "id": "uuid-del-paquete-000003",
  "registration_number": "000003",
  "company_folder_name": "000003_NOVA CONSULTORA EMPRESARIAL",
  "incorporation_deed": null,
  "fields": {
    "companyName": "NOVA CONSULTORÍA EMPRESARIAL",
    "companyType": "sa",
    "_language": "zh",
    "companyObject": "Servicios de consultoría",
    "capitalSocial": 50000,
    "shareholderCount": 2,

    "shareholderType1": "natural",
    "naturalShareholderName1": "吴佳鑫",
    "naturalNationality1": "china",
    "naturalShareholderEmail1": "jiaxin@empresa.cn",
    "naturalSharePercentage1": 50,
    "naturalMarried1": "yes",

    "shareholderType2": "natural",
    "naturalShareholderName2": "李伟",
    "naturalNationality2": "china",
    "naturalShareholderEmail2": "liwei@empresa.cn",
    "naturalSharePercentage2": 50,
    "naturalMarried2": "no"
  },
  "files": [
    {
      "field": "naturalTaxCertificate1",
      "original_name": "TAX_ID.pdf",
      "relay_name": "000003__naturalTaxCertificate1__tax.pdf",
      "content_type": "application/pdf",
      "size": 108548,
      "content": "<base64_del_archivo>"
    }
  ]
}
```

**Campos obligatorios:**

- **Header:** `X-Nexum-Secret: <valor_de_SINGAPUR_WEBHOOK_SECRET>` (sin esto → `401`).
- **Validado antes del `202`** (síncrono, en `WebhookController@singapur`): `id`
  (también es la llave de idempotencia en `webhook_events`).
- **Validado en el job** (`SingapurSubmissionParser::parse`, falla el procesamiento
  aunque el HTTP haya devuelto `202`): `registration_number`, `company_folder_name`,
  `fields`.
- **Dentro de `fields`:** `shareholderCount` (entero) si hay socios; por cada socio
  `i` (1-based): `naturalShareholderName{i}` y `naturalSharePercentage{i}`.
  `companyName` y `companyType` no truenan si faltan (default `''`) pero el
  expediente quedaría sin nombre/tipo.
- **Cada entrada de `files[]`:** `field`, `original_name`, `relay_name`,
  `content_type`, `size` son obligatorias (`SingapurFileDTO::fromArray`); `content`
  es el base64 inline (sin él el archivo no se almacena).

**Opcionales:** `incorporation_deed` (acta pre-generada en base64, string u objeto
`{content, content_type, original_name}`), `fields._language` (default `zh`),
`fields.companyObject`, `fields.capitalSocial`, y los demás campos por socio
(`naturalGender{i}`, `naturalBirthdate{i}`, `naturalBirthplace{i}`,
`naturalCivilStatus{i}`, `naturalPhone{i}`, `naturalPhoneCountryCode{i}`,
`naturalTaxId{i}`, `naturalMarried{i}`).

---

## 6. Bot MUA (microservicio Python)

El portal de la SE (Secretaría de Economía) requiere automatización con navegador. Existe un microservicio Python separado (FastAPI + Playwright) que hace web scraping del portal MUA.

### Endpoints del bot

- `POST http://mua-bot:8000/submit` — envía denominaciones para dictamen
- `POST http://mua-bot:8000/status` — consulta resultados

### Autenticación bot → Laravel (webhook de regreso)

El bot firma con HMAC-SHA256 sobre el **JSON canónico** del payload (llaves
ordenadas alfabéticamente con `ksort`, `JSON_UNESCAPED_SLASHES |
JSON_UNESCAPED_UNICODE`) y lo manda en el header **`X-Signature`**.

El controller acepta dos esquemas de firma (para poder actualizar el bot sin
deploy en lockstep):
- **Cuerpo completo** (preferido): HMAC sobre todo el body (menos `signature`).
  Cubre `clave_unica`, la constancia PDF, `rejection_reason`, etc.
- **Heredado**: HMAC sólo sobre `{legal_name_id, status, timestamp}`.

```python
import hmac, hashlib, json
secret = os.environ["MUA_BOT_SECRET_KEY"]
# Esquema preferido: firmar el cuerpo completo, canónico (ksort + separators).
canonical = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
sig = hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
headers = {"X-Signature": sig}   # X-Mua-Signature se acepta como fallback
```

Laravel verifica en `MuaBotCallbackController@handle` (ruta `webhook/mua-bot`).
Además exige `MUA_BOT_SECRET_KEY` configurada (si falta → `500`, nunca firma con
llave vacía) y rechaza timestamps con más de 5 min de antigüedad (anti-replay).

### Crons del bot

```bash
# Cada minuto (ajustar según volumen)
* * * * * php artisan mua:submit-denominations
* * * * * php artisan mua:poll-status
```

---

## 7. Variables de entorno (producción)

```env
# App
APP_NAME=Nexum Core
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nexumcore.app
APP_KEY=<php artisan key:generate --show>

# Auth
JWT_SECRET=<php artisan jwt:secret --show>

# Webhook de China
SINGAPUR_WEBHOOK_SECRET=<openssl rand -hex 32>  # Compartir con China

# Bot MUA
MUA_BOT_URL=http://mua-bot:8000
MUA_BOT_SECRET_KEY=<openssl rand -hex 32>        # Compartir con bot Python

# Almacenamiento R2
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<R2 Access Key ID>
AWS_SECRET_ACCESS_KEY=<R2 Secret Access Key>
AWS_DEFAULT_REGION=auto
AWS_BUCKET=<nombre-del-bucket>
AWS_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true

# Email (Resend)
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=noreply@nexumcore.app
MAIL_FROM_NAME="Nexum Core"
RESEND_API_KEY=<API key de resend.com>

# Admin inicial (para seeder)
ADMIN_EMAIL=admin@nexumcore.app
ADMIN_PASSWORD=<contraseña_segura>
ADMIN_NAME=Administrador

# DB (Laravel Cloud provee automáticamente)
DB_CONNECTION=mysql
DB_HOST=...
DB_DATABASE=...

# Redis (Laravel Cloud)
REDIS_HOST=...
QUEUE_CONNECTION=redis
```

**Variables opcionales (relay bulk):**
```env
SINGAPUR_API_URL=     # URL del relay de China (sólo para SingapurRelayService)
SINGAPUR_BEARER_TOKEN=  # Token del relay de China
```

---

## 8. Base de datos

### Tablas principales

| Tabla | Descripción |
|-------|-------------|
| `registrations` | Expediente de constitución (tabla central) |
| `shareholders` | Accionistas por expediente |
| `legal_names` | Denominaciones sociales propuestas |
| `documents` | Archivos adjuntos (storage_path en R2) |
| `stage_transitions` | Auditoría de cambios de etapa |
| `notes` | Notas libres del equipo notarial |
| `tasks` | Tareas asignadas por expediente |
| `mua_accounts` | Cuentas FIEL para el portal SE |
| `mua_credentials` | Credenciales cifradas (cert, key, password) |
| `notifications` | Notificaciones DB de Filament (campana) |
| `notification_settings` | Config por evento: toggle on/off (NotificationEventEnum) |
| `notification_setting_user` | Destinatarios por evento (pivote, sólo super_admin) |
| `roles`, `permissions` | Spatie permission |

### Columna `stage` en registrations

Tipo: `string`. Valores posibles: `data_received`, `identity_validation`, `legal_name`, `partner_signature`, `incorporation`, `tax_address`, `sat_registration`, `efirma_appointment`, `completed`.

**IMPORTANTE:** `bank_account` fue eliminado. La migración `2026_06_21_000001_update_stages_remove_bank_account.php` convierte registros históricos a `tax_address`.

---

## 9. Roles y permisos (Spatie)

| Rol | Acceso |
|-----|--------|
| `super_admin` | Todo — ve AdminStatsOverview + StageDistributionChart |
| `notario` | Sus expedientes asignados — ve NotarioStatsOverview |
| `asistente_notario` | Tareas y documentos — ve AsistenteStatsOverview |

**Seed inicial (obligatorio en producción):**
```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan db:seed --class=AdminUserSeeder
```

O todo el DatabaseSeeder: `php artisan db:seed`

---

## 10. DocuSign (etapa PARTNER_SIGNATURE)

El código de DocuSign existe en el repositorio Tally (`tally-backend-php-main`):
- `app/Services/DocuSign/DocuSignService.php` — cliente completo con JWT auth
- `app/Http/Controllers/Api/V3/DocuSignController.php` — endpoints

Para nexum-core, la etapa `partner_signature` aún no tiene la integración DocuSign conectada. **Siguiente tarea:** crear `PartnerSignatureAction` en Filament que invoque el servicio DocuSign para enviar el acta constitutiva a firma de los socios.

Variables de entorno que se necesitarán:
```env
DOCUSIGN_INTEGRATION_KEY=
DOCUSIGN_USER_ID=
DOCUSIGN_ACCOUNT_ID=
DOCUSIGN_PRIVATE_KEY=
DOCUSIGN_SECRET_HMAC=
DOCUSIGN_AUTH_SERVER=account.docusign.com
```

---

## 11. Despliegue (Laravel Cloud)

- **Plataforma:** Laravel Cloud
- **DNS:** Porkbun → dos registros A apuntando a `103.133.1.1` (nexumcore.app y www.nexumcore.app)
- **Pendiente:** Propagación DNS puede tardar hasta 48h
- **Comandos al desplegar:**
  ```bash
  php artisan migrate
  php artisan db:seed
  php artisan horizon  # Cola de trabajos
  ```

---

## 12. Rutas API (V3)

```
POST   /api/v3/auth/login                     # JWT login
POST   /api/v3/webhook/singapur               # Webhook de China (sin auth, HMAC)
POST   /api/v3/webhook/mua-bot                # Callback del bot MUA (HMAC X-Signature)
GET    /api/v3/mua/check-availability         # Estado del portal SE (sin auth)
GET    /api/v3/legal-names                    # Listado denominaciones (JWT)
POST   /api/v3/legal-names                    # Crear denominación (JWT)
PUT    /api/v3/legal-names/{id}               # Actualizar denominación (JWT)
DELETE /api/v3/legal-names/{id}               # Eliminar denominación (JWT)
```

Dashboard: `https://nexumcore.app/admin`

---

## 13. Comandos de desarrollo

```bash
# Con Sail (Docker)
vendor/bin/sail up -d
vendor/bin/sail artisan migrate
vendor/bin/sail artisan db:seed
vendor/bin/sail artisan horizon

# Tests
vendor/bin/sail artisan test
vendor/bin/sail artisan test --filter=RegistrationUpsertServiceTest

# Formato de código (obligatorio antes de commit)
vendor/bin/sail bin pint --dirty
```

---

## 14. Convenciones de código

- PHPDoc completo en **todos los métodos** (público, protegido, privado) — obligatorio
- Comentarios e identificadores en **inglés**
- Códigos HTTP: siempre `Response::HTTP_OK`, nunca `200`
- Controladores delgados: sólo validación + llamada a servicio
- Servicios en `app/Services/{Modulo}/`, Enums en `app/Services/{Modulo}/Enums/` o `app/Enums/`
- Nova no aplica — este repo usa **Filament** como dashboard
- Ver `CLAUDE.md` en `tally-backend-php-main` para convenciones heredadas que aplican aquí

---

## 15. Lo que está pendiente de desarrollar

| Tarea | Prioridad |
|-------|-----------|
| Integración DocuSign en etapa `partner_signature` | Alta |
| `queryMuaStatus()` en `PollMuaStatusCommand` (depende del bot Python) | Alta |
| Conectar bot Python MUA al contenedor Docker | Alta |
| Configurar R2 bucket y credenciales en producción | Alta |
| Verificar propagación DNS y primer deploy en Laravel Cloud | Alta |
| Tests para AdvanceStageAction y StageTransitionService | Media |
| Vista pública de estado del expediente para el cliente chino | Baja |
| ~~Notificación email al recibir nuevo expediente (Resend)~~ ✅ Hecho — módulo configurable (ver §5) | — |

---

## 16. Contexto de negocio clave

- **China = "Singapur"**: el relay chino se llama internamente "Singapur" en todo el código
- **MUA**: el portal web de la Secretaría de Economía donde se solicitan las denominaciones sociales
- **FIEL**: firma electrónica avanzada del SAT, necesaria para operar en el portal SE
- **Expediente**: el registro completo del proceso de constitución de una empresa
- **Relay**: el sistema chino que nos envía los documentos KYC de los clientes
- Los documentos llegan en **base64 inline** en el JSON — no hay descarga separada desde China
- Los documentos se almacenan en **Cloudflare R2** con rutas `documents/{registration_id}/{field}_{filename}`
