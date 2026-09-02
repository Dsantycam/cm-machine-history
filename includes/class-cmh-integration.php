<?php
/**
 * CMH_Integration — captura de Forminator y asociación de PDFs E2PDF.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Integration {

    public static function init() {
        add_action( 'forminator_form_after_handle_submit', [ __CLASS__, 'capture_submit' ],    10, 10 );
        add_action( 'forminator_form_after_save_entry',    [ __CLASS__, 'capture_submit' ],    10, 10 );
        add_action( 'cmh_find_e2pdf_pdf_event',            [ __CLASS__, 'find_pdf' ],          10, 4  );
        add_action( 'wp_enqueue_scripts',                  [ __CLASS__, 'enqueue_frontend' ] );
    }

    /**
     * Carga el JS del frontend y le pasa lo justo: qué campo es la máquina en
     * cada formato y, si la URL trae `cmh_machine`, los valores de prellenado YA
     * RESUELTOS en el servidor.
     *
     * Resolver aquí y no en el navegador es deliberado: la página no tiene que
     * pedir la ficha por AJAX para poder pintarse, así que el prellenado funciona
     * aunque la petición asíncrona falle.
     */
    public static function enqueue_frontend() {
        wp_enqueue_script( 'cmh-front', CMH_URL . 'assets/frontend.js', [ 'jquery' ], CMH_VERSION, true );

        $forms = [];
        foreach ( self::config() as $id => $cfg ) {
            $forms[ $id ] = [
                'machine_field' => self::slug( $cfg, 'machine' ),
                'contact_field' => self::slug( $cfg, 'contact' ),
            ];
        }

        $machine_code = isset( $_GET['cmh_machine'] ) ? sanitize_text_field( wp_unslash( $_GET['cmh_machine'] ) ) : '';
        $task_id      = isset( $_GET['cmh_task'] ) ? (int) $_GET['cmh_task'] : 0;

        wp_localize_script( 'cmh-front', 'CMHFront', [
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'formConfigs' => $forms,
            'prefill'     => $machine_code ? CMH_Forms::resolve_prefill( $machine_code, $task_id ) : [],
        ] );
    }

    // -------------------------------------------------------------------------
    // Configuración de formularios
    // -------------------------------------------------------------------------

    /**
     * Mapa de formatos vinculados y activos, indexado por form_id de Forminator.
     *
     * Hasta la v1.0.1 este array estaba escrito a mano aquí. Desde la v2.0 vive en
     * la opción `cmh_forms` y se administra desde «Máquinas → Formatos»; si la
     * opción falta o está corrupta, CMH_Forms devuelve el mapeo original, así que
     * la captura nunca se queda sin configuración.
     */
    public static function config() {
        return CMH_Forms::enabled();
    }

    /** Slug de un campo del formato, o '' si no está mapeado. */
    private static function slug( $cfg, $target ) {
        return isset( $cfg['fields'][ $target ] ) ? (string) $cfg['fields'][ $target ] : '';
    }

    // -------------------------------------------------------------------------
    // Formatos: nombre, página y apertura prellenada
    // -------------------------------------------------------------------------

    /** Nombre legible de un formato. */
    public static function form_label( $form_id ) {
        $cfg = CMH_Forms::get( $form_id );
        if ( ! $cfg ) return '';
        return ( $cfg['label'] ?: ( 'Formulario ' . (int) $form_id ) ) . ' (' . (int) $form_id . ')';
    }

    /** [ form_id => etiqueta ] de los formatos activos, para los <select>. */
    public static function forms_for_select() {
        $out = [];
        foreach ( array_keys( self::config() ) as $id ) $out[ $id ] = self::form_label( $id );
        return $out;
    }

    /** ¿Es un formato vinculado y activo? */
    public static function is_valid_form( $form_id ) {
        return isset( self::config()[ (int) $form_id ] );
    }

    /**
     * Busca la página donde está incrustado un formulario de Forminator.
     *
     * El plugin no sabe en qué página pusiste cada formato, así que se rastrea el
     * contenido publicado buscando el shortcode o el bloque de Gutenberg. Es una
     * ayuda, no la verdad: si acierta mal, la URL escrita en la ficha del formato
     * manda. El resultado se cachea 12 h para no rastrear en cada carga.
     *
     * @return string Permalink o '' si no se encontró.
     */
    public static function detect_form_url( $form_id ) {
        $form_id = (int) $form_id;
        $key     = 'cmh_form_url_' . $form_id;
        $cached  = get_transient( $key );
        if ( $cached !== false ) return $cached;

        global $wpdb;
        $patterns = [
            '%[forminator_form id="' . $form_id . '"%',
            "%[forminator_form id='" . $form_id . "'%",
            // Sin comillas hay que cerrar el número: si no, «id=215» matchearía «id=2151».
            '%[forminator_form id=' . $form_id . ']%',
            '%[forminator_form id=' . $form_id . ' %',
            '%"module_id":"' . $form_id . '"%',   // bloque de Gutenberg
            '%"form_id":"' . $form_id . '"%',
        ];

        $url = '';
        foreach ( $patterns as $p ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status='publish' AND post_type NOT IN ('revision','nav_menu_item')
                   AND post_content LIKE %s
                 ORDER BY post_type='page' DESC, ID ASC LIMIT 1",
                $p
            ) );
            if ( $id ) { $url = get_permalink( $id ); break; }
        }

        set_transient( $key, $url, 12 * HOUR_IN_SECONDS );
        return $url;
    }

    /**
     * URL final de un formato, con los parámetros de prellenado.
     * Prioridad: la escrita en la ficha del formato → la autodetectada → '' (sin URL).
     */
    public static function form_url( $form_id, $args = [] ) {
        $cfg = CMH_Forms::get( $form_id );
        if ( ! $cfg ) return '';

        $url = $cfg['page_url'] ?: self::detect_form_url( $form_id );
        if ( ! $url ) return '';

        return $args ? add_query_arg( $args, $url ) : $url;
    }

    /** Formatos activos que hoy NO tienen URL resoluble (para avisar en la pantalla). */
    public static function forms_without_url() {
        $out = [];
        foreach ( array_keys( self::config() ) as $id ) {
            if ( ! self::form_url( $id ) ) $out[] = $id;
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Captura de envíos
    // -------------------------------------------------------------------------

    /**
     * Adaptador del hook de Forminator: identifica el formulario, comprueba que
     * el envío fue exitoso, aplana los datos y delega en process_entry().
     */
    public static function capture_submit() {
        static $processed = [];

        $args    = func_get_args();
        $form_id = self::extract_form_id( $args );
        if ( ! $form_id || ! self::is_valid_form( $form_id ) ) return;

        // forminator_form_after_handle_submit dispara incluso cuando hay errores de
        // validación. Solo continuar si la respuesta indica éxito explícito.
        // forminator_form_after_save_entry solo dispara al guardar → no requiere chequeo.
        if ( current_filter() === 'forminator_form_after_handle_submit' ) {
            $ok = false;
            foreach ( $args as $arg ) {
                if ( is_array( $arg ) && isset( $arg['success'] ) ) {
                    $ok = (bool) $arg['success']; break;
                }
                if ( is_object( $arg ) && isset( $arg->success ) ) {
                    $ok = (bool) $arg->success; break;
                }
            }
            if ( ! $ok ) return;
        }

        $data = self::flatten_submission_data( $args );
        if ( empty( $data ) ) $data = $_POST;

        // Los dos hooks pueden disparar por el mismo envío dentro de la misma
        // petición: la llave evita procesarlo dos veces.
        $key = self::entry_key( $form_id, $data );
        if ( isset( $processed[ $key ] ) ) return;
        $processed[ $key ] = true;

        self::process_entry( $form_id, $data );
    }

    /** Llave única del envío, usada para deduplicar dentro y entre peticiones. */
    private static function entry_key( $form_id, $data, $cfg = null ) {
        $cfg          = $cfg ?: CMH_Forms::get( $form_id );
        $machine_code = strtoupper( trim( sanitize_text_field(
            self::human( self::field( $data, self::slug( $cfg, 'machine' ) ) )
        ) ) );
        $remission = self::field( $data, self::slug( $cfg, 'remission' ) );
        return 'f' . (int) $form_id . '-' . md5( $machine_code . '|' . self::human( $remission ) . '|' . wp_json_encode( $data ) );
    }

    /**
     * Lee un envío y devuelve TODO lo que el plugin extraería, sin tocar la base
     * de datos. Es la fuente única de la lectura: la usan la captura real y el
     * probador de mapeo de «Máquinas → Formatos».
     *
     * @return array Datos interpretados + 'warnings' con lo que no se pudo mapear.
     */
    public static function parse_entry( $form_id, $data, $cfg = null ) {
        global $wpdb; $t = CMH_Core::tables();
        $cfg      = $cfg ?: CMH_Forms::get( $form_id );
        $warnings = [];

        $machine_code = strtoupper( trim( sanitize_text_field(
            self::human( self::field( $data, self::slug( $cfg, 'machine' ) ) )
        ) ) );

        $machine = $machine_code ? $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['machines']} WHERE machine_code=%s", $machine_code
        ) ) : null;

        if ( ! self::slug( $cfg, 'machine' ) ) $warnings[] = 'No hay campo de máquina mapeado.';
        elseif ( ! $machine_code )             $warnings[] = 'El campo de máquina llegó vacío.';
        elseif ( ! $machine )                  $warnings[] = 'El código «' . $machine_code . '» no existe en el sistema.';

        // ── Tipo de mantenimiento ─────────────────────────────────────────────
        $maintenance_type = $cfg['maintenance_type'];
        if ( ! empty( $cfg['type_field'] ) ) {
            $selected = self::selected_values( self::field( $data, $cfg['type_field'] ) );
            $matched  = false;
            // El orden del mapa es la prioridad: gana la primera coincidencia.
            foreach ( (array) $cfg['type_map'] as $needle => $mapped ) {
                if ( in_array( self::norm( $needle ), $selected, true ) ) {
                    $maintenance_type = $mapped;
                    $matched          = true;
                    break;
                }
            }
            if ( ! $matched && $selected ) {
                $warnings[] = 'El valor «' . implode( ', ', $selected ) . '» no está en la tabla de tipos: se usó «' . $maintenance_type . '».';
            }
        }

        // ── Sistema / falla ───────────────────────────────────────────────────
        $failure_system = '';
        if ( self::slug( $cfg, 'failure_system' ) ) {
            $raw      = self::field( $data, self::slug( $cfg, 'failure_system' ) );
            $selected = self::selected_values( $raw );

            foreach ( (array) $cfg['system_map'] as $needle => $mapped ) {
                if ( in_array( self::norm( $needle ), $selected, true ) ) { $failure_system = $mapped; break; }
            }
            // Sin traducción explícita, se intenta reconocer contra la taxonomía.
            if ( ! $failure_system ) $failure_system = self::guess_system( $selected );
            if ( ! $failure_system && $selected ) {
                $warnings[] = 'El sistema «' . implode( ', ', $selected ) . '» no se pudo traducir: agrégalo a la tabla de sistemas.';
            }
        }

        $obs = self::human( self::field( $data, self::slug( $cfg, 'observations' ) ) );
        $rem = self::human( self::field( $data, self::slug( $cfg, 'remission' ) ) );
        if ( $rem ) $obs = trim( $obs . "\nRemisión: " . $rem );

        $cost = self::slug( $cfg, 'cost' ) ? self::to_float( self::field( $data, self::slug( $cfg, 'cost' ) ) ) : 0.0;
        $paid = self::slug( $cfg, 'paid_amount' ) ? self::to_float( self::field( $data, self::slug( $cfg, 'paid_amount' ) ) ) : 0.0;
        list( $pay_status, $paid ) = CMH_Admin::normalize_payment( CMH_Admin::derive_payment_status( $cost, $paid ), $cost, $paid );

        return [
            'machine_code'      => $machine_code,
            'machine'           => $machine,
            'machine_found'     => (bool) $machine,
            'intervention_date' => self::normalize_date( self::field( $data, self::slug( $cfg, 'date' ) ) ),
            'form_type'         => $cfg['form_type'] ?: 'formato',
            'maintenance_type'  => $maintenance_type,
            'affects'           => CMH_Metrics::auto_affects_availability( $maintenance_type ),
            'hourmeter'         => self::to_float( self::field( $data, self::slug( $cfg, 'hourmeter' ) ) ),
            'worked_hours'      => self::to_float( self::field( $data, self::slug( $cfg, 'worked_hours' ) ) ),
            'downtime_hours'    => self::to_float( self::field( $data, self::slug( $cfg, 'downtime_hours' ) ) ),
            'technician'        => self::human( self::field( $data, self::slug( $cfg, 'technician' ) ) ),
            'parts'             => self::human( self::field( $data, self::slug( $cfg, 'parts' ) ) ),
            'services'          => self::human( self::field( $data, self::slug( $cfg, 'services' ) ) ),
            'observations'      => $obs,
            'failure_system'    => $failure_system,
            'cost'              => $cost,
            'paid_amount'       => $paid,
            'payment_status'    => $pay_status,
            'warnings'          => $warnings,
        ];
    }

    /** Valores marcados de un checkbox/radio/select, normalizados para comparar. */
    private static function selected_values( $raw ) {
        if ( is_array( $raw ) ) {
            $vals = array_map( [ __CLASS__, 'norm' ], array_map( [ __CLASS__, 'human' ], $raw ) );
        } else {
            $str  = self::norm( self::human( $raw ) );
            $vals = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $str ) ) );
            // El valor completo también cuenta: «sistema hidraulico» no es dos palabras sueltas.
            if ( $str !== '' ) $vals[] = $str;
        }
        return array_values( array_unique( array_filter( $vals ) ) );
    }

    /** Minúsculas y sin acentos: así se comparan los valores del formulario. */
    private static function norm( $v ) {
        return strtolower( remove_accents( trim( (string) $v ) ) );
    }

    /** Intenta reconocer el sistema contra la taxonomía del plugin. */
    private static function guess_system( $selected ) {
        $systems = CMH_Admin::failure_systems();
        foreach ( $selected as $v ) {
            if ( isset( $systems[ $v ] ) ) return $v;
            foreach ( $systems as $key => $label ) {
                if ( self::norm( $label ) === $v ) return $key;
            }
        }
        return '';
    }

    /**
     * Procesa un envío: lo interpreta, crea la intervención y dispara la búsqueda
     * del PDF. Separado de capture_submit() para que el reproceso desde los logs
     * pueda reutilizarlo tal cual.
     *
     * @return array ['created'=>bool, 'intervention_id'=>int, 'message'=>string]
     */
    public static function process_entry( $form_id, $data ) {
        global $wpdb; $t = CMH_Core::tables();

        $cfg = CMH_Forms::get( $form_id );
        if ( ! $cfg ) return [ 'created' => false, 'intervention_id' => 0, 'message' => 'El formato no está vinculado.' ];

        $p            = self::parse_entry( $form_id, $data, $cfg );
        $machine_code = $p['machine_code'];
        $key          = self::entry_key( $form_id, $data, $cfg );

        if ( ! $machine_code ) {
            CMH_Core::log( 'warning', $form_id, '', null,
                'Campo máquina vacío en ' . ( self::slug( $cfg, 'machine' ) ?: '(sin mapear)' ), $data );
            return [ 'created' => false, 'intervention_id' => 0, 'message' => 'el campo de máquina llegó vacío.' ];
        }
        if ( ! $p['machine_found'] ) {
            CMH_Core::log( 'error', $form_id, $machine_code, null, 'Código de máquina no encontrado en el sistema.', $data );
            return [ 'created' => false, 'intervention_id' => 0, 'message' => 'el código «' . $machine_code . '» no existe.' ];
        }

        // Evitar duplicados por llave única de envío.
        if ( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['interventions']} WHERE e2pdf_entry_id=%s LIMIT 1", $key
        ) ) ) {
            CMH_Core::log( 'info', $form_id, $machine_code, null, 'Intervención duplicada ignorada.', [] );
            return [ 'created' => false, 'intervention_id' => 0, 'message' => 'ese envío ya estaba registrado.' ];
        }

        $machine = $p['machine'];
        $date    = $p['intervention_date'];

        $wpdb->insert( $t['interventions'], [
            'machine_id'          => (int) $machine->id,
            'forminator_form_id'  => (int) $form_id,
            'e2pdf_entry_id'      => $key,
            'intervention_date'   => $date,
            'form_type'           => $p['form_type'],
            'maintenance_type'    => $p['maintenance_type'],
            'technician'          => sanitize_text_field( $p['technician'] ),
            'hourmeter'           => $p['hourmeter'],
            'worked_hours'        => $p['worked_hours'],
            'downtime_hours'      => $p['downtime_hours'],
            'cost'                => $p['cost'],
            'paid_amount'         => $p['paid_amount'],
            'payment_status'      => $p['payment_status'],
            'affects_availability'=> $p['affects'],
            'failure_system'      => sanitize_text_field( $p['failure_system'] ),
            'parts'               => sanitize_textarea_field( $p['parts'] ),
            'services'            => sanitize_textarea_field( $p['services'] ),
            'observations'        => sanitize_textarea_field( $p['observations'] ),
        ] );

        if ( $wpdb->last_error ) {
            CMH_Core::log( 'error', $form_id, $machine_code, null, 'Error DB: ' . $wpdb->last_error, $data );
            return [ 'created' => false, 'intervention_id' => 0, 'message' => 'error de base de datos.' ];
        }

        $intervention_id = (int) $wpdb->insert_id;

        if ( $p['hourmeter'] > 0 ) {
            $wpdb->update( $t['machines'],
                [ 'current_hourmeter' => $p['hourmeter'], 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $machine->id ]
            );
        }

        // Lo que no se pudo traducir se deja anotado: es accionable desde la pantalla de formatos.
        foreach ( $p['warnings'] as $w ) {
            CMH_Core::log( 'warning', $form_id, $machine_code, $intervention_id, $w, [] );
        }

        CMH_Core::log( 'success', $form_id, $machine_code, $intervention_id, 'Intervención creada desde Forminator.', [] );

        // v0.11 — Recurrencia: si el formulario registró un preventivo y la máquina
        // tiene intervalo configurado, se reprograma la próxima fecha automáticamente.
        $next = CMH_Schedule::recalc_next_maintenance( (int) $machine->id, $date, $p['maintenance_type'] );
        if ( $next ) {
            CMH_Core::log( 'info', $form_id, $machine_code, $intervention_id,
                'Próximo mantenimiento reprogramado para ' . $next . '.', [] );
        }

        // Epoch real del envío (no la fecha de la BD): así la ventana de búsqueda es
        // inmune a la zona horaria de MySQL y coincide con el mtime real de los archivos.
        $submitted = time();
        self::find_pdf( $intervention_id, (int) $machine->id, $machine_code, $submitted );

        // Reintentos escalonados: E2PDF puede generar el PDF con retraso y, en sitios
        // de bajo tráfico, WP-Cron dispara tarde. Programamos varios intentos para que
        // el PDF quede asociado aunque aparezca minutos después (aplica a todo tipo de
        // intervención, no solo averías).
        foreach ( [ 90, 300, 900 ] as $delay ) {
            wp_schedule_single_event( time() + $delay, 'cmh_find_e2pdf_pdf_event',
                [ $intervention_id, (int) $machine->id, $machine_code, $submitted ] );
        }

        return [ 'created' => true, 'intervention_id' => $intervention_id, 'message' => '' ];
    }

    // -------------------------------------------------------------------------
    // E2PDF — búsqueda y asociación de PDF
    // -------------------------------------------------------------------------

    public static function find_pdf( $intervention_id, $machine_id, $machine_code, $submitted = 0 ) {
        global $wpdb;
        $t = CMH_Core::tables();

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, file_url FROM {$t['files']} WHERE intervention_id=%d LIMIT 1", $intervention_id
        ) );
        // Si ya hay copia propia (URL en cm-machine-history/), no hace falta hacer nada
        if ( $existing && strpos( $existing->file_url, '/cm-machine-history/' ) !== false ) return;

        $upload = wp_upload_dir();
        $base   = trailingslashit( $upload['basedir'] ) . 'e2pdf';

        if ( ! is_dir( $base ) ) {
            CMH_Core::log( 'warning', null, $machine_code, $intervention_id,
                'No existe carpeta uploads/e2pdf. Verifica que [e2pdf-save] esté activo.', [] );
            return;
        }

        $candidate = self::latest_pdf( $base, $machine_code, (int) $submitted );
        if ( ! $candidate ) {
            CMH_Core::log( 'warning', null, $machine_code, $intervention_id,
                'PDF de E2PDF aún no disponible. Se reintentará automáticamente.', [] );
            return;
        }

        // Copiar el PDF a nuestro propio directorio para que persista aunque E2PDF lo elimine
        $safe_code  = sanitize_file_name( $machine_code );
        $our_dir    = $upload['basedir'] . '/cm-machine-history/' . $safe_code;
        $our_url    = $upload['baseurl'] . '/cm-machine-history/' . $safe_code;
        $store_path = $candidate;
        $store_url  = '';

        if ( ! is_dir( $our_dir ) ) wp_mkdir_p( $our_dir );
        if ( is_dir( $our_dir ) ) {
            $dst = $our_dir . '/' . basename( $candidate );
            if ( ! file_exists( $dst ) && copy( $candidate, $dst ) ) {
                $store_path = $dst;
                $store_url  = esc_url_raw( set_url_scheme( $our_url . '/' . basename( $candidate ) ) );
            }
        }

        if ( ! $store_url ) {
            $rel       = str_replace( $upload['basedir'], '', $candidate );
            $store_url = esc_url_raw( set_url_scheme( trailingslashit( $upload['baseurl'] ) . ltrim( str_replace( DIRECTORY_SEPARATOR, '/', $rel ), '/' ) ) );
        }

        $file_data = [
            'file_url'  => $store_url,
            'file_path' => $store_path,
            'file_name' => basename( $candidate ),
            'file_type' => 'application/pdf',
        ];
        if ( $existing ) {
            $wpdb->update( $t['files'], $file_data, [ 'id' => (int) $existing->id ] );
        } else {
            $wpdb->insert( $t['files'], array_merge( $file_data, [
                'machine_id'      => (int) $machine_id,
                'intervention_id' => (int) $intervention_id,
                'uploaded_by'     => 0,
            ] ) );
        }

        CMH_Core::log( 'success', null, $machine_code, $intervention_id,
            'PDF asociado automáticamente: ' . basename( $candidate ), [] );
    }

    /**
     * Busca el PDF de E2PDF que corresponde a la intervención en uploads/e2pdf.
     *
     * Estrategia en dos niveles para no asociar el PDF de otra máquina:
     *  1. Coincidencia por código de máquina normalizado (sin espacios ni signos): es la
     *     señal fuerte; se acepta a cualquier hora ≥ la del envío (los reintentos tardíos
     *     siguen valiendo). Gana el más reciente.
     *  2. Si ninguno coincide por código, cae al PDF más reciente pero solo dentro de una
     *     ventana acotada tras el envío (evita robar el PDF de un envío posterior).
     *
     * Trabaja con el epoch real del envío (`$submitted`), no con la fecha de la BD, para
     * ser inmune a la zona horaria de MySQL: `getMTime()` también es epoch real.
     *
     * @param string $base         Carpeta uploads/e2pdf.
     * @param string $machine_code Código de máquina (ej. "APC BOG TY No. 001").
     * @param int    $submitted    Epoch (time()) del envío. 0 → llamada manual: exige
     *                             coincidencia por código y mira hasta 30 días atrás.
     */
    private static function latest_pdf( $base, $machine_code, $submitted = 0 ) {
        $norm_code = self::norm_code( $machine_code );
        $now = time();
        if ( $submitted > $now ) $submitted = 0; // defensa: nunca un envío en el futuro

        // Cota inferior para considerar archivos (con 2 min de holgura por desfase de reloj).
        $min_mtime = $submitted ? ( $submitted - 120 ) : ( $now - 30 * DAY_IN_SECONDS );
        // Ventana del respaldo sin código: solo cuando conocemos la hora del envío.
        $fallback_max = $submitted ? ( $submitted + 1800 ) : 0;

        $best_code = null; $best_code_m = -1; // mejor coincidencia por código
        $best_any  = null; $best_any_m  = -1; // mejor respaldo por fecha

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $it as $file ) {
                if ( ! $file->isFile() ) continue;
                if ( strtolower( $file->getExtension() ) !== 'pdf' ) continue;
                $mtime = $file->getMTime();
                if ( $mtime < $min_mtime ) continue;

                $path = $file->getPathname();
                if ( $norm_code && strpos( self::norm_code( $path ), $norm_code ) !== false ) {
                    if ( $mtime > $best_code_m ) { $best_code_m = $mtime; $best_code = $path; }
                } elseif ( $fallback_max && $mtime <= $fallback_max && $mtime > $best_any_m ) {
                    $best_any_m = $mtime; $best_any = $path;
                }
            }
        } catch ( Exception $e ) {
            return null;
        }
        return $best_code ?: $best_any;
    }

    /** Normaliza a minúsculas alfanuméricas (sin espacios, acentos ni signos) para comparar códigos con nombres de archivo. */
    private static function norm_code( $value ) {
        return strtolower( preg_replace( '/[^a-z0-9]/i', '', remove_accents( (string) $value ) ) );
    }

    // -------------------------------------------------------------------------
    // Helpers de extracción de datos de Forminator
    // -------------------------------------------------------------------------

    public static function extract_form_id( $args ) {
        $valid = array_keys( self::config() );
        foreach ( $args as $arg ) {
            if ( is_numeric( $arg ) && in_array( (int) $arg, $valid, true ) ) return (int) $arg;
            if ( is_object( $arg ) ) {
                foreach ( [ 'id', 'form_id' ] as $p ) {
                    if ( isset( $arg->$p ) && in_array( (int) $arg->$p, $valid, true ) ) return (int) $arg->$p;
                }
            }
            if ( is_array( $arg ) ) {
                foreach ( [ 'form_id', 'id' ] as $k ) {
                    if ( isset( $arg[ $k ] ) && in_array( (int) $arg[ $k ], $valid, true ) ) return (int) $arg[ $k ];
                }
            }
        }
        if ( ! empty( $_POST['form_id'] ) && in_array( (int) $_POST['form_id'], $valid, true ) ) {
            return (int) $_POST['form_id'];
        }
        return 0;
    }

    public static function flatten_submission_data( $value, &$out = [] ) {
        if ( is_object( $value ) ) $value = get_object_vars( $value );
        if ( ! is_array( $value ) ) return $out;
        foreach ( $value as $k => $v ) {
            if ( is_string( $k ) && preg_match( '/^(text|number|name|date|hidden|textarea|address|email|phone|checkbox|radio|select)-/i', $k ) ) {
                $out[ $k ] = $v;
            } elseif ( is_array( $v ) || is_object( $v ) ) {
                self::flatten_submission_data( $v, $out );
            }
        }
        return $out;
    }

    public static function field( $data, $key ) {
        if ( ! $key ) return '';
        if ( isset( $data[ $key ] ) ) return $data[ $key ];
        foreach ( $data as $k => $v ) {
            if ( $k === $key ) return $v;
            if ( is_array( $v ) && isset( $v[ $key ] ) ) return $v[ $key ];
        }
        return '';
    }

    public static function human( $value ) {
        if ( is_array( $value ) )  return trim( implode( ' ', array_map( [ __CLASS__, 'human' ], $value ) ) );
        if ( is_object( $value ) ) return self::human( get_object_vars( $value ) );
        return trim( (string) $value );
    }

    /**
     * Número escrito por una persona → float.
     *
     * v2.0 — Antes se cambiaba la coma por punto y se descartaba lo que no fuera
     * numérico: un horómetro escrito «1.234,5» quedaba en `1.234.5`, no pasaba
     * `is_numeric` y se guardaba como **0**, en silencio. Con campos de texto
     * (el horómetro del formato 226 es `text-5`) eso es un escenario real.
     *
     * Ahora, cuando aparecen los dos separadores, el ÚLTIMO manda como decimal y
     * el otro se trata como separador de miles. Con un solo separador se conserva
     * el comportamiento anterior, que ya era correcto para «4,5» y «1234.5».
     */
    public static function to_float( $value ) {
        $v = trim( self::human( $value ) );
        if ( $v === '' ) return 0.0;

        // Fuera todo lo que no sea número, separador o signo (unidades, espacios, «h»).
        $v = preg_replace( '/[^0-9,.\-]/', '', $v );
        if ( $v === '' || $v === '-' ) return 0.0;

        $last_dot   = strrpos( $v, '.' );
        $last_comma = strrpos( $v, ',' );

        if ( $last_dot !== false && $last_comma !== false ) {
            // Los dos presentes: el que va más a la derecha es el decimal.
            if ( $last_comma > $last_dot ) $v = str_replace( '.', '', $v );   // 1.234,5
            else                           $v = str_replace( ',', '', $v );   // 1,234.5
        }
        $v = str_replace( ',', '.', $v );

        return is_numeric( $v ) ? (float) $v : 0.0;
    }

    public static function normalize_date( $value ) {
        $v = str_replace( '/', '-', self::human( $value ) );
        if ( ! $v ) return current_time( 'Y-m-d' );
        $ts = strtotime( $v );
        return $ts ? date( 'Y-m-d', $ts ) : current_time( 'Y-m-d' );
    }
}
