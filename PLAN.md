# CM Machine History — Plan maestro del proyecto

**Versión actual:** 0.8.0  
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
│   └── admin.js                    ← Tabs, validación horómetro, interacciones UI
└── lib/
    └── plugin-update-checker/      ← Librería PUC v5.7 (debe existir para auto-updates)
        └── load-v5p7.php           ← Entry point (versión 5.7 específicamente)
```

### Constantes globales (definidas en cm-machine-history.php)

```php
CMH_VERSION  // '0.8.0' — debe coincidir EXACTAMENTE en header del plugin Y en esta constante
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
  └── Ciudad (cmh_cities)
        ├── Sucursal (cmh_branches) [opcional]
        │     └── Máquina (cmh_machines, branch_id = sucursal)
        └── Máquina sin sucursal (cmh_machines, branch_id = NULL)
              └── Intervenciones (cmh_interventions)
                    └── Archivos PDF (cmh_files)
```

La navegación del plugin sigue exactamente esta jerarquía:
`Empresas → [Empresa] → [Ciudad] → [Sucursal]? → [Máquina] → Hoja de vida`

---

## 6. Identificador de máquinas

### Formato actual (desde v0.7)
```
EMPRESA-CIUDAD-MARCA-NNN
Ejemplo: APC-BOG-TY-001
```
- `APC` = código de la empresa
- `BOG` = código de la ciudad
- `TY` = código de la marca (Toyota → TY)
- `001` = consecutivo de 3 dígitos (se calcula contando máquinas con el mismo prefijo)

### Formato anterior (antes de v0.7) — YA NO SE USA
```
EMPRESA-CIUDAD-SUCURSAL-MARCA-NNN
Ejemplo: APC-BOG-FAC-TY-001
```
Se eliminó la sucursal del código para simplificar y porque la sucursal es ahora opcional.

**IMPORTANTE:** Las máquinas existentes con el formato antiguo NO se migran. Conservan su código original para no romper PDFs ya generados ni referencias existentes. Solo las máquinas nuevas usan el formato sin sucursal.

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
    'machine_field'    => 'text-14',      // ← campo donde el técnico escribe el código
    'hourmeter_field'  => 'number-1',
    'date_field'       => 'date-1',
    'technician_field' => 'name-2',
    'remission_field'  => 'hidden-1',
    'contact_field'    => 'text-12',
    'observations_field' => 'textarea-1',
]
225 => [  // Eléctricos — misma estructura que 215 ]
226 => [
    'form_type'        => 'correctivo',
    'maintenance_type' => 'correctivo',
    'machine_field'    => 'text-6',       // ← distinto campo en este formulario
    'parts_field'      => 'textarea-1',
    'worked_hours_field' => 'number-1',
    'services_field'   => 'textarea-2',
    ...
]
```

**IMPORTANTE:** NO modificar los formularios Forminator. Los técnicos los usan hace mucho tiempo. El plugin se adapta al formulario, no al revés.

### 8.3 Deduplicación de envíos

Cada envío genera una clave única: `'f' + form_id + '-' + md5(machine_code + '|' + remission + '|' + json(data))`.

Esta clave se guarda en `e2pdf_entry_id` y se verifica antes de insertar. Si ya existe, se ignora el envío (evita duplicados por el doble hook de Forminator 1.37.x).

### 8.4 Lógica de búsqueda de PDFs (E2PDF)

E2PDF guarda sus PDFs en `uploads/e2pdf/`. El plugin escanea recursivamente esa carpeta buscando archivos `.pdf` modificados en los últimos 15 minutos. 

Si hay varios PDFs recientes, prioriza los que contienen el código de máquina en la ruta o nombre de archivo (suma 1.000.000.000 al score de mtime). El de mayor score gana.

**NO hay webhook ni integración directa con E2PDF.** El mecanismo es búsqueda por tiempo de modificación de archivo.

