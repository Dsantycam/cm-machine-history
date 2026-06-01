# CM Machine History — Plan maestro del proyecto

**Versión actual:** 0.8.4  
**Autor:** Santiago Camacho — santiagocamachomkt.com  
**Repositorio:** https://github.com/Dsantycam/cm-machine-history  
**Última actualización de este documento:** 2026-06-01

---

## 1. Objetivo general

Construir un **CMMS (Computerized Maintenance Management System)** completo dentro de WordPress para gestionar el historial de mantenimiento de maquinaria industrial, principalmente montacargas.

El sistema debe:
- Registrar empresas, ciudades, sucursales y máquinas en una jerarquía clara
- Llevar hoja de vida técnica completa por máquina
- Calcular indicadores operativos reales (disponibilidad, MTTR, criticidad)
- Integrarse automáticamente con los formularios Forminator que los técnicos ya usan
- Asociar los PDFs generados por E2PDF a cada intervención sin intervención manual
- Ser escalable, actualizable y potencialmente comercializable como SaaS

---

## 2. Stack tecnológico

| Componente | Versión | Rol |
|---|---|---|
| WordPress | cualquiera moderna | CMS base |
| PHP | 7.4+ | Lenguaje del plugin |
| Forminator Pro | **1.37.1** | Formularios que llenan los técnicos |
| E2PDF Pro | **1.28.05** | Generación de PDFs de los formularios |
| Plugin Update Checker | 5.7 | Auto-actualizaciones desde GitHub |

**IMPORTANTE:** No actualizar Forminator ni E2PDF sin pruebas previas. Las versiones están fijas por compatibilidad probada.

---

## 3. Estructura de archivos

```
cm-machine-history/
├── cm-machine-history.php          ← Bootstrap: constantes, requires, activation hook, PUC
├── uninstall.php                   ← Limpieza al desinstalar: borra tablas y opciones
├── PLAN.md                         ← Este archivo
├── .gitignore                      ← Excluye *.xlsx, *.pdf, desktop.ini, .DS_Store
├── includes/
│   ├── class-cmh-core.php          ← BD: tablas, activate(), maybe_upgrade(), log()
│   ├── class-cmh-metrics.php       ← KPIs: availability(), mttr(), is_critical(), etc.
│   ├── class-cmh-integration.php   ← Forminator + E2PDF: captura, parseo, PDF finder
│   └── class-cmh-admin.php         ← Toda la UI: páginas, formularios, CRUD, export CSV
├── assets/
│   ├── admin.css                   ← Estilos del admin (design system con CSS variables)
│   ├── admin.js                    ← Tabs, validación horómetro, interacciones UI
│   └── frontend.js                 ← Autocompletado en formularios Forminator (frontend)
└── lib/
    └── plugin-update-checker/      ← Librería PUC v5.7 (debe existir para auto-updates)
        └── load-v5p7.php           ← Entry point (versión 5.7 específicamente)
```

### Constantes globales (definidas en cm-machine-history.php)

```php
CMH_VERSION  // '0.8.4' — debe coincidir EXACTAMENTE en header del plugin Y en esta constante
CMH_SLUG     // 'cm-machine-history'
CMH_DIR      // ruta absoluta del directorio del plugin (con trailing slash)
CMH_URL      // URL del directorio del plugin (con trailing slash)
```

---

## 4. Base de datos

Todas las tablas usan el prefijo de WordPress + `cmh_`. Ejemplo: `wp_cmh_companies`.

El mapa de tablas se obtiene con `CMH_Core::tables()` que retorna un array asociativo con las claves: `companies`, `cities`, `branches`, `machines`, `interventions`, `files`, `logs`.

### 4.1 `cmh_companies`
| Campo | Tipo | Descripción |
|---|---|---|
| id | BIGINT UNSIGNED PK | Auto-incremental |
| name | VARCHAR(190) | Nombre completo de la empresa |
| code | VARCHAR(20) UNIQUE | Código corto en mayúsculas, ej: `APC` |
| created_at | DATETIME | Timestamp de creación |

### 4.2 `cmh_cities`
| Campo | Tipo | Descripción |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| company_id | BIGINT UNSIGNED FK | Referencia a companies |
| name | VARCHAR(190) | Nombre de la ciudad |
| code | VARCHAR(20) | Código corto, ej: `BOG` |
| created_at | DATETIME | |

### 4.3 `cmh_branches`
| Campo | Tipo | Descripción |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| company_id | BIGINT UNSIGNED FK | |
| city_id | BIGINT UNSIGNED FK | |
| name | VARCHAR(190) | Nombre de la sucursal/bodega |
| code | VARCHAR(20) | Código corto, ej: `FAC` |
| address | TEXT NULL | Dirección física |
| created_at | DATETIME | |

### 4.4 `cmh_machines` ← tabla central
| Campo | Tipo | Descripción |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| company_id | BIGINT UNSIGNED FK | |
| city_id | BIGINT UNSIGNED FK | |
| **branch_id** | BIGINT UNSIGNED **NULL** | Sucursal opcional — puede ser NULL |
| machine_code | VARCHAR(80) UNIQUE | Código generado: `APC-BOG-TY-001` |
| brand | VARCHAR(120) | Marca legible: `Toyota` |
| brand_code | VARCHAR(20) | Código de marca: `TY` |
| model | VARCHAR(120) NULL | Modelo: `8FGU25` |
| serial | VARCHAR(120) NULL | Serial del fabricante |
| contact | VARCHAR(190) NULL | Persona de contacto |
| current_hourmeter | DECIMAL(12,2) | Horómetro actual (se actualiza con intervenciones) |
| **scheduled_hours_monthly** | DECIMAL(10,2) DEFAULT 480 | ← CLAVE para disponibilidad: horas de turno programadas al mes |
| status | VARCHAR(40) DEFAULT 'activa' | Estados: `activa`, `mantenimiento`, `inactiva`, `fuera_servicio` |
| notes | TEXT NULL | Notas libres |
| created_at / updated_at | DATETIME | |

