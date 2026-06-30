# Webhook Singapur → Nexum · Contrato de integración / Integration contract

> Documento para el equipo del relay (China / "Singapur"). Describe el **formato
> exacto** del `POST` que Nexum espera para crear un expediente.
> Document for the relay team (China / "Singapur"). Describes the **exact format**
> of the `POST` Nexum expects in order to create a registration.

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

### Campos obligatorios

**Nivel superior**

| Campo | Obligatorio | Si falta |
|-------|:-----------:|----------|
| `id` | ✅ | `422` inmediato. Es también la **llave de idempotencia**: no reenviar el mismo `id`. |
| `registration_number` | ✅ | El `POST` responde `202` pero el procesamiento **falla** en segundo plano. |
| `company_folder_name` | ✅ | Igual: falla en el job. |
| `fields` (objeto) | ✅ | Igual: falla en el job. |
| `files` | ➖ | Opcional (default `[]`). |
| `incorporation_deed` | ➖ | Opcional (acta en base64). |

**Dentro de `fields`**

| Campo | Obligatorio | Notas |
|-------|:-----------:|-------|
| `shareholderCount` | ✅ si hay socios | Entero. Define cuántos accionistas (1..N) se leen. |
| `companyName` | ⚠️ recomendado | Default `''`; sin él el expediente queda sin nombre. |
| `companyType` | ⚠️ recomendado | `sa`, `srl`, `sapi`, … |
| `_language` | ➖ | Default `zh`. |
| `companyObject`, `capitalSocial` | ➖ | Opcionales. |

**Por cada accionista `i` (de 1 a `shareholderCount`)** — el índice va pegado al final:

| Campo | Obligatorio |
|-------|:-----------:|
| `naturalShareholderName{i}` (o `juridicaShareholderName{i}`) | ✅ |
| `naturalSharePercentage{i}` | ✅ (número) |
| `naturalShareholderEmail{i}` | ⚠️ recomendado |
| `naturalNationality{i}` | ⚠️ recomendado |
| `shareholderType{i}` | ➖ (default `natural`) |
| `naturalMarried{i}` | ➖ (`"yes"` / `"no"`) |
| `naturalGender{i}`, `naturalBirthdate{i}`, `naturalBirthplace{i}`, `naturalCivilStatus{i}`, `naturalPhone{i}`, `naturalPhoneCountryCode{i}`, `naturalTaxId{i}` | ➖ |

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
| `422 Unprocessable Entity` | Falta `id`. |
| `409 Conflict` | Ese `id` ya se había recibido (idempotencia). |

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

**Top level**

| Field | Required | If missing |
|-------|:--------:|------------|
| `id` | ✅ | Immediate `422`. Also the **idempotency key**: never resend the same `id`. |
| `registration_number` | ✅ | `POST` returns `202` but background processing **fails**. |
| `company_folder_name` | ✅ | Same: job fails. |
| `fields` (object) | ✅ | Same: job fails. |
| `files` | ➖ | Optional (defaults to `[]`). |
| `incorporation_deed` | ➖ | Optional (base64 deed). |

**Inside `fields`**

| Field | Required | Notes |
|-------|:--------:|-------|
| `shareholderCount` | ✅ if any shareholders | Integer. How many shareholders (1..N) to read. |
| `companyName` | ⚠️ recommended | Defaults to `''`; without it the registration has no name. |
| `companyType` | ⚠️ recommended | `sa`, `srl`, `sapi`, … |
| `_language` | ➖ | Defaults to `zh`. |
| `companyObject`, `capitalSocial` | ➖ | Optional. |

**Per shareholder `i` (1..`shareholderCount`)** — the index is appended to the key:

| Field | Required |
|-------|:--------:|
| `naturalShareholderName{i}` (or `juridicaShareholderName{i}`) | ✅ |
| `naturalSharePercentage{i}` | ✅ (number) |
| `naturalShareholderEmail{i}` | ⚠️ recommended |
| `naturalNationality{i}` | ⚠️ recommended |
| `shareholderType{i}` | ➖ (defaults to `natural`) |
| `naturalMarried{i}` | ➖ (`"yes"` / `"no"`) |
| `naturalGender{i}`, `naturalBirthdate{i}`, `naturalBirthplace{i}`, `naturalCivilStatus{i}`, `naturalPhone{i}`, `naturalPhoneCountryCode{i}`, `naturalTaxId{i}` | ➖ |

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
| `422 Unprocessable Entity` | Missing `id`. |
| `409 Conflict` | That `id` was already received (idempotency). |

### `curl` example

```bash
curl -sS -X POST 'https://nexumcore.app/api/v3/webhook/singapur' \
  -H 'X-Nexum-Secret: <SINGAPUR_WEBHOOK_SECRET>' \
  -H 'Content-Type: application/json' \
  --data @submission.json
```