**Futuro:** mover/copiar PDFs a carpetas por máquina: `uploads/cm-machine-history/{machine_code}/`.

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
- Páginas: `page_dashboard()`, `page_companies()`, `page_company()`, `page_city()`, `page_branch()`, `page_machines()`, `page_machine()`, `page_integration()`
- Formularios: `machine_form()`, `edit_machine_form()`, `intervention_form()`, `upload_form()`
- Tablas UI: `machines_table()`, `interventions_table()`, `intervention_cards()`, `availability_table()`, `files_table()`
- CRUD: `save_company/city/branch/machine/intervention()`, `update_machine()`, `upload_file()`
- V0.8: `export_csv()` y métodos privados para cada tipo de CSV
- AJAX: `ajax_get_machine()` — busca máquina por código o serial

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
  - Con BOM UTF-8 para compatibilidad con Excel en español
  - Separador punto y coma (`,` no funciona bien en Excel CO con configuración de lista en `;`)
- **Imprimir hoja de vida** — botón que llama `window.print()` con CSS de impresión limpio
- **Estado automático al intervenir** — selector en formulario sugiere cambiar el estado de la máquina según el tipo (avería → sugiere "En mantenimiento", correctivo → sugiere "Activa"), guardado en `save_intervention()`
- `mtype_badge()` — badges de color por tipo de mantenimiento en tablas
- `empty_state()` — componente unificado de estados vacíos

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

### 11.3 Código de máquina: sucursal eliminada
**Decisión:** formato `EMPRESA-CIUDAD-MARCA-NNN` en vez de `EMPRESA-CIUDAD-SUCURSAL-MARCA-NNN`.  
**Por qué:** la sucursal es un agrupador físico opcional, no una parte esencial de la identidad de la máquina. Simplifica los códigos y permite máquinas en ciudades sin sucursales.  
**Compatibilidad:** máquinas existentes conservan su código antiguo. Solo nuevas máquinas usan el nuevo formato.

### 11.4 Sucursal opcional
**Decisión:** `branch_id` en `cmh_machines` es `NULL`able.  
**Por qué:** no todas las ciudades tienen sucursales separadas. Forzar la creación de una sucursal solo para poder crear una máquina era innecesario.

### 11.5 Auto-update: repo público sin token
**Decisión:** repositorio GitHub público, sin token de autenticación en el plugin.  
**Por qué:** los plugins GPL distribuidos deben tener código fuente disponible. Un token embebido en un plugin distribuido es un riesgo de seguridad (cualquiera puede extraerlo del archivo PHP). Un repositorio público es la práctica estándar de la industria.

### 11.6 No modificar UX de formularios Forminator
**Decisión:** el plugin NO cambia nada en los formularios Forminator.  
**Por qué:** los técnicos llevan mucho tiempo usando esos formularios. Cambios en la estructura rompería el parseo de campos. El plugin se adapta a los formularios, no al revés.

### 11.7 Downtime_hours en Forminator = 0 por defecto
**Decisión:** las intervenciones creadas desde Forminator tienen `downtime_hours = 0` por defecto.  
**Por qué:** los formularios actuales no tienen un campo específico para horas de parada. El técnico lo debe registrar manualmente editando la intervención. Este es un gap conocido que podría resolverse añadiendo el campo al formulario en el futuro, pero no está en la agenda inmediata para no cambiar el UX de los técnicos.

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

### 16.1 Doble hook de Forminator
El plugin registra el mismo handler en DOS hooks de Forminator:
- `forminator_form_after_handle_submit`
- `forminator_form_after_save_entry`

Esto es necesario para compatibilidad con Forminator 1.37.x que puede disparar uno u otro según la configuración. El mecanismo de deduplicación por `e2pdf_entry_id` evita que se creen intervenciones duplicadas si ambos hooks se disparan para el mismo envío.

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

---

## 17. Lo que falta y próximas sesiones

### Inmediato (cuando se retome)
- Probar el plugin en WordPress real con datos reales de interventions para validar que la disponibilidad y MTTR calculan correctamente
- Verificar que la exportación CSV abre bien en Excel colombiano
- Verificar que el botón de imprimir hoja de vida funciona en Chrome/Edge

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
