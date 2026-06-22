# Webhook Singapur — Contrato de integración

Fuentes de verdad consultadas para este documento:
- `000001_NOVA_CONSULTORA_EMPRESARIAL/submission.json` — paquete real enviado por China
- `000001_NOVA_CONSULTORA_EMPRESARIAL/KYC/` — archivos reales del ZIP
- `tally-backend-php-main` — `ChineseCompaniesDocumentsMigrationService::resolveDocumentType()` y `DocumentManagerService::getPartnerDetailCard()`

---

## Endpoint

```
POST /api/v3/webhook/singapur
```

Sin autenticación JWT. Verificación por header HMAC.

### Header requerido

```
X-Nexum-Secret: <SINGAPUR_WEBHOOK_SECRET>
```

Respuestas: `202 Accepted` | `401 Unauthorized` | `422 Unprocessable Entity`

---

## Flujo de procesamiento

```
China → POST /api/v3/webhook/singapur
  → WebhookController         valida X-Nexum-Secret
  → ProcessSingapurWebhookJob cola asíncrona (Redis / Horizon)
    → SingapurSubmissionDTO   deserializa el JSON
    → RegistrationUpsertService::upsert()
        crea/actualiza Registration
        crea LegalName inicial (priority 1 — campo companyName)
        sincroniza Shareholders
        por cada archivo en files[]:
            base64_decode(content) → Storage::put() → crea Document
    → NewExpedienteReceivedNotification a super_admins
```

Los archivos llegan **base64 inline** en `files[].content`. No hay descarga separada.

---

## Estructura del JSON

```json
{
  "id": "7dde1760-57d4-4f4e-b81b-3ae2b93025d0",
  "type": "company-registration",
  "registration_number": "000001",
  "company_folder_name": "000001_NOVA CONSULTORA EMPRESARIAL",
  "document_group": "KYC",
  "created_at": "2026-06-14T22:35:56.765341+00:00",

  "fields": {
    "companyName": "NOVA CONSULTORÍA EMPRESARIAL",
    "companyType": "sa",
    "shareholderCount": "2",

    "shareholderType1": "natural",
    "naturalShareholderName1": "吴佳鑫",
    "naturalShareholderEmail1": "上海",
    "naturalSharePercentage1": "50",
    "naturalNationality1": "china",
    "naturalOtherNationality1": "",
    "naturalMarried1": "yes",

    "shareholderType2": "natural",
    "naturalShareholderName2": "李锐佳",
    "naturalShareholderEmail2": "上海",
    "naturalSharePercentage2": "50",
    "naturalNationality2": "china",
    "naturalOtherNationality2": "",
    "naturalMarried2": "yes"
  },

  "files": [
    {
      "field": "naturalTaxCertificate1",
      "original_name": "JIAXIN_WIU_TAX_ID.pdf",
      "relay_name": "000001__NOVA-CONSULTORA__naturalTaxCertificate1__JIAXIN_WIU_TAX_ID_untranslated.pdf",
      "storage": "local",
      "content_type": "application/pdf",
      "size": 108548,
      "content": "<base64>"
    }
  ]
}
```

### Campos raíz del JSON

| Campo | Obligatorio | Tipo | Descripción |
|---|---|---|---|
| `id` | ✅ Sí | string (UUID) | Identificador único del paquete — usado para idempotencia en Nexum |
| `registration_number` | ✅ Sí | string | Código de cliente (ej. `"000001"`) → `singapur_client_code` en BD |
| `company_folder_name` | ✅ Sí | string | Nombre de carpeta del expediente (ej. `"000001_NOVA CONSULTORA EMPRESARIAL"`) |
| `fields` | ✅ Sí | object | Datos del formulario chino (ver tabla siguiente) |
| `type` | No | string | Siempre `"company-registration"` — informativo |
| `document_group` | No | string | Siempre `"KYC"` — informativo |
| `created_at` | No | string (ISO 8601) | Timestamp de creación en el relay chino |
| `files` | No | array | Documentos KYC en base64. Si viene vacío o ausente, se crea el expediente sin documentos |

### Campos dentro de `fields`

| Campo | Obligatorio | Tipo | Descripción |
|---|---|---|---|
| `companyName` | ✅ Sí | string | Nombre propuesto de la empresa → primera `LegalName` (priority 1) |
| `companyType` | ✅ Sí | string | `"sa"` · `"srl"` · `"sapi"` |
| `shareholderCount` | ✅ Sí | string numérico | `"1"` o `"2"` — determina cuántos bloques de accionista se procesan |
| `naturalShareholderName{N}` | ✅ Sí | string | Nombre del accionista N en chino (ej. `"吴佳鑫"`) |
| `naturalMarried{N}` | ✅ Sí | string | `"yes"` o `"no"` — determina si llegan acta de matrimonio y pasaporte del cónyuge |
| `naturalSharePercentage{N}` | ✅ Sí | string numérico | Porcentaje de participación (ej. `"50"`) |
| `naturalNationality{N}` | No | string | Nacionalidad (ej. `"china"`) |
| `naturalOtherNationality{N}` | No | string | Segunda nacionalidad si aplica |
| `naturalShareholderEmail{N}` | No | string | Email del accionista (puede llegar como ciudad en lugar de email) |

