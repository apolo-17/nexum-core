# Webhook Singapur → Nexum · Contrato de integración / Integration contract

> Documento para el equipo del relay (China / "Singapur"). Describe el **formato
> exacto** del `POST` que Nexum espera para crear un expediente.
> Document for the relay team (China / "Singapur"). Describes the **exact format**
> of the `POST` Nexum expects in order to create a registration.
>
> **Versión / Version: 2026-08-19**

---

## ⚠️ Cambios recientes (2026-08-19) · Recent changes

A partir de esta versión, Nexum **valida el contenido de negocio** de forma
síncrona. Un payload incompleto ya **NO** se acepta con `202`: se rechaza con
`422` y el relay debe reenviarlo corregido. Campos que **ahora son obligatorios**:

_As of this version, Nexum **validates the business content** synchronously. An
incomplete payload is **no longer** accepted with `202`; it is rejected with `422`
and the relay must resend it fixed. Fields that are **now mandatory**:_

- `fields.companyName`, `fields.companyType`
- `fields.companyObject` — *antes opcional / previously optional*
- `fields.capitalSocial` — *antes opcional / previously optional*
- `fields.shareholderCount` (≥ 1)
- **`fields.denominationPoolId`** — *nuevo / new*
- Por socio / per shareholder: `naturalShareholderName{i}`, `naturalSharePercentage{i}` (> 0),
  `naturalShareholderEmail{i}`, `naturalNationality{i}`

---

## 🇲🇽 Español

### Endpoint

```
POST https://nexumcore.app/api/v3/webhook/singapur
```

### Headers obligatorios

| Header | Valor |
|--------|-------|
| `X-Nexum-Secret` | El secreto compartido (`SINGAPUR_WEBHOOK_SECRET`). Sin él → `401`. |
| `Content-Type` | `application/json` |

### Cuerpo (JSON)

Los datos de la empresa y de los accionistas van **dentro de un objeto `fields`**,
con llaves **planas e indexadas por accionista** (`naturalShareholderName1`,
`naturalSharePercentage1`, …). **No** se usa un array `shareholders[]` ni
`company_name` / `company_type` de nivel superior.

```json
{
  "id": "uuid-del-paquete-000003",
  "registration_number": "000003",
  "company_folder_name": "000003_NOVA CONSULTORA EMPRESARIAL",
  "incorporation_deed": null,
  "fields": {
    "companyName": "NOVA CONSULTORÍA EMPRESARIAL",
    "companyType": "sa",
    "companyObject": "Servicios de consultoría empresarial.",
    "capitalSocial": 50000,
    "denominationPoolId": "pool-abc-123",
    "shareholderCount": 2,
    "_language": "zh",

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

### Campos obligatorios

Todos los campos ✅ se validan de forma **síncrona**: si falta alguno, la respuesta
es `422` con el detalle en `errors` y el expediente **no** se crea.

**Nivel superior**

| Campo | Obligatorio | Notas |
|-------|:-----------:|-------|
| `id` | ✅ | UUID del paquete. Es también la **llave de idempotencia**: no reenviar el mismo `id`. |
| `registration_number` | ✅ | |
| `company_folder_name` | ✅ | |
| `fields` (objeto) | ✅ | Bolsa de campos planos (ver abajo). |
| `files` | ➖ | Opcional (default `[]`). |
| `incorporation_deed` | ➖ | Opcional (acta pre-generada en base64). |

**Dentro de `fields` — datos de la empresa**

| Campo | Obligatorio | Notas |
|-------|:-----------:|-------|
| `companyName` | ✅ | Razón social base. |
| `companyType` | ✅ | `sa`, `srl` o `sapi`. |
| `companyObject` | ✅ | Objeto social. |
| `capitalSocial` | ✅ | Numérico. |
| `shareholderCount` | ✅ | Entero ≥ 1. Define cuántos accionistas (1..N) se leen. |
| `denominationPoolId` | ✅ | ID de la denominación asignada del pool de Nexum. |
| `_language` | ➖ | Default `zh`. |

**Por cada accionista `i` (de 1 a `shareholderCount`)** — el índice va pegado al final:

| Campo | Obligatorio | Notas |
|-------|:-----------:|-------|
| `naturalShareholderName{i}` | ✅ | Acepta también `naturalShareholderNameEs{i}` o `juridicaShareholderName{i}`. |
| `naturalSharePercentage{i}` | ✅ | Número **mayor que 0**. |
| `naturalShareholderEmail{i}` | ✅ | Correo — **obligatorio** (se usa para la firma electrónica DocuSign). |
| `naturalNationality{i}` | ✅ | Acepta también `naturalOtherNationality{i}`. |
| `shareholderType{i}` | ➖ | Default `natural`. |
| `naturalMarried{i}` | ➖ | `"yes"` / `"no"`. |
| `naturalGender{i}`, `naturalDateOfBirth{i}`, `naturalPlaceOfBirth{i}`, `naturalCivilStatus{i}`, `naturalPhone{i}`, `naturalPhoneCountryCode{i}`, `naturalTaxId{i}`, `naturalPassportNumber{i}` | ➖ | Opcionales — Nexum extrae estos datos de los documentos con IA. |

**Cada entrada de `files[]`** (si se envían archivos)

| Campo | Obligatorio | Notas |
|-------|:-----------:|-------|
| `field` | ✅ | Nombre del campo, p.ej. `naturalTaxCertificate1` (el número al final = índice del socio). |
| `original_name` | ✅ | Nombre original del archivo. |
| `relay_name` | ✅ | Etiqueta legible. |
| `content_type` | ✅ | MIME, p.ej. `application/pdf`. |
| `size` | ✅ | Tamaño en bytes. |
| `content` | ⚠️ | Base64 del archivo. Sin él, el archivo **no** se almacena. |

### Respuestas

| Código | Significado |
|--------|-------------|
| `202 Accepted` | Recibido y encolado para procesar. |
| `401 Unauthorized` | Falta o no coincide `X-Nexum-Secret`. |
| `409 Conflict` | Ese `id` ya se había recibido (idempotencia). |
| `422 Unprocessable Entity` | Falta algún campo obligatorio. El detalle viene en `errors`. |

Ejemplo de cuerpo `422`:

```json
{
  "message": "Falta denominationPoolId (denominación asignada del pool). (and 1 more errors)",
  "errors": {
    "fields.denominationPoolId": ["Falta denominationPoolId (denominación asignada del pool)."],
    "fields": ["El socio 2 no tiene correo (naturalShareholderEmail2); es obligatorio para la firma."]
  }
}
```

### Ejemplo `curl`

```bash
curl -sS -X POST 'https://nexumcore.app/api/v3/webhook/singapur' \
  -H 'X-Nexum-Secret: <SINGAPUR_WEBHOOK_SECRET>' \
  -H 'Content-Type: application/json' \
  --data @submission.json
