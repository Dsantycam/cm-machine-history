<?php
/**
 * CMH_Integration — captura de Forminator y asociación de PDFs E2PDF.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Integration {

    /** v2.10.1 — Segundos tras el envío en que se reintenta encontrar el PDF. */
    const PDF_RETRIES = [ 90, 300, 900, 3600, 21600 ];

    public static function init() {
        add_action( 'forminator_form_after_handle_submit', [ __CLASS__, 'capture_submit' ],    10, 10 );
        add_action( 'forminator_form_after_save_entry',    [ __CLASS__, 'capture_submit' ],    10, 10 );
        // v2.10.1 — 6 argumentos (remisión y número de intento). Los eventos que
        // quedaron programados por versiones anteriores, con 4, siguen valiendo.
        add_action( 'cmh_find_e2pdf_pdf_event',            [ __CLASS__, 'find_pdf' ],          10, 6  );
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
            // v2.9 — Solo el campo de máquina: el resto lo decide el prellenado
            // configurado, no el JS.
            $forms[ $id ] = [ 'machine_field' => self::slug( $cfg, 'machine' ) ];
        }

        $machine_code = isset( $_GET['cmh_machine'] ) ? sanitize_text_field( wp_unslash( $_GET['cmh_machine'] ) ) : '';
        $task_id      = isset( $_GET['cmh_task'] ) ? (int) $_GET['cmh_task'] : 0;

        wp_localize_script( 'cmh-front', 'CMHFront', [
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'formConfigs' => $forms,
            'prefill'     => $machine_code ? CMH_Forms::resolve_prefill( $machine_code, $task_id ) : [],
            // Para el aviso verde bajo el campo: lo mismo que ve un visitante.
            'machine'     => $machine_code ? CMH_Admin::machine_summary( $machine_code ) : null,
            // v2.10.1 — La tarea que se abrió: el JS la mete en el envío como
            // `cmh_task` (fuera de los campos de Forminator, así no toca `hidden-1`
            // ni el nombre del PDF) para cerrar ESA tarea y no adivinarla.
            'task'        => $task_id,
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

        self::process_entry( $form_id, $data, self::submitted_task_id(), get_current_user_id() );
    }

    /**
     * v2.10.1 — La tarea desde la que se abrió el formato, si la hay.
     *
     * 1. El input `cmh_task` que mete frontend.js en el formulario.
     * 2. Si no llegó (JS bloqueado, formulario pintado tarde), la URL de la
     *    página del formato: el envío AJAX la trae como referer con ?cmh_task=.
     */
    private static function submitted_task_id() {
        $id = (int) ( $_POST['cmh_task'] ?? 0 );
        if ( $id > 0 ) return $id;

        $ref = wp_get_referer() ?: (string) ( $_POST['_wp_http_referer'] ?? '' );
        if ( ! $ref ) return 0;
        $query = (string) wp_parse_url( $ref, PHP_URL_QUERY );
        if ( $query === '' ) return 0;
        parse_str( $query, $q );
        return max( 0, (int) ( $q['cmh_task'] ?? 0 ) );
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
        // v2.2 — Reglas en orden: gana la primera que se cumpla. Si ninguna lo
        // hace, queda el tipo por defecto del formato. Lo que hasta la v2.1 era
        // «un campo + tabla de valores» se migra solo a reglas con «es igual a».
        $maintenance_type = $cfg['maintenance_type'];
        $rules            = (array) ( $cfg['type_rules'] ?? [] );
        if ( $rules ) {
            $hit = self::match_type_rules( $data, $rules );
            if ( $hit !== '' ) {
                $maintenance_type = $hit;
            } else {
                $warnings[] = 'Ninguna regla del tipo de mantenimiento se cumplió: se usó «' . $maintenance_type . '».';
            }
        }

        // ── Sistema / falla ───────────────────────────────────────────────────
        // v2.6 — Se guardan TODOS los sistemas marcados, no solo el primero: un
        // checkbox trae varios y quedarse con uno falseaba la gráfica de averías
        // por sistema sin avisar de nada.
        $failure_system = '';
        if ( self::slug( $cfg, 'failure_system' ) ) {
            $raw            = self::field( $data, self::slug( $cfg, 'failure_system' ) );
            $failure_system = CMH_Taxonomy::systems_to_string( self::match_systems( $raw, $cfg ) );

            if ( ! $failure_system && self::selected_values( $raw ) ) {
                $warnings[] = 'El campo de sistema llegó con un valor que no se pudo interpretar.';
            }
        }

        // ── v2.9 — Tipo de mantenimiento preventivo (250H, 500H…) ─────────────
        // Igual que el sistema: si el formato no lo trae, queda vacío y no se
        // muestra. Si lo trae, es un preventivo —reprograma el próximo
        // mantenimiento—, salvo que las reglas ya lo hayan marcado como algo
        // que descuenta disponibilidad: una avería no se disfraza de preventivo.
        $mtto_level = '';
        if ( self::slug( $cfg, 'mtto_level' ) ) {
            $raw        = self::field( $data, self::slug( $cfg, 'mtto_level' ) );
            $mtto_level = self::match_level( $raw, $cfg );
            if ( $mtto_level !== '' && ! CMH_Taxonomy::mtype_affects( $maintenance_type ) ) {
                $maintenance_type = 'preventivo';
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
            'mtto_level'        => $mtto_level,
            'cost'              => $cost,
            'paid_amount'       => $paid,
            'payment_status'    => $pay_status,
            // v2.10.1 — Va al principio del nombre del PDF (savename «{hidden-1}{máquina}»):
            // con ella se busca el archivo por nombre exacto.
            'remission'         => $rem,
            'warnings'          => $warnings,
        ];
    }

    /** Valores marcados de un checkbox/radio/select, normalizados para comparar. */
    /**
     * v2.3 — Cierra la tarea que este envío da por hecha.
     *
     * Hasta la v2.10 se elegía solo por deducción (el número de tarea no viajaba
     * con el envío): tareas EN CURSO de la máquina, la del técnico que firma o la
     * más antigua; una pendiente nunca se cerraba.
     *
     * v2.9 — También cuentan las «Completadas sin formato»: justamente están
     * esperando este envío para cerrarse. Van primero, porque son las que ya
     * se dieron por hechas.
     *
     * v2.10.1 — Hasta aquí, una tarea que seguía «Pendiente» nunca se cerraba, y
     * seguía pendiente cada vez que el formato se abría sin pasar por «Abrir
     * formato» del técnico asignado (enlaces «Diligenciar formato», tareas sin
     * asignar, otro técnico). Ahora, en orden:
     *   1. Si el envío trae el número de tarea ($task_id) y es de esta máquina,
     *      se cierra esa, esté pendiente, en curso o sin formato.
     *   2. Si no, la deducción de siempre (en curso / sin formato), prefiriendo
     *      la del usuario que envía y luego la del nombre que firma.
     *   3. Si no hay ninguna en curso, la PENDIENTE de esta máquina asignada al
     *      técnico que envía (la de fecha más cercana): él mismo diligenció el
     *      formato de su máquina, que es la señal que antes faltaba.
     *
     * @param int $task_id Tarea explícita del envío, o 0.
     * @param int $user_id Usuario que envía, o 0 (reproceso desde los logs).
     * @return int  ID de la tarea cerrada, o 0.
     */
    public static function close_task_for_machine( $machine_id, $technician_name = '', $task_id = 0, $user_id = 0 ) {
        global $wpdb; $t = CMH_Core::tables();

        if ( class_exists( 'CMH_Schedule' ) && ! CMH_Schedule::setting( 'auto_complete_task' ) ) return 0;

        // Un administrador que envía para probar no es «el técnico que envía».
        $user_id = ( $user_id && user_can( $user_id, 'cmh_tech' ) ) ? (int) $user_id : 0;

        $chosen = null;

        // 1. La tarea exacta desde la que se abrió el formato.
        if ( $task_id ) {
            $chosen = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$t['tasks']} WHERE id=%d AND machine_id=%d AND status<>'completada'",
                (int) $task_id, (int) $machine_id
            ) );
        }

        // 2. Deducción entre las tareas en curso de la máquina.
        if ( ! $chosen ) {
            $tasks = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$t['tasks']} WHERE machine_id=%d AND status IN ('sin_formato','en_progreso')
                 ORDER BY FIELD(status,'sin_formato','en_progreso'), id ASC",
                (int) $machine_id
            ) );
            if ( $tasks ) $chosen = self::pick_task( $tasks, $technician_name, $user_id );
        }

        // 3. La pendiente del técnico que envía, en esta máquina.
        if ( ! $chosen && $user_id ) {
            $chosen = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$t['tasks']} WHERE machine_id=%d AND status='pendiente' AND assigned_to=%d
                 ORDER BY due_date IS NULL, ABS(DATEDIFF(due_date, %s)), id ASC LIMIT 1",
                (int) $machine_id, $user_id, current_time( 'Y-m-d' )
            ) );
        }

        if ( ! $chosen ) return 0;

        // Si ya estaba «sin formato», el trabajo terminó cuando la marcaron así:
        // se conserva esa fecha para que el cumplimiento no la cuente tarde
        // solo porque la firma llegó después.
        $data = [ 'status' => 'completada' ];
        if ( $chosen->status !== 'sin_formato' ) $data['updated_at'] = current_time( 'mysql' );
        $wpdb->update( $t['tasks'], $data, [ 'id' => (int) $chosen->id ] );

        // El reloj de horas para cuando para la tarea.
        if ( class_exists( 'CMH_Time' ) ) CMH_Time::on_status_change( $chosen, 'completada', 0 );

        return (int) $chosen->id;
    }

    /**
     * Entre varias tareas en curso de la misma máquina: primero la asignada al
     * usuario que envía; luego la del nombre que firma (exacto, ya normalizado);
     * si nada coincide, la primera (sin formato antes que en curso, la más antigua).
     */
    private static function pick_task( $tasks, $technician_name, $user_id ) {
        if ( $user_id ) {
            foreach ( $tasks as $ta ) if ( (int) $ta->assigned_to === $user_id ) return $ta;
        }
        if ( count( $tasks ) > 1 && $technician_name !== '' ) {
            // Nombre exacto: con «contiene», «Ana» le ganaba la tarea a «Juana».
            $needle = self::norm( $technician_name );
            foreach ( $tasks as $ta ) {
                if ( ! $ta->assigned_to ) continue;
                if ( self::norm( get_the_author_meta( 'display_name', (int) $ta->assigned_to ) ) === $needle ) return $ta;
            }
        }
        return $tasks[0];
    }

    // =========================================================================
    // Motor de reglas del tipo de mantenimiento (v2.2)
    // =========================================================================

    /**
     * Evalúa las reglas en orden y devuelve el tipo de la primera que se cumpla,
     * o '' si ninguna lo hace. El orden ES la prioridad: se sale al primer acierto.
     */
    public static function match_type_rules( $data, $rules ) {
        foreach ( (array) $rules as $r ) {
            if ( empty( $r['field'] ) || empty( $r['type'] ) ) continue;
            if ( self::rule_matches( $data, $r ) ) return (string) $r['type'];
        }
        return '';
    }

    /**
     * ¿Se cumple una regla sobre este envío?
     *
     * Las comparaciones de texto van contra `selected_values()`, que ya normaliza
     * (minúsculas, sin acentos) y desarma los campos de opción múltiple. Así una
     * regla escrita «Correctivo» reconoce igual un checkbox que llega como
     * ['correctivo'] que un texto suelto «CORRECTIVO».
     */
    private static function rule_matches( $data, $rule ) {
        $raw      = self::field( $data, $rule['field'] );
        $selected = self::selected_values( $raw );
        $needle   = self::norm( $rule['value'] ?? '' );
        $whole    = self::norm( self::human( $raw ) );
        $filled   = (bool) array_filter( $selected, function ( $v ) { return $v !== ''; } );

        switch ( $rule['op'] ) {
            case 'equals':
                return in_array( $needle, $selected, true );

            // «No es igual a» solo se cumple si HAY algo que comparar: un campo
            // vacío no debería disparar una regla de desigualdad.
            case 'not_equals':
                return $filled && ! in_array( $needle, $selected, true );

            // «Contiene» y «empieza por» miran el valor COMPLETO del campo, no
            // palabra por palabra: si no, «empieza por general» se cumpliría con
            // «Revisión General», que no es lo que nadie espera al escribir la
            // regla. `equals` sí sigue mirando los valores sueltos, que es como
            // se comparan los checkbox y como venía funcionando desde la v2.1.
            case 'contains':
                if ( $needle === '' ) return false;
                return strpos( $whole, $needle ) !== false;

            case 'starts_with':
                if ( $needle === '' ) return false;
                return strpos( $whole, $needle ) === 0;

            case 'filled': return $filled;
            case 'empty':  return ! $filled;

            // Numéricos: se comparan sobre el valor crudo, con el mismo lector
            // tolerante que usa el resto del plugin («1.234,5» incluido).
            case 'gt': return self::to_float( $raw ) >  self::to_float( $rule['value'] ?? '' );
            case 'lt': return self::to_float( $raw ) <  self::to_float( $rule['value'] ?? '' );
        }
        return false;
    }

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

    /**
     * Todos los sistemas de un envío (v2.6).
     *
     * Un checkbox llega como arreglo y un texto puede traer varios separados por
     * coma. Cada valor se resuelve por su cuenta y en tres pasos: la tabla de
     * traducción del formato, la taxonomía del plugin, y —si no está en ninguna—
     * se da de alta. Antes se descartaba, y el reporte salía incompleto en
     * silencio; el usuario puede renombrarlo luego desde Ajustes.
     *
     * @return string[] Claves, sin repetir y en el orden en que llegaron.
     */
    private static function match_systems( $raw, $cfg ) {
        $values = is_array( $raw )
            ? $raw
            : preg_split( '/\s*[,;|]\s*/', (string) self::human( $raw ) );

        $out = [];
        foreach ( (array) $values as $value ) {
            $value = trim( (string) self::human( $value ) );
            if ( $value === '' ) continue;
            $norm = self::norm( $value );

            $slug = '';
            foreach ( (array) $cfg['system_map'] as $needle => $mapped ) {
                if ( $mapped !== '' && self::norm( $needle ) === $norm ) { $slug = $mapped; break; }
            }
            // Sin traducción explícita se reconoce contra la taxonomía, incluso
            // por palabras sueltas: «Sistema hidráulico» debe caer en hidraulico
            // en vez de crear una entrada nueva casi igual.
            if ( ! $slug ) $slug = self::guess_system( self::selected_values( $value ) );
            if ( ! $slug ) $slug = CMH_Taxonomy::ensure_system( $value );

            if ( $slug !== '' && ! in_array( $slug, $out, true ) ) $out[] = $slug;
        }
        return $out;
    }

    /**
     * v2.9 — Clave del tipo de mantenimiento preventivo que trae el envío.
     * Uno solo: si el campo trae varios, gana el primero que se reconozca.
     * Orden: traducción del formato → lista del plugin → alta automática.
     */
    private static function match_level( $raw, $cfg ) {
        $values = is_array( $raw )
            ? $raw
            : preg_split( '/\s*[,;|]\s*/', (string) self::human( $raw ) );

        $labels = CMH_Taxonomy::mlevels();
        foreach ( (array) $values as $value ) {
            $value = trim( (string) self::human( $value ) );
            if ( $value === '' ) continue;
            $norm = self::norm( $value );

            foreach ( (array) ( $cfg['level_map'] ?? [] ) as $needle => $mapped ) {
                if ( $mapped !== '' && self::norm( $needle ) === $norm ) return $mapped;
            }
            // Contra la lista, sin que cuenten el prefijo, los espacios ni los signos.
            $key = CMH_Taxonomy::mlevel_key( $value );
            foreach ( $labels as $slug => $l ) {
                if ( $key !== '' && CMH_Taxonomy::mlevel_key( $l['label'] ) === $key ) return $slug;
            }
            $slug = CMH_Taxonomy::ensure_mlevel( $value );
            if ( $slug !== '' ) return $slug;
        }
        return '';
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
     * @param int $task_id v2.10.1 — tarea desde la que se abrió el formato (0 = no se sabe).
     * @param int $user_id v2.10.1 — usuario que envía (0 en el reproceso desde los logs).
     * @param int $submitted_at v2.10.1 — epoch del envío original (reproceso); 0 = ahora.
     * @return array ['created'=>bool, 'intervention_id'=>int, 'message'=>string]
     */
    public static function process_entry( $form_id, $data, $task_id = 0, $user_id = 0, $submitted_at = 0 ) {
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
            'failure_system'      => CMH_Taxonomy::systems_to_string( $p['failure_system'] ),
            'mtto_level'          => $p['mtto_level'] !== '' ? $p['mtto_level'] : null,
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

        // v2.3 — La tarea se da por terminada al llegar su intervención.
        // v2.10.1 — Si el formato se abrió desde una tarea, llega su número (input
        // `cmh_task`, fuera de los campos: `hidden-1` sigue intacto para E2PDF) y se
        // cierra ESA. Si no, se deduce por máquina y por quién envía.
        $closed = self::close_task_for_machine( (int) $machine->id, $p['technician'] ?? '', $task_id, $user_id );
        if ( $closed ) {
            CMH_Core::log( 'info', $form_id, $machine_code, $intervention_id,
                'Tarea #' . $closed . ' marcada como completada por este envío.', [] );
        }

        // Epoch real del envío (no la fecha de la BD): así la ventana de búsqueda es
        // inmune a la zona horaria de MySQL y coincide con el mtime real de los archivos.
        // v2.10.1 — En el reproceso desde los logs llega la hora del envío ORIGINAL:
        // con time() el PDF real (de hace días) quedaba fuera de la búsqueda.
        $submitted = $submitted_at > 0 ? (int) $submitted_at : time();
        $remission = (string) ( $p['remission'] ?? '' );
        self::find_pdf( $intervention_id, (int) $machine->id, $machine_code, $submitted, $remission, 0 );

        // Reintentos escalonados: E2PDF puede generar el PDF con retraso y, en sitios
        // de bajo tráfico, WP-Cron dispara tarde.
        // v2.10.1 — Cada intento lleva su número en los argumentos: WordPress
        // descarta un evento idéntico a otro que esté a menos de 10 minutos, así
        // que el de 300 s nunca corría. Y se alarga hasta 6 h.
        foreach ( self::PDF_RETRIES as $n => $delay ) {
            wp_schedule_single_event( time() + $delay, 'cmh_find_e2pdf_pdf_event',
                [ $intervention_id, (int) $machine->id, $machine_code, $submitted, $remission, $n + 1 ] );
        }

        return [ 'created' => true, 'intervention_id' => $intervention_id, 'message' => '' ];
    }

    // -------------------------------------------------------------------------
    // E2PDF — búsqueda y asociación de PDF
    // -------------------------------------------------------------------------

    /**
     * Busca el PDF de E2PDF de una intervención y lo copia a la carpeta del plugin.
     *
     * @param int    $submitted Epoch del envío (0 = llamada manual sin hora conocida).
     * @param string $remission v2.10.1 — remisión del envío: primera parte del nombre del PDF.
     * @param int    $attempt   v2.10.1 — 0 = al enviar, 1..N = reintento del cron, -1 = manual.
     */
    public static function find_pdf( $intervention_id, $machine_id, $machine_code, $submitted = 0, $remission = '', $attempt = -1 ) {
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

        // v2.10.1 — Un PDF que ya es de OTRA intervención de esta máquina no se
        // vuelve a asociar. Se compara el CONTENIDO (huella md5), no el nombre:
        // E2PDF puede repetir el nombre y sobrescribir el archivo, y entonces el
        // mismo nombre es un PDF nuevo y legítimo.
        $taken = [];
        foreach ( $wpdb->get_col( $wpdb->prepare(
            "SELECT file_path FROM {$t['files']} WHERE machine_id=%d AND intervention_id<>%d",
            (int) $machine_id, (int) $intervention_id
        ) ) as $p ) {
            if ( $p && is_file( $p ) && is_readable( $p ) ) $taken[ md5_file( $p ) ] = true;
        }

        $candidate = self::latest_pdf( $base, $machine_code, (int) $submitted, (string) $remission, $taken );
        if ( ! $candidate ) {
            $last = (int) $attempt >= count( self::PDF_RETRIES );
            CMH_Core::log( $last ? 'error' : 'warning', null, $machine_code, $intervention_id,
                $last
                    ? 'No apareció el PDF de E2PDF tras todos los reintentos. Revisa que el [e2pdf-save] del formato guarde en uploads/e2pdf con savename «{remisión}{campo de máquina}», o anéxalo a mano.'
                    : ( (int) $attempt >= 0 ? 'PDF de E2PDF aún no disponible. Se reintentará automáticamente.' : 'No se encontró un PDF con el nombre de esta intervención.' ),
                [] );
            return;
        }

        // Copiar el PDF a nuestro propio directorio para que persista aunque E2PDF lo elimine
        self::protect_storage();
        $safe_code  = sanitize_file_name( $machine_code );
        $our_dir    = $upload['basedir'] . '/cm-machine-history/' . $safe_code;
        $our_url    = $upload['baseurl'] . '/cm-machine-history/' . $safe_code;
        $store_path = $candidate;
        $store_url  = '';

        if ( ! is_dir( $our_dir ) ) wp_mkdir_p( $our_dir );
        if ( is_dir( $our_dir ) ) {
            // v2.10.1 — Una copia PROPIA por intervención, con su número delante.
            // Hasta aquí la copia llevaba el nombre del archivo de E2PDF y, si ya
            // existía uno igual del mismo tamaño, se reutilizaba (y antes de la
            // v2.9 se apuntaba al de E2PDF, que este sobrescribe). Cuando E2PDF
            // repite el nombre, todas las intervenciones acababan en el MISMO
            // archivo: la hoja de vida mostraba el mismo formato en todas.
            $name = 'iv' . (int) $intervention_id . '-' . basename( $candidate );
            $dst  = $our_dir . '/' . $name;
            if ( copy( $candidate, $dst ) ) {
                $store_path = $dst;
                $store_url  = esc_url_raw( set_url_scheme( $our_url . '/' . rawurlencode( $name ) ) );
            }
        }

        // Sin copia propia no se asocia: apuntar al archivo de E2PDF es justo lo
        // que hacía que varias intervenciones compartieran el mismo PDF.
        if ( ! $store_url ) {
            CMH_Core::log( 'error', null, $machine_code, $intervention_id,
                'Se encontró el PDF pero no se pudo copiar a uploads/cm-machine-history. Revisa los permisos de esa carpeta.', [] );
            return;
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
     * v2.10.1 — Reescrita. Hasta aquí, si el PDF propio aún no estaba en disco,
     * se tomaba «el más reciente» de CUALQUIER máquina generado en los 30 min
     * siguientes: así una intervención se quedaba con el PDF de otro cliente.
     * Además el código se buscaba como subcadena de la ruta entera («No. 1»
     * casaba con «No. 10») y ganaba el más NUEVO, no el del envío. Ahora:
     *
     *  - Solo se mira el NOMBRE del archivo, nunca la carpeta.
     *  - El código de máquina tiene que aparecer completo: no puede seguirle
     *    otra letra o número («No. 1» ya no casa con «No. 10»).
     *  - Con remisión (savename «{hidden-1}{máquina}»): lo que va antes del código
     *    debe ser EXACTAMENTE la remisión. Es la coincidencia buena.
     *  - Sin coincidencia exacta, se admite solo el código, y solo de esta
     *    máquina. Nunca un archivo sin el código.
     *  - Gana el MÁS CERCANO después del envío, no el más nuevo.
     *  - Los PDF cuyo contenido ya es de otra intervención ($taken) no cuentan.
     *
     * Trabaja con el epoch real del envío (`$submitted`), no con la fecha de la BD,
     * para ser inmune a la zona horaria de MySQL: `getMTime()` también es epoch real.
     *
     * @param string $base         Carpeta uploads/e2pdf.
     * @param string $machine_code Código de máquina (ej. "APC BOG TY No. 001").
     * @param int    $submitted    Epoch del envío. 0 → sin hora conocida: 30 días atrás.
     * @param string $remission    Remisión del envío ('' si el formato no la tiene).
     * @param array  $taken        Huellas md5 => true de los PDF ya asociados a otra intervención.
     */
    private static function latest_pdf( $base, $machine_code, $submitted = 0, $remission = '', $taken = [] ) {
        $code_re = self::code_regex( $machine_code );
        if ( ! $code_re ) return null;

        $rem = self::norm_code( $remission );
        $now = time();
        if ( $submitted > $now ) $submitted = $now; // defensa: nunca un envío en el futuro

        // Cota inferior (2 min de holgura por desfase de reloj).
        $min_mtime = $submitted ? ( $submitted - 120 ) : ( $now - 30 * DAY_IN_SECONDS );

        $exact = null; $exact_m = PHP_INT_MAX; // remisión + código
        $code  = null; $code_m  = PHP_INT_MAX; // solo el código

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $it as $file ) {
                if ( ! $file->isFile() ) continue;
                if ( strtolower( $file->getExtension() ) !== 'pdf' ) continue;
                $mtime = $file->getMTime();
                if ( $mtime < $min_mtime ) continue;

                $name = strtolower( remove_accents( pathinfo( $file->getFilename(), PATHINFO_FILENAME ) ) );
                if ( ! preg_match( $code_re, $name, $m, PREG_OFFSET_CAPTURE ) ) continue;

                $path = $file->getPathname();
                // El mismo contenido ya está en otra intervención: no es de esta.
                if ( $taken && isset( $taken[ md5_file( $path ) ] ) ) continue;
                if ( $rem !== '' && self::norm_code( substr( $name, 0, $m[0][1] ) ) === $rem ) {
                    if ( $mtime < $exact_m ) { $exact_m = $mtime; $exact = $path; }
                } elseif ( $mtime < $code_m ) {
                    $code_m = $mtime; $code = $path;
                }
            }
        } catch ( Exception $e ) {
            return null;
        }
        if ( $exact ) return $exact;

        // Con remisión conocida, un archivo con OTRA remisión delante es de otro
        // envío: solo se acepta el de solo código si no lleva remisión distinta,
        // es decir, si el nombre empieza por el código.
        if ( $code && $rem !== '' ) {
            $name = strtolower( remove_accents( pathinfo( $code, PATHINFO_FILENAME ) ) );
            preg_match( $code_re, $name, $m, PREG_OFFSET_CAPTURE );
            if ( self::norm_code( substr( $name, 0, $m[0][1] ) ) !== '' ) return null;
        }
        return $code;
    }

    /**
     * v2.10.1 — Patrón del código de máquina dentro de un nombre de archivo, en
     * minúsculas: sus partes en orden, con o sin separadores entre ellas, sin una
     * letra justo antes y sin letra ni número justo después. La remisión numérica
     * pegada delante («12345APC BOG…») sigue valiendo.
     */
    private static function code_regex( $machine_code ) {
        $parts = preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( (string) $machine_code ) ), -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $parts ) return '';
        $parts = array_map( function ( $p ) { return preg_quote( $p, '/' ); }, $parts );
        return '/(?<![a-z])' . implode( '[^a-z0-9]*', $parts ) . '(?![a-z0-9])/';
    }

    /** v2.10.1 — ¿El nombre de este archivo lleva el código de esa máquina? (revisión de PDFs) */
    public static function file_matches_machine( $file_name, $machine_code ) {
        $re = self::code_regex( $machine_code );
        return $re && preg_match( $re, strtolower( remove_accents( pathinfo( (string) $file_name, PATHINFO_FILENAME ) ) ) );
    }

    /**
     * v2.10.1 — La carpeta de copias (uploads/cm-machine-history) no se sirve
     * directo: los PDF se abren solo por el plugin (CMH_Admin::serve_file), que
     * comprueba quién puede ver cada máquina. Sin esto, cualquiera que adivinara
     * la URL (los códigos son predecibles) bajaba el PDF sin iniciar sesión.
     * Apache respeta el .htaccess; en nginx hay que bloquear la ruta en el servidor.
     */
    public static function protect_storage() {
        $dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'cm-machine-history';
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        if ( ! is_dir( $dir ) ) return;
        if ( ! file_exists( $dir . '/.htaccess' ) ) {
            @file_put_contents( $dir . '/.htaccess',
                "# CM Machine History: los archivos se abren solo a través del plugin.\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
        }
        if ( ! file_exists( $dir . '/index.php' ) ) @file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
    }

    /**
     * v2.10.1 — Búsqueda manual («Buscar PDF», y el rescate al abrir un archivo
     * que ya no está): usa la remisión y la fecha de la propia intervención, para
     * no traerse el PDF de otra más nueva de la misma máquina.
     */
    public static function find_pdf_for_intervention( $intervention_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT i.id, i.machine_id, i.observations, i.created_at, m.machine_code
             FROM {$t['interventions']} i JOIN {$t['machines']} m ON m.id=i.machine_id WHERE i.id=%d",
            (int) $intervention_id
        ) );
        if ( ! $row ) return false;

        $remission = preg_match( '/Remisi[oó]n:\s*(.+)\s*$/mu', (string) $row->observations, $mm ) ? trim( $mm[1] ) : '';
        // created_at lo pone MySQL y su zona horaria no se conoce: 12 h de margen.
        $created   = $row->created_at ? strtotime( $row->created_at ) : 0;
        $submitted = $created ? $created - 12 * HOUR_IN_SECONDS : 0;

        self::find_pdf( (int) $row->id, (int) $row->machine_id, $row->machine_code, $submitted, $remission, -1 );
        return true;
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