**`branch_id` es NULL:** las máquinas pueden existir directamente bajo una ciudad sin pertenecer a una sucursal. Esto se migró en v0.7 con `ALTER TABLE ... MODIFY COLUMN branch_id BIGINT UNSIGNED NULL`.

**`scheduled_hours_monthly`:** fue añadido en v0.7. Si la instalación era previa a v0.7, se agrega con `ALTER TABLE ... ADD COLUMN`. El default es 480 h/mes (1 turno de 8h x 5 días x 4 semanas). Algunas máquinas tienen 720 h/mes (turnos más largos). Este campo es la **base de cálculo de disponibilidad**.

### 4.5 `cmh_interventions` ← tabla de hechos principal
| Campo | Tipo | Descripción |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| machine_id | BIGINT UNSIGNED FK | |
| forminator_form_id | BIGINT UNSIGNED NULL | ID del form Forminator si vino de allí |
| e2pdf_entry_id | VARCHAR(120) NULL | Clave de dedup: hash del envío de Forminator |
| intervention_date | DATE | Fecha de la intervención |
| form_type | VARCHAR(80) | `combustion`, `electricos`, `correctivo`, `manual` |
| maintenance_type | VARCHAR(80) | `preventivo`, `correctivo`, `averia`, `evaluacion` |
| technician | VARCHAR(190) NULL | Nombre del técnico |
| hourmeter | DECIMAL(12,2) | Horómetro en el momento de la intervención |
| worked_hours | DECIMAL(10,2) | Horas que el técnico trabajó |
| downtime_hours | DECIMAL(10,2) | Horas que la máquina estuvo parada |
| cost | DECIMAL(14,2) | Costo de la intervención |
| **affects_availability** | TINYINT(1) DEFAULT 0 | ← CLAVE: 1 = descuenta disponibilidad |
| failure_system | VARCHAR(190) NULL | Sistema fallado: `frenos`, `traccion`, etc. |
| parts | TEXT NULL | Repuestos/insumos usados |
| services | TEXT NULL | Servicios prestados |
| observations | TEXT NULL | Observaciones libres |
| created_at | DATETIME | |

**`affects_availability`:** es el campo más crítico del sistema. Determina si una intervención descuenta del tiempo disponible. Se asigna automáticamente:
- `averia` → siempre 1 (la máquina estaba varada)
- `preventivo` → siempre 0 (es mantenimiento planificado)
- `evaluacion` → siempre 0
- `correctivo` → 1 por defecto, el usuario puede cambiar a 0

Esta lógica está en `CMH_Metrics::auto_affects_availability()`.

### 4.6 `cmh_files`
| Campo | Tipo | Descripción |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| machine_id | BIGINT UNSIGNED FK | |
| intervention_id | BIGINT UNSIGNED NULL | Puede ser NULL si se sube directamente |
| file_url | TEXT | URL pública del archivo |
| file_path | TEXT NULL | Ruta absoluta en servidor |
| file_name | VARCHAR(255) | Nombre del archivo |
| file_type | VARCHAR(80) NULL | MIME type |
| uploaded_by | BIGINT UNSIGNED NULL | ID del usuario WP que subió |
| created_at | DATETIME | |

Los archivos subidos manualmente van a `uploads/cm-machine-history/{machine_code}/`.
Los PDFs de E2PDF vienen de `uploads/e2pdf/` y se buscan/asocian automáticamente.

### 4.7 `cmh_logs`
Tabla de auditoría de la integración Forminator/E2PDF. Cada evento del proceso automático se registra aquí con niveles `info`, `success`, `warning`, `error`.

---

## 5. Jerarquía de datos y navegación

```
Empresa (cmh_companies)
  └── Ciudad/Sucursal (cmh_cities)   ← nivel único, nombre libre ("BOGOTÁ", "BODEGA NORTE", etc.)
        └── Máquina (cmh_machines)
              └── Intervenciones (cmh_interventions)
                    └── Archivos PDF (cmh_files)
```

La tabla `cmh_branches` sigue existiendo en BD pero **la UI de sucursales fue eliminada** a partir de v0.8.1. El concepto de "ciudad" y "sucursal" se fusionó en un único nivel "Ciudad/Sucursal" donde el usuario escribe lo que necesite. Las máquinas existentes con `branch_id` no se ven afectadas.

La navegación del plugin sigue esta jerarquía:
`Empresas → [Empresa] → [Ciudad/Sucursal] → [Máquina] → Hoja de vida`

---

## 6. Identificador de máquinas

### Formato actual (desde v0.8.1)
```
EMPRESA CIUDAD MARCA No.VARIABLE
Ejemplo: APC BOG TY No.001
```
- `APC` = código de la empresa (mayúsculas, alfanumérico)
- `BOG` = código de la ciudad (mayúsculas, alfanumérico)
- `TY` = código de la marca (Toyota → TY)
- `No.` = texto literal fijo (N mayúscula, o minúscula, punto)
- `001` = identificador manual ingresado por el usuario al crear la máquina (puede ser número o alfanumérico: `001`, `A1`, `LINEA2`, etc.)

**Cambios respecto al formato anterior:**
- Separador cambiado de `-` (guión) a ` ` (espacio)
- Consecutivo automático eliminado → ahora el usuario escribe el identificador
- El código puede editarse posteriormente desde el tab "Editar" de la hoja de vida (valida unicidad)