```

---

## 🇬🇧 English

### Endpoint

```
POST https://nexumcore.app/api/v3/webhook/singapur
```

### Required headers

| Header | Value |
|--------|-------|
| `X-Nexum-Secret` | The shared secret (`SINGAPUR_WEBHOOK_SECRET`). Missing → `401`. |
| `Content-Type` | `application/json` |

### Body (JSON)

Company and shareholder data live **inside a `fields` object**, using **flat,
per-shareholder indexed keys** (`naturalShareholderName1`, `naturalSharePercentage1`,
…). There is **no** `shareholders[]` array and **no** top-level `company_name` /
`company_type`. See the JSON example in the Spanish section above (identical).

### Required fields

All ✅ fields are validated **synchronously**: if any is missing the response is
`422` with the detail in `errors`, and the registration is **not** created.

**Top level**

| Field | Required | Notes |
|-------|:--------:|-------|
| `id` | ✅ | Package UUID. Also the **idempotency key**: never resend the same `id`. |
| `registration_number` | ✅ | |
| `company_folder_name` | ✅ | |
| `fields` (object) | ✅ | Flat field bag (see below). |
| `files` | ➖ | Optional (defaults to `[]`). |
| `incorporation_deed` | ➖ | Optional (base64 pre-rendered deed). |

**Inside `fields` — company data**

| Field | Required | Notes |
|-------|:--------:|-------|
| `companyName` | ✅ | Base company name. |
| `companyType` | ✅ | `sa`, `srl` or `sapi`. |
| `companyObject` | ✅ | Corporate purpose. |
| `capitalSocial` | ✅ | Numeric. |
| `shareholderCount` | ✅ | Integer ≥ 1. How many shareholders (1..N) to read. |
| `denominationPoolId` | ✅ | ID of the denomination assigned from Nexum's pool. |
| `_language` | ➖ | Defaults to `zh`. |

**Per shareholder `i` (1..`shareholderCount`)** — the index is appended to the key:

| Field | Required | Notes |
|-------|:--------:|-------|
| `naturalShareholderName{i}` | ✅ | Also accepts `naturalShareholderNameEs{i}` or `juridicaShareholderName{i}`. |
| `naturalSharePercentage{i}` | ✅ | Number **greater than 0**. |
| `naturalShareholderEmail{i}` | ✅ | Email — **mandatory** (used for the DocuSign e-signature). |
| `naturalNationality{i}` | ✅ | Also accepts `naturalOtherNationality{i}`. |
| `shareholderType{i}` | ➖ | Defaults to `natural`. |
| `naturalMarried{i}` | ➖ | `"yes"` / `"no"`. |
| `naturalGender{i}`, `naturalDateOfBirth{i}`, `naturalPlaceOfBirth{i}`, `naturalCivilStatus{i}`, `naturalPhone{i}`, `naturalPhoneCountryCode{i}`, `naturalTaxId{i}`, `naturalPassportNumber{i}` | ➖ | Optional — Nexum extracts these from the documents via AI. |

**Each `files[]` entry** (when files are sent)

| Field | Required | Notes |
|-------|:--------:|-------|
| `field` | ✅ | Field name, e.g. `naturalTaxCertificate1` (trailing number = shareholder index). |
| `original_name` | ✅ | Original file name. |
| `relay_name` | ✅ | Human-readable label. |
| `content_type` | ✅ | MIME, e.g. `application/pdf`. |
| `size` | ✅ | Size in bytes. |
| `content` | ⚠️ | Base64 of the file. Without it the file is **not** stored. |

### Responses

| Code | Meaning |
|------|---------|
| `202 Accepted` | Received and queued for processing. |
| `401 Unauthorized` | Missing/incorrect `X-Nexum-Secret`. |
| `409 Conflict` | That `id` was already received (idempotency). |
| `422 Unprocessable Entity` | A required field is missing. Detail is in `errors`. |

Example `422` body:

```json
{
  "message": "Falta denominationPoolId (denominación asignada del pool). (and 1 more errors)",
  "errors": {
    "fields.denominationPoolId": ["Falta denominationPoolId (denominación asignada del pool)."],
    "fields": ["El socio 2 no tiene correo (naturalShareholderEmail2); es obligatorio para la firma."]
  }
}
```

### `curl` example

```bash
curl -sS -X POST 'https://nexumcore.app/api/v3/webhook/singapur' \
  -H 'X-Nexum-Secret: <SINGAPUR_WEBHOOK_SECRET>' \
  -H 'Content-Type: application/json' \
  --data @submission.json
```