---

## Documentos KYC — lo que China manda

Todo llega en **un solo paquete** desde el primer webhook (etapa `DATA_RECEIVED`). No hay entregas escalonadas.

### Documentos por accionista (persona natural)

| Campo `files[].field` | Condición | Tipo en Nexum | Equivalente en Tally |
|---|---|---|---|
| `naturalTaxCertificate{N}` | Siempre | `KYC_TAX_CERTIFICATE` | `CFI` |
| `naturalProofAddress{N}` | Siempre | `KYC_PROOF_OF_ADDRESS` | `ADDRESS` |
| `naturalPassport{N}` | Siempre (cuando disponible) | `PASSPORT` | `PASSPORT` |
| `naturalMarriageCertificate{N}` | Solo si `naturalMarried{N} = "yes"` | `KYC_MARRIAGE_CERTIFICATE` | `MARRIAGE_CERTIFICATE` |
| `naturalSpousePassport{N}` | Solo si `naturalMarried{N} = "yes"` | `KYC_SPOUSE_PASSPORT` | `ID_SPOUSE` |

> **Sobre `naturalPassport{N}`:** Este campo puede o no estar presente en el paquete dependiendo del cliente. No fue incluido en `000001_NOVA_CONSULTORA_EMPRESARIAL` pero Tally lo maneja y puede llegar en otros expedientes. Nexum lo procesa y almacena cuando llega.

> **`naturalTaxCertificate` NO es una CSF mexicana.** Es el documento de identificación fiscal chino (equivalente al RFC para personas físicas chinas). Tally lo almacenaba como `CFI` por proximidad conceptual. En Nexum se usa `KYC_TAX_CERTIFICATE` para evitar confusiones.

### Versiones de archivos en los ZIPs del relay

Algunos archivos llegan en dos versiones dentro del ZIP:

| Sufijo | Descripción |
|---|---|
| `_untranslated.pdf` | Documento original en chino |
| `_translated_review.pdf` | Versión con traducción al español para revisión del equipo |

Ambas versiones se almacenan como `Document` separados en la tabla.

### Ruta en R2 / MinIO

```
documents/{registration_id}/{field}_{relay_name}
```

Ejemplo:
```
documents/01JX4K.../naturalTaxCertificate1_000001__naturalTaxCertificate1__JIAXIN_WIU_TAX_ID_untranslated.pdf
```

---

## Documentos que genera Nexum (NO los manda China)

| Tipo en Nexum | Cuándo | Etapa del pipeline |
|---|---|---|
| `INCORPORATION_ACT` | Acta constitutiva firmada | `INCORPORATION` |
| `RFC_DOCUMENT` | Constancia de RFC del SAT | `SAT_REGISTRATION` |
| `CSF` | Constancia de Situación Fiscal del SAT | `SAT_REGISTRATION` |
| `EFIRMA` | Certificados .cer y .key | `EFIRMA_APPOINTMENT` |

---

## Mapeo de campos del relay → `DocumentTypeEnum`

El método `DocumentTypeEnum::fromRelayField(string $field)` centraliza este mapeo. `SingapurFileDTO::documentType()` lo invoca al procesar cada archivo del webhook.

```php
return match ($base) {
    'naturalTaxCertificate'      => self::KYC_TAX_CERTIFICATE,
    'naturalProofAddress'        => self::KYC_PROOF_OF_ADDRESS,
    'naturalPassport'            => self::PASSPORT,
    'naturalMarriageCertificate' => self::KYC_MARRIAGE_CERTIFICATE,
    'naturalSpousePassport'      => self::KYC_SPOUSE_PASSPORT,
    default                      => self::OTHER,
};
```

Campos desconocidos caen en `OTHER` y se almacenan para revisión manual del equipo.

---

## Variables de entorno

```env
SINGAPUR_WEBHOOK_SECRET=     # Compartido con China — autenticación HMAC
SINGAPUR_API_URL=            # Solo para SingapurRelayService (descarga bulk ZIP)
SINGAPUR_BEARER_TOKEN=       # Solo para SingapurRelayService (descarga bulk ZIP)
```

---

## Clases involucradas

| Clase | Ruta | Responsabilidad |
|---|---|---|
| `WebhookController` | `Http/Controllers/Api/V3/` | Valida HMAC, despacha el job |
| `ProcessSingapurWebhookJob` | `Jobs/` | Procesamiento asíncrono |
| `SingapurSubmissionDTO` | `DTOs/` | Deserializa el JSON completo |
| `SingapurFileDTO` | `DTOs/` | Archivo individual; resuelve `DocumentTypeEnum` vía `fromRelayField()` |
| `RegistrationUpsertService` | `Services/Registration/` | Crea/actualiza Registration, Shareholders, LegalName y Documents |
| `DocumentTypeEnum` | `Enums/` | Tipos de documento; incluye `fromRelayField()` y `isKyc()` |