### Formatos históricos — ya no se usan para máquinas nuevas
| Versión | Formato | Ejemplo |
|---|---|---|
| v0.7 | EMPRESA-CIUDAD-MARCA-NNN | APC-BOG-TY-001 |
| anterior a v0.7 | EMPRESA-CIUDAD-SUCURSAL-MARCA-NNN | APC-BOG-FAC-TY-001 |

**IMPORTANTE:** Las máquinas existentes NO se migran. Conservan su código original para no romper PDFs generados ni referencias existentes.

### Mapa de códigos de marca
```
Toyota → TY    Crown → CR      Hyster → HY     Hangcha → HC
Yale → YA      Linde → LD      Komatsu → KM    Nissan → NS
Caterpillar → CAT    Mitsubishi → MI    Still → ST    Jungheinrich → JH
```
Si la marca no está en el mapa, se toman los primeros 3 caracteres del código limpio (sin acentos, mayúsculas, sin espacios).

---

## 7. Fórmulas de KPIs

Estas fórmulas fueron validadas contra la plantilla real "INDICADORES INHOUSE APC 2025.xlsx" usada por el cliente.

### 7.1 Disponibilidad mensual

```
OPERACIÓN REAL = Horas programadas - Horas parada por AVERÍAS
DISPONIBILIDAD = OPERACIÓN REAL / Horas programadas × 100
```

**Regla crítica:** Solo las intervenciones con `affects_availability = 1` descuentan. El mantenimiento preventivo NO descuenta disponibilidad, solo las averías (máquina varada).

**Base de cálculo:** `scheduled_hours_monthly` de la máquina (campo configurable por máquina, no un cálculo dinámico). Algunos equipos tienen 480 h/mes, otros 720 h/mes.

**Límites:** siempre entre 0% y 100%. No puede ser negativa.

**Si no hay horas programadas** (scheduled_hours_monthly = 0): retorna `null` (se muestra "N/A").

**Ejemplo real del Excel:**
- Toyota 11: 480 h programadas, 125 h varada por averías → disponibilidad = (480-125)/480 = 74%
- Toyota 9: 720 h programadas, 2 h averías → disponibilidad = (720-2)/720 = 99.7% ≈ 100%

### 7.2 MTTR (Mean Time To Repair)

```
MTTR = Suma de horas parada de AVERÍAS / Cantidad de AVERÍAS
```

Solo cuenta intervenciones con `affects_availability = 1`. Si no hay averías, retorna `null`.

**Ejemplo:** 198 h de parada total / 15 averías = 13.20 h/avería (dato real del Excel)

### 7.3 Disponibilidad de flota

Para el dashboard global: suma de `scheduled_hours_monthly` de TODAS las máquinas como base, contra la suma de downtime de averías en el mes actual.

### 7.4 Máquina crítica

Una máquina se marca como crítica si, en el **mes actual**:
- Disponibilidad < 70%, **O**
- 3 o más averías registradas

Umbral de 70% y 3 averías definido junto al cliente en base a los datos del Excel.

---

## 8. Integración Forminator + E2PDF

### 8.1 Flujo completo (automático)

```
1. Técnico llena formulario Forminator en el sitio web
2. Forminator procesa el envío
3. Plugin intercepta hook 'forminator_form_after_handle_submit'
   (también 'forminator_form_after_save_entry' para compatibilidad con Forminator 1.37.x)
4. Plugin extrae el código de máquina del campo definido en la config
5. Plugin busca la máquina en cmh_machines por machine_code
6. Plugin inserta la intervención en cmh_interventions
7. Plugin actualiza current_hourmeter en cmh_machines (si horómetro > anterior)
8. Plugin llama a find_e2pdf_pdf() inmediatamente
9. find_e2pdf_pdf() busca el PDF más reciente en uploads/e2pdf (últimos 15 min)
10. Si lo encuentra: inserta en cmh_files y lo asocia a la intervención
11. Si NO lo encuentra: programa WP-Cron para reintentarlo en 90 segundos
12. PDF aparece en la hoja de vida de la máquina
```

### 8.2 Formularios configurados

```php
215 => [
    'form_type'        => 'combustion',
    'maintenance_type' => 'preventivo',
    'machine_field'    => 'text-14',       // campo código de máquina
    'hourmeter_field'  => 'number-1',
    'date_field'       => 'date-1',
    'technician_field' => 'name-2',
    'remission_field'  => 'hidden-1',
    'contact_field'    => 'text-12',
    'observations_field' => 'textarea-1',
]
225 => [ /* Eléctricos — estructura idéntica a 215 */ ]
226 => [
    'form_type'              => 'correctivo',
    'maintenance_type'       => 'preventivo',     // fallback si checkbox viene vacío
    'maintenance_type_field' => 'checkbox-1',     // ← "tipo de mantenimiento"
    'maintenance_type_map'   => [                 // orden = prioridad (correctivo primero)
        'correctivo'  => 'averia',
        'evaluacion'  => 'evaluacion',
        'remision'    => 'preventivo',
        'preventivo'  => 'preventivo',
    ],
    'machine_field'          => 'text-6',
    'hourmeter_field'        => 'text-5',
    'contact_field'          => 'text-4',
    'worked_hours_field'     => 'number-1',
    'downtime_hours_field'   => 'number-2',       // ← "horas detenida la máquina"
    'parts_field'            => 'textarea-1',
    'services_field'         => 'textarea-2',
    'observations_field'     => 'textarea-3',
    ...
]
```

