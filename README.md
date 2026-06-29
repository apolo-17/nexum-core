# Nexum Core

Backend Laravel de **Backend Bridge Incorporation**: gestiona la constitución de
empresas mexicanas para clientes chinos. La guía completa para desarrollo y agentes
está en [`CLAUDE.md`](CLAUDE.md).

---

## Módulo Soldados (jun 2026)

Un **soldado** es una persona física mexicana contratada que puede servir como
**operador MUA** (presta su FIEL para denominaciones) y/o **representante legal /
comisario** en el acta. Una sola persona, modelada una vez; las funciones son
capacidades (flags) y la FIEL se guarda una vez y se reutiliza.

Se construyó en 6 fases. Cada fase es un commit.

| Fase | Qué incluye | Commit |
|------|-------------|--------|
| 0 | **Credenciales de la empresa**: subir/resguardar FIEL (.cer/.key, contraseña cifrada reversible) y RFC de la empresa, con descarga segura | `7d433d5` |
| 1 | **Entidad `Soldado`** + rol Spatie `soldado` + invitación al panel (login opcional con email de bienvenida) | `f1dea9f` |
| 2 | **Consolidación**: se unifican `MuaAccount` y `LegalAgent` en `Soldado` (migración de datos con dedupe por RFC); todo el pipeline MUA y el acta apuntan a `soldado_id` | `41dbebc` |
| 3 | **Citas SAT**: tabla `appointments` (RFC y FIEL por empresa), captura manual + descarga de acuse | `af58ef2` |
| 4 | **Dashboard del soldado** (recursos *scoped*: Mis citas, Mis empresas) + **KPIs** para super_admin | `d266533` |
| 5 | **Bot de citas SAT (lado Laravel)**: endpoints `pending` + `callback` para el servicio externo | `cb4ff48` |

### Entidades nuevas

- **`Soldado`** (`soldados`): identidad (nombre, tel, correo, RFC, CURP, INE anverso/reverso),
  capacidades (`available_for_mua`, `available_as_legal_representative`, `available_as_commissary`),
  `user_id` opcional (acceso al panel), soft delete = baja.
- **`SoldadoCredential`**: FIEL cifrada (espejo de `MuaCredential`).
- **`Appointment`** (`appointments`): cita SAT por empresa, `type` = `rfc` | `fiel`,
  estado = `EfirmaAppointmentStatusEnum`, soldado que asiste, acuse en R2.
- **`AppointmentEmail`** (`appointment_emails`): pool de correos que usa el bot del SAT.
- Pivote **`registration_soldado`** (rol + % de participación en el acta).
- Columnas en `registrations`: `company_fiel_cer_path`, `company_fiel_key_path`,
  `company_fiel_password` (cifrado), `company_rfc_path`.
- Columna `legal_names.soldado_id` (reemplaza `mua_account_id`, conservado un release).

> **Deprecados** (tablas/recursos conservados un release, ocultos del menú):
> `MuaAccountResource` y `LegalAgentResource`. La fuente de verdad es `Soldado`.

### Dashboard / acceso

- **super_admin**: gestiona soldados (`SoldadoResource`), pool de correos
  (`AppointmentEmailResource`) y ve KPIs por soldado.
- **soldado** (rol nuevo, login opcional): panel *scoped* "Mi panel" — sus citas
  (RFC/FIEL, completas vs pendientes) y sus empresas. No ve expedientes ajenos.

### Integración con el bot de citas del SAT

El bot es un **servicio externo** (`nexum-citas-sat`, repo aparte). Nexum expone, como
con el bot MUA:

| Método | Ruta | Auth |
|--------|------|------|
| GET  | `/api/v3/sat-bot/pending`  | `X-Bot-Api-Key` |
| POST | `/api/v3/webhook/sat-bot`  | HMAC `X-Signature` |

- `pending`: el bot jala las citas por agendar; Nexum le asigna un correo del pool.
- `callback`: el bot reporta la cita agendada (fecha, sede, acuse) → Nexum rellena la
  cita, guarda el acuse en R2, libera el correo y **notifica al soldado** (correo branded
  + campana; hook para WhatsApp/SMS).

Contrato e implementación del bot: ver el repo `nexum-citas-sat` (`docs/CONTRACT.md`).

### Variables de entorno nuevas

```env
# Bot de citas del SAT (compartidas con el repo nexum-citas-sat)
SAT_BOT_API_KEY=        # openssl rand -hex 32
SAT_BOT_SECRET_KEY=     # openssl rand -hex 32
```

### Pendientes antes de operar en producción

- [ ] Correr `php artisan migrate` (con Sail/DB arriba) y `db:seed --class=RolesAndPermissionsSeeder` (rol `soldado`).
- [ ] Validar la migración de consolidación (`2026_06_28_000006`) contra un snapshot de prod.
- [ ] Generar y compartir `SAT_BOT_API_KEY` / `SAT_BOT_SECRET_KEY` con el bot.
- [ ] Cargar el **pool de correos** (Configuración → Pool de correos).
- [ ] Conectar un proveedor de **WhatsApp/SMS** en `SatAppointmentScheduledNotification`
      (recomendado: WhatsApp Cloud API de Meta para bajo volumen).

---

## Pruebas

```bash
php artisan test                  # SQLite en memoria
```

> Nota: ~25 tests dependen de MinIO/S3 (almacenamiento) y fallan si no está levantado;
> no están relacionados con el módulo Soldados.