**Reglas del form 226:**
- Si `checkbox-1` contiene "correctivo" → intervención tipo `averia`, `affects_availability = 1`, `downtime_hours` desde `number-2`
- Si contiene "preventivo", "evaluacion" o "remision" → sus tipos correspondientes
- Si hay múltiples seleccionados y uno es "correctivo", siempre gana avería (mayor prioridad)

**IMPORTANTE:** NO modificar los formularios Forminator. El plugin se adapta al formulario, no al revés.

### 8.3 Deduplicación de envíos

Cada envío genera una clave única: `'f' + form_id + '-' + md5(machine_code + '|' + remission + '|' + json(data))`.

Esta clave se guarda en `e2pdf_entry_id` y se verifica antes de insertar. Si ya existe, se ignora el envío (evita duplicados por el doble hook de Forminator 1.37.x).

**Fix v0.8.4:** `forminator_form_after_handle_submit` disparaba incluso en envíos fallidos (errores de validación). Ahora se verifica `$response['success'] === true` antes de procesar. `forminator_form_after_save_entry` solo dispara en éxito real, no requiere verificación adicional.

### 8.4 Lógica de búsqueda y almacenamiento de PDFs (E2PDF)

E2PDF guarda sus PDFs en `uploads/e2pdf/`. El plugin escanea recursivamente esa carpeta buscando archivos `.pdf` modificados en los últimos 15 minutos. Si hay varios PDFs recientes, prioriza los que contienen el código de máquina en la ruta o nombre (suma 1.000.000.000 al score de mtime).

**Almacenamiento permanente (desde v0.8.1):** cuando se encuentra un PDF de E2PDF, se copia inmediatamente a `uploads/cm-machine-history/{sanitized_machine_code}/`. Se guarda la URL de la copia propia, no la de E2PDF. Si E2PDF limpia sus archivos temporales, nuestra copia persiste. Si la copia falla (permisos), se usa la URL de E2PDF como fallback.

Los archivos subidos manualmente también van a `uploads/cm-machine-history/{machine_code}/`. El filtro `upload_dir` se remueve con `remove_filter()` inmediatamente después de cada upload para no afectar otras operaciones.

**NO hay webhook ni integración directa con E2PDF.** El mecanismo es búsqueda por tiempo de modificación de archivo.

### 8.5 Autocompletado en formularios Forminator (frontend.js)

Al cargar cualquier página del frontend, `frontend.js` escucha cambios en los campos de código de máquina (`text-14` para forms 215/225, `text-6` para form 226). Cuando el técnico escribe un código (mín. 3 caracteres), hace AJAX a `wp_ajax_nopriv_cmh_get_machine` y:
- Muestra indicador verde con marca, modelo, empresa y ciudad
- Rellena automáticamente campos cuya etiqueta contenga: "marca", "modelo", "serial", "contacto", "horómetro", "empresa", "ciudad" (comparación sin acentos, case-insensitive)
- Rellena el `contact_field` configurado como respaldo explícito

El endpoint `cmh_get_machine` es público (`nopriv`) para que funcione sin login.

---

## 9. Clases y su responsabilidad

### `CMH_Core` (`includes/class-cmh-core.php`)
- `tables()` → retorna mapa de nombres de tablas con prefijo WP
- `activate()` → crea/actualiza tablas con `dbDelta` (idempotente, seguro de llamar N veces)
- `run_migrations()` → migraciones específicas de versión (ALTER TABLE cuando dbDelta no puede)
- `maybe_upgrade()` → hook en `admin_init`, compara versión instalada con CMH_VERSION y llama `activate()` si difieren. También lee el option legacy `cmh_machine_history_version`
- `log()` → inserta en cmh_logs

### `CMH_Metrics` (`includes/class-cmh-metrics.php`)
- `availability($machine_id, $month, $year)` → disponibilidad % o null
- `monthly_breakdown($machine_id, $months)` → array de últimos N meses con todos los KPIs
- `fleet_availability($month, $year)` → disponibilidad de flota para el dashboard
- `mttr($machine_id, $month, $year)` → MTTR en horas o null
- `is_critical($machine_id)` → bool
- `critical_machines()` → array de máquinas críticas con sus métricas
- `averia_count($machine_id, $month, $year)` → int
- `auto_affects_availability($maintenance_type, $manual_value)` → 0 o 1
- `fmt_pct($pct)` / `fmt_mttr($hours)` → formateo para UI

### `CMH_Integration` (`includes/class-cmh-integration.php`)
- `init()` → registra hooks de Forminator y WP-Cron
- `config()` → array de configuración de formularios
- `capture_submit()` → handler principal del envío (hook en ambos events de Forminator)
- `find_pdf($intervention_id, $machine_id, $machine_code)` → busca y asocia PDF de E2PDF
- `latest_pdf($base, $machine_code)` → algoritmo de selección del mejor PDF
- Helpers de parseo: `extract_form_id()`, `flatten_submission_data()`, `field()`, `human()`, `to_float()`, `normalize_date()`

### `CMH_Admin` (`includes/class-cmh-admin.php`)
- Menú de administración: Dashboard, Empresas, Buscar máquinas, Integración
- Páginas: `page_dashboard()`, `page_companies()`, `page_company()`, `page_city()`, `page_machines()`, `page_machine()`, `page_integration()`
- Formularios: `machine_form()`, `edit_machine_form()`, `intervention_form()`, `upload_form()`
- Tablas UI: `machines_table()`, `interventions_table()`, `intervention_cards()`, `availability_table()`, `files_table()`
- CRUD: `save_company/city/machine/intervention()`, `update_machine()`, `upload_file()`, `edit_intervention()`
- V0.8: `export_csv()` y métodos privados para cada tipo de CSV
- AJAX admin: `ajax_get_machine()` (requiere `read`) y `ajax_get_machine_public()` (sin login, para frontend)

---

## 10. Roadmap completo

### ✅ v0.1 — v0.5 (histórico, antes del proyecto actual)
- Estructura básica del plugin
- CRUD de empresas, ciudades, sucursales, máquinas
- Integración inicial con Forminator
- Dashboard básico
- Corrección de bugs: integración no disparaba, ZIP mal empaquetado, dashboard rompía, hoja de vida rompía por badge de estado

### ✅ v0.6.0
- KPIs básicos en dashboard
- Timeline de intervenciones
- Búsqueda global de máquinas con filtros
- UX de dashboard inicial
- Logs de integración
- Breadcrumbs de navegación

### ✅ v0.7.0 — Refactorización completa + corrección de KPIs
- **Refactorización:** de 1 archivo monolítico a 4 clases (Core, Metrics, Integration, Admin)
- **Fix crítico disponibilidad:** ahora solo averías (`affects_availability=1`) descuentan. Antes usaba todo `downtime_hours`
- **Fix crítico MTTR:** ahora solo divide entre averías. Antes dividía entre todos los correctivos
- **`scheduled_hours_monthly`:** campo nuevo en máquinas, configurable por equipo (default 480)
- **Tabla mensual de disponibilidad** en hoja de vida (equivalente al Excel INDICADORES INHOUSE)
- **Máquinas críticas** en dashboard (disponibilidad < 70% o 3+ averías/mes)
- **Auto-set `affects_availability`** según tipo de mantenimiento
- **Cambio de código de máquina:** de EMPRESA-CIUDAD-SUCURSAL-MARCA-NNN a EMPRESA-CIUDAD-MARCA-NNN
- **Sucursal opcional:** `branch_id` ahora es nullable, máquinas pueden vivir en una ciudad directamente
- **Advertencia de horómetro:** alerta JS cuando el horómetro ingresado es menor al anterior
- **Dropdown de sistemas/fallas** con taxonomía estándar
- **uninstall.php** para limpieza al desinstalar
- Migración automática de base de datos en `maybe_upgrade()`
- Versión: 0.7.0

### ✅ v0.7.1 — Fix auto-update
- Configuración de Plugin Update Checker (PUC)
- Repositorio público en GitHub: https://github.com/Dsantycam/cm-machine-history
- Primera publicación de release en GitHub

### ✅ v0.7.2 — Fix de carga de PUC
- Corregido: el archivo de entrada era `load-v5p5.php` pero la versión descargada era v5.7, cuyo archivo es `load-v5p7.php`
- Sin este fix, el auto-update simplemente no cargaba (silencioso, sin errores)

### ✅ v0.8.0 — Diseño profesional + V0.8 features
- **CSS completo desde cero** con design system (variables CSS, tokens de color)
- Cards con acento de color según valor (verde/amarillo/rojo)
- Tabs estilo pill con progressive enhancement (funciona con o sin JS)
- Timeline con puntos y bordes de color según tipo de mantenimiento
- Filas de tabla completamente clickables (navegación a hoja de vida)
- Empty states con iconos y mensajes útiles
- Estilos `@media print` para imprimir hoja de vida
- **Export CSV** en todas las vistas (máquinas, intervenciones, disponibilidad, logs)
- **Imprimir hoja de vida** — botón que llama `window.print()` con CSS de impresión limpio
- **Estado automático al intervenir** — selector sugiere cambiar estado según tipo de mantenimiento

### ✅ v0.8.1 — Estructura, formato de ID y correcciones
- **Nuevo formato de código de máquina:** `EMP CIU MARCA No.VARIABLE` (espacios, N.º manual)
  - Anterior: `APC-BOG-TY-001` → Nuevo: `APC BOG TY No.001`
  - El usuario escribe el identificador (número o alfanumérico)
  - Código editable desde el tab "Editar" de la hoja de vida (valida unicidad)
- **Sucursales eliminadas de la UI** — "ciudad" y "sucursal" fusionados en un único nivel "Ciudad/Sucursal". La tabla `cmh_branches` persiste en BD pero no es accesible desde la UI
- **Mayúsculas forzadas** — empresa, ciudad/sucursal, marca, modelo, serial y N.º se convierten automáticamente a mayúsculas (JS al escribir + PHP al guardar)
- **Horómetro opcional** al crear máquinas (antes era requerido)
- **Editar intervenciones** — botón "Editar" en cada tarjeta del timeline; permite corregir fecha, tipo, técnico, horas parada, costo, afecta disponibilidad y observaciones; las horas trabajadas se muestran como referencia
- **PDFs permanentes** — al detectar un PDF de E2PDF, se copia a `uploads/cm-machine-history/{code}/` para que persista aunque E2PDF lo elimine
- **Autocompletado frontend** — nuevo `assets/frontend.js`; cuando el técnico escribe el código en Forminator, se rellenan automáticamente: marca, modelo, serial, contacto, empresa, ciudad (por texto de etiqueta)
- **`ajaxurl` en admin JS** — corregido: antes no se pasaba y los endpoints AJAX fallaban
- **Fix filtro `upload_dir`** — ahora se remueve con `remove_filter()` después de cada upload
- **URLs de archivos normalizadas** — `set_url_scheme()` al guardar; corrige inconsistencias http/https
- **Endpoint público** `wp_ajax_nopriv_cmh_get_machine` para autocompletado sin login

### ✅ v0.8.2 — Autocompletado mejorado
- `frontend.js` ahora detecta campos por texto de etiqueta (no solo por slug configurado)
- Rellena cualquier campo cuya etiqueta contenga: marca/brand, modelo/model, serial/serie, contacto, horómetro, empresa, ciudad/sucursal

### ✅ v0.8.3 — Averías desde Forminator
- **`checkbox-1`** conectado como campo "tipo de mantenimiento" en form 226
- Cuando el técnico marca "correctivo" → intervención tipo `averia`, `affects_availability = 1`
- **`number-2`** ("horas detenida la máquina") → `downtime_hours` en la intervención
- Mapa completo: evaluación → evaluacion, remisión → preventivo, preventivo → preventivo
- Prioridad: si hay múltiples seleccionados y uno es "correctivo", siempre gana avería
- **Fix `flatten_submission_data`** — ahora captura campos `checkbox`, `radio` y `select` (antes se ignoraban)

### ✅ v0.8.4 — Fix duplicados en error de formulario
- `forminator_form_after_handle_submit` disparaba incluso cuando el formulario tenía errores de validación, creando intervenciones fantasma
- Fix: verificar `$response['success'] === true` antes de procesar. Si el envío falló, no se registra nada

---

### 🔲 v0.9 — Panel de técnicos
- Panel específico para usuarios técnicos (rol diferente al admin)
- Vista de tareas asignadas
- Asignaciones de máquinas a técnicos
- Posiblemente: notificaciones por email al asignar

### 🔲 v1.0 — SaaS ready
- Panel de cliente (vista de solo lectura para el cliente final)
- Analytics completos (gráficas de tendencia)
- Mantenimiento programado (calendario de próximos mantenimientos)
- Alertas automáticas (email cuando una máquina entra en estado crítico)
- Multiempresa / multiusuario con roles
- Posiblemente: integración con sistema de tickets

---

## 11. Decisiones de diseño importantes

### 11.1 Disponibilidad: solo averías descuentan
**Decisión:** solo `affects_availability = 1` descuenta tiempo disponible.  
**Por qué:** el mantenimiento preventivo es tiempo de trabajo del técnico, no tiempo muerto de la operación. En la plantilla Excel del cliente "INDICADORES INHOUSE APC 2025", se separan claramente "T. SUSP. AVERIAS" (descuenta) de "T. MANTENIMIENTO" (no descuenta).

### 11.2 Base de disponibilidad: horas programadas por máquina (no por intervención)
**Decisión:** `scheduled_hours_monthly` es un campo configurable por máquina.  
**Por qué:** distintas máquinas tienen distintos turnos. En el Excel del cliente: Toyota 11 tiene 480 h/mes (1 turno), Toyota 9 tiene 720 h/mes (turnos extendidos). Una base fija o calculada dinámicamente sería incorrecta.  
**Implicación:** la disponibilidad histórica usa el valor actual de `scheduled_hours_monthly` para todos los meses. Si las horas programadas cambian, el histórico se recalcula con el nuevo valor. Para versiones futuras, podría necesitarse historial de cambios.

### 11.3 Código de máquina: formato con espacios y N.º manual (desde v0.8.1)
**Decisión:** formato `EMPRESA CIUDAD MARCA No.VARIABLE` con identificador manual.  
**Por qué:** el autoincremental (`001`, `002`...) era confuso cuando se eliminaban máquinas o se reordenaban. El usuario conoce mejor el número de su propia máquina. Los espacios son más legibles que guiones en etiquetas físicas.  
**Compatibilidad:** máquinas existentes conservan su código. Solo nuevas máquinas usan el nuevo formato.

### 11.4 Sucursales eliminadas de la UI (desde v0.8.1)
**Decisión:** el nivel "sucursal" se elimina de la interfaz. "Ciudad" se renombra a "Ciudad/Sucursal" y el usuario escribe lo que necesite.  
**Por qué:** la jerarquía Empresa → Ciudad → Sucursal → Máquina tenía un nivel de más. En la práctica, los usuarios agrupaban máquinas por sede/bodega, no por ciudad administrativa. "Ciudad/Sucursal" como un único campo libre es más flexible y simple.  
**BD:** la tabla `cmh_branches` y el campo `branch_id` siguen existiendo para no romper datos históricos, pero la UI no los expone.

### 11.5 Auto-update: repo público sin token
**Decisión:** repositorio GitHub público, sin token de autenticación en el plugin.  
**Por qué:** los plugins GPL distribuidos deben tener código fuente disponible. Un token embebido en un plugin distribuido es un riesgo de seguridad (cualquiera puede extraerlo del archivo PHP). Un repositorio público es la práctica estándar de la industria.

### 11.6 No modificar UX de formularios Forminator
**Decisión:** el plugin NO cambia nada en los formularios Forminator.  
**Por qué:** los técnicos llevan mucho tiempo usando esos formularios. Cambios en la estructura rompería el parseo de campos. El plugin se adapta a los formularios, no al revés.

### 11.7 Downtime_hours en Forminator — resuelto en v0.8.3
**Decisión:** el campo `number-2` ("horas detenida la máquina") del form 226 se mapea a `downtime_hours`.  
**Por qué:** el usuario agregó este campo al formulario Forminator sin cambiar el flujo visual del técnico. Es el enfoque correcto: adaptar el formulario gradualmente sin romper la UX.  
**Para forms 215/225:** siguen sin campo de horas parada (son preventivos, no descuentan disponibilidad, por lo que no aplica).

### 11.8 No depender de APIs externas
**Decisión:** todo es local (servidor WordPress).  
**Por qué:** minimizar costos, no depender de servicios de terceros, funcionamiento offline.

### 11.9 Tabs: progressive enhancement
**Decisión:** los paneles de la hoja de vida se muestran como secciones normales si JS no está disponible.  
**Implementación:** CSS por defecto muestra todos los paneles. JS añade clase `.cmh-tabs-active` al wrapper que activa el comportamiento de tabs (ocultar todos, mostrar solo el activo). Sin JS, todo es visible como scroll vertical.

### 11.10 Export CSV con BOM y separador punto y coma
**Decisión:** los CSV usan BOM UTF-8 (`\xEF\xBB\xBF`) y separador `;`.  
**Por qué:** Excel en Colombia (y América Latina) usa `;` como separador de lista por la configuración regional. Sin BOM, los caracteres con tilde o ñ se corrompen al abrir en Excel.

---

## 12. Sistema de diseño CSS

### Variables (tokens)
```css
--cmh-primary:       #2271b1  (azul WordPress)
--cmh-ok:            #00a32a  (verde)
--cmh-warn:          #dba617  (amarillo/ámbar)
--cmh-danger:        #d63638  (rojo)
--cmh-gray-50/100/200/400/600/900  (escala de grises)
--cmh-radius:        10px
--cmh-radius-lg:     16px
--cmh-shadow:        0 1px 4px rgba(0,0,0,.07)
--cmh-shadow-md:     0 4px 16px rgba(0,0,0,.09)
```

### Componentes principales
- `.cmh-hero-block` — hero sections (dashboard, hoja de vida)
- `.cmh-grid` + `.cmh-card` — grid de tarjetas KPI con acento de color
- `.cmh-card-accent-{ok|warn|danger|blue}` — barra de color en top del card
- `.cmh-tabs` + `.cmh-tab` + `.cmh-tab-panel` — sistema de tabs estilo pill
- `.cmh-timeline` + `.cmh-timeline-item` + `.cmh-dot-{tipo}` — timeline de intervenciones
- `.cmh-avail-badge .cmh-avail-{ok|warn|danger}` — badge de disponibilidad
- `.cmh-badge .cmh-status-{activa|mantenimiento|inactiva|fuera_servicio}` — estado máquina
- `.cmh-empty` — estados vacíos con icono y mensaje
- `.cmh-toolbar` — barra con título + acciones (exportar, etc.)
- `.cmh-field-warning` — advertencia amarilla bajo inputs

---

## 13. Auto-actualizaciones

### Mecanismo
Plugin Update Checker v5.7 verifica el repositorio GitHub en cada actualización de plugins de WordPress. Si la versión del último release (tag vX.Y.Z) es mayor a la versión instalada (del header del plugin), muestra la notificación de actualización estándar de WordPress.

### Regla crítica de versiones
**El número en el header `Version:` y la constante `CMH_VERSION` DEBEN ser idénticos siempre.**  
Si difieren, el mecanismo de actualización se comporta de forma impredecible.

### Proceso de publicar una nueva versión
```
1. Cambiar 'Version: X.Y.Z' en el header del plugin
2. Cambiar define('CMH_VERSION', 'X.Y.Z') en la misma línea del archivo
3. git add . && git commit -m "vX.Y.Z — descripción"
4. git push origin main
5. GitHub → Releases → Create new release
   - Tag: vX.Y.Z (con "v" minúscula)
   - Adjuntar: cm-machine-history.zip (carpeta completa comprimida, con la carpeta padre adentro)
6. Los WordPress con el plugin instalado detectan la actualización en ~12h
   (o inmediatamente con plugins.php?force-check=1)
```

### Estructura del ZIP
El ZIP debe contener la carpeta con el mismo nombre del plugin adentro:
```
cm-machine-history.zip
└── cm-machine-history/     ← carpeta padre obligatoria
    ├── cm-machine-history.php
    ├── includes/
    ├── assets/
    └── lib/
```
Si el ZIP contiene los archivos directamente sin la carpeta padre, WordPress no puede instalar la actualización correctamente.

---

## 14. Reglas y restricciones del proyecto

| # | Regla |
|---|---|
| R1 | NO modificar la UX de los formularios Forminator (estructura visual, flujo, lógica operativa) |
| R2 | NO modificar los templates de E2PDF |
| R3 | NO actualizar versiones de Forminator o E2PDF sin pruebas previas (versiones fijas: 1.37.1 y 1.28.05) |
| R4 | NO usar APIs externas — todo local |
| R5 | NO Google Drive (baja prioridad, tal vez futuro) |
| R6 | Minimizar costos: evitar SaaS externos |
| R7 | Plugin debe mantener créditos/autoría a nombre de Santiago Camacho |
| R8 | Version header y CMH_VERSION siempre idénticos |
| R9 | El repositorio GitHub debe ser público para que el auto-update funcione sin tokens |
| R10 | La disponibilidad nunca puede ser negativa ni mayor a 100% |

---

## 15. Campos del sistema de fallas (taxonomía estándar)

Usado en el dropdown del formulario de intervención manual y en los reportes:

| Clave DB | Etiqueta UI |
|---|---|
| frenos | Frenos |
| potencia | Potencia |
| traccion | Tracción |
| seguridad | Seguridad |
| encendido | Encendido |
| refrigeracion | Refrigeración |
| mastil | Mástil |
| direccion | Dirección |
| combustible | Combustible |
| hidraulico | Sist. Hidráulico |
| electronico | Electrónico |
| otro | Otro |

Esta taxonomía viene de la plantilla Excel del cliente (columna SISTEMA).

---

## 16. Detalles técnicos implícitos / no obvios

### 16.1 Doble hook de Forminator + verificación de éxito
El plugin registra el mismo handler en DOS hooks de Forminator:
- `forminator_form_after_handle_submit` — dispara durante el procesamiento, **incluso en envíos fallidos**
- `forminator_form_after_save_entry` — solo dispara cuando la entrada se guardó exitosamente

Para compatibilidad con Forminator 1.37.x se mantienen ambos hooks. El mecanismo de deduplicación por `e2pdf_entry_id` evita duplicados si ambos se disparan.

**Fix crítico v0.8.4:** cuando el hook activo es `forminator_form_after_handle_submit`, se verifica `$response['success'] === true` antes de procesar. Si el formulario tuvo errores de validación o fallo, se retorna sin crear la intervención.

### 16.2 `maybe_upgrade()` corre en cada `admin_init`
Es el mecanismo de migración automática. Está protegido por la comparación de versiones: `get_option('cmh_version') !== CMH_VERSION`. Solo si difieren (nueva versión instalada) se corre `activate()`. El impacto en performance es mínimo (una query a `wp_options` por request en el admin).

### 16.3 `dbDelta` no puede todo
`dbDelta` (WordPress) puede AÑADIR columnas a tablas existentes, pero NO puede cambiar definiciones de columnas existentes (como hacer `NOT NULL` → `NULL`). Por eso `run_migrations()` usa `ALTER TABLE` directo para cambios que dbDelta no soporta.

### 16.4 El horómetro solo se actualiza si es mayor o igual al anterior
En `save_intervention()`, el `current_hourmeter` de la máquina solo se actualiza si `$hourmeter >= $prev_hm`. Si el técnico ingresó un horómetro inconsistente (menor) y lo confirmó, la intervención se guarda con ese valor pero el horómetro de la máquina NO retrocede. Esto es intencional para proteger la integridad del historial.

### 16.5 `fleet_availability()` usa scheduled_hours_monthly actual de TODAS las máquinas
La disponibilidad de flota en el dashboard suma las horas programadas mensuales de todas las máquinas como base, contra el downtime de averías del mes actual. No tiene en cuenta si una máquina fue dada de baja durante el mes.

### 16.6 Los CSV usan `fputcsv` con `;` como delimitador
```php
fputcsv( $out, $row, ';' );
```
El tercer parámetro de `fputcsv` es el delimitador. El default de PHP es `,` que no funciona bien en Excel con configuración de lista en `;` (caso común en Colombia).

### 16.7 La opción de BD legacy se limpia
La opción de WordPress `cmh_machine_history_version` (nombre usado en versiones anteriores a v0.7) se elimina con `delete_option()` en `maybe_upgrade()` cuando se detecta y se migra a `cmh_version`.

### 16.8 Tabs: la clase `cmh-tabs-active` la agrega JS
El CSS tiene:
```css
.cmh-tab-panel { display: block; }          /* Default: todos visibles */
.cmh-tabs-active .cmh-tab-panel { display: none; }  /* Con JS: ocultar */
.cmh-tabs-active .cmh-tab-panel.active { display: block; } /* Mostrar activo */
```
JS hace `$tabsWrapper.addClass('cmh-tabs-active')` antes de activar el primer tab. Así, sin JS, todos los paneles son visibles (progressive enhancement).

### 16.9 `flatten_submission_data` captura checkbox/radio/select
El método que extrae datos de los envíos de Forminator usaba el patrón `text|number|name|date|hidden|textarea|address|email|phone`. Se amplió a incluir `checkbox|radio|select` para poder leer el campo `checkbox-1` (tipo de mantenimiento). Sin este fix, los campos de checkbox se ignoraban silenciosamente.

### 16.10 Mayúsculas: doble capa (JS + PHP)
La clase CSS `.cmh-uppercase` en inputs activa un handler JS que convierte a mayúsculas mientras el usuario escribe (`input` event). Además, en los handlers PHP (`save_company`, `save_city`, `save_machine`, `update_machine`) se aplica `strtoupper()` antes de guardar. El PHP es la capa de seguridad real; el JS es solo UX.

### 16.11 gh CLI instalado en C:\tools\gh\bin
El GitHub CLI (`gh`) se instaló manualmente en `C:\tools\gh\bin\gh.exe` y se agregó al PATH del usuario. En nuevas sesiones de PowerShell puede ser necesario ejecutar `$env:PATH += ";C:\tools\gh\bin"` si no está disponible. La autenticación se almacena en el keyring del sistema con la cuenta `Dsantycam`.

---

## 17. Lo que falta y próximas sesiones

### Inmediato (cuando se retome)
- Probar el plugin en WordPress real con datos reales para validar disponibilidad y MTTR
- Verificar que la exportación CSV abre bien en Excel colombiano
- Verificar que el botón de imprimir hoja de vida funciona en Chrome/Edge
- Probar flujo completo de avería desde Forminator: form 226 → checkbox correctivo → horas en number-2 → aparece como avería en hoja de vida con downtime correcto

### V0.9 — Panel de técnicos
- Nuevo rol de usuario WordPress: `cmh_technician`
- Vista simplificada (solo sus asignaciones, sin gestión de empresas)
- CRUD de tareas de mantenimiento
- Asignación técnico → máquina
- Quizás: notificaciones por email

### V1.0 — SaaS
- Panel de cliente (solo lectura, sin acceso al admin de WP)
- Gráficas de tendencia (Chart.js o similar, o solo tablas bien diseñadas)
- Mantenimiento programado: alertas cuando se acerque el vencimiento de un preventivo
- Alertas automáticas por email cuando una máquina entra en estado crítico
- Soporte multiempresa con roles separados por empresa
- Considerar Freemius para gestión de licencias y pagos
