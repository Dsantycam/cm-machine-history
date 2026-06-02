<?php
/**
 * CMH_Integration — captura de Forminator y asociación de PDFs E2PDF.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Integration {

    public static function init() {
        add_action( 'forminator_form_after_handle_submit', [ __CLASS__, 'capture_submit' ],    10, 10 );
        add_action( 'forminator_form_after_save_entry',    [ __CLASS__, 'capture_submit' ],    10, 10 );
        add_action( 'cmh_find_e2pdf_pdf_event',            [ __CLASS__, 'find_pdf' ],          10, 3  );
        add_action( 'wp_enqueue_scripts',                  [ __CLASS__, 'enqueue_frontend' ] );
    }

    public static function enqueue_frontend() {
        wp_enqueue_script( 'cmh-front', CMH_URL . 'assets/frontend.js', [ 'jquery' ], CMH_VERSION, true );
        wp_localize_script( 'cmh-front', 'CMHFront', [
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'formConfigs' => self::config(),
        ] );
    }

    // -------------------------------------------------------------------------
    // Configuración de formularios
    // -------------------------------------------------------------------------

    /**
     * Mapa de formularios Forminator conectados.
     * Clave: form_id de Forminator.
     */
    public static function config() {
        return [
            215 => [
                'form_type'        => 'combustion',
                'maintenance_type' => 'preventivo',
                'machine_field'    => 'text-14',
                'hourmeter_field'  => 'number-1',
                'date_field'       => 'date-1',
                'technician_field' => 'name-2',
                'remission_field'  => 'hidden-1',
                'contact_field'    => 'text-12',
                'observations_field' => 'textarea-1',
            ],
            225 => [
                'form_type'        => 'electricos',
                'maintenance_type' => 'preventivo',
                'machine_field'    => 'text-14',
                'hourmeter_field'  => 'number-1',
                'date_field'       => 'date-1',
                'technician_field' => 'name-2',
                'remission_field'  => 'hidden-1',
                'contact_field'    => 'text-12',
                'observations_field' => 'textarea-1',
            ],
            226 => [
                'form_type'              => 'correctivo',
                'maintenance_type'       => 'preventivo',   // fallback si el checkbox viene vacío
                'maintenance_type_field' => 'checkbox-1',   // "tipo de mantenimiento"
                'maintenance_type_map'   => [               // orden = prioridad (correctivo primero)
                    'correctivo'  => 'averia',
                    'evaluacion'  => 'evaluacion',
                    'remision'    => 'preventivo',
                    'preventivo'  => 'preventivo',
                ],
                'machine_field'          => 'text-6',
                'hourmeter_field'        => 'text-5',
                'date_field'             => 'date-1',
                'technician_field'       => 'name-2',
                'remission_field'        => 'hidden-1',
                'contact_field'          => 'text-4',
                'parts_field'            => 'textarea-1',
                'worked_hours_field'     => 'number-1',
                'downtime_hours_field'   => 'number-2',     // "horas detenida la máquina"
                'services_field'         => 'textarea-2',
                'observations_field'     => 'textarea-3',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Captura de envíos
    // -------------------------------------------------------------------------

    public static function capture_submit() {
        static $processed = [];

        $args    = func_get_args();
        $form_id = self::extract_form_id( $args );
        $cfg_map = self::config();

        if ( ! $form_id || empty( $cfg_map[ $form_id ] ) ) return;

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

        $cfg  = $cfg_map[ $form_id ];
        $data = self::flatten_submission_data( $args );
        if ( empty( $data ) ) $data = $_POST;

        $machine_code = strtoupper( trim( sanitize_text_field(
            self::field( $data, $cfg['machine_field'] )
        ) ) );
        $remission = self::field( $data, $cfg['remission_field'] ?? 'hidden-1' );
        $key       = 'f' . $form_id . '-' . md5( $machine_code . '|' . $remission . '|' . wp_json_encode( $data ) );

        if ( isset( $processed[ $key ] ) ) return;
        $processed[ $key ] = true;

        if ( ! $machine_code ) {
            CMH_Core::log( 'warning', $form_id, '', null, 'Campo máquina vacío en ' . $cfg['machine_field'], $data );
            return;
        }

        global $wpdb;
        $t = CMH_Core::tables();

        $machine = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['machines']} WHERE machine_code=%s", $machine_code
        ) );
        if ( ! $machine ) {
            CMH_Core::log( 'error', $form_id, $machine_code, null,
                'Código de máquina no encontrado en el sistema.', $data );
            return;
        }

        // Evitar duplicados por llave única de envío.
        if ( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['interventions']} WHERE e2pdf_entry_id=%s LIMIT 1", $key
        ) ) ) {
            CMH_Core::log( 'info', $form_id, $machine_code, null, 'Intervención duplicada ignorada.', [] );
            return;
        }

        $date       = self::normalize_date( self::field( $data, $cfg['date_field'] ?? 'date-1' ) );
        $hourmeter  = self::to_float( self::field( $data, $cfg['hourmeter_field'] ?? '' ) );
        $worked     = self::to_float( self::field( $data, $cfg['worked_hours_field'] ?? '' ) );
        $downtime   = self::to_float( self::field( $data, $cfg['downtime_hours_field'] ?? '' ) );
        $technician = self::human( self::field( $data, $cfg['technician_field'] ?? '' ) );
        $parts      = self::human( self::field( $data, $cfg['parts_field'] ?? '' ) );
        $services   = self::human( self::field( $data, $cfg['services_field'] ?? '' ) );
        $obs        = self::human( self::field( $data, $cfg['observations_field'] ?? '' ) );
        if ( $remission ) $obs = trim( $obs . "\nRemisión: " . self::human( $remission ) );

        // Determinar tipo de mantenimiento desde el campo checkbox/radio/select del formulario
        $maintenance_type = $cfg['maintenance_type'];
        if ( ! empty( $cfg['maintenance_type_field'] ) ) {
            $raw = self::field( $data, $cfg['maintenance_type_field'] );

            // Normalizar a array de strings sin acentos y en minúsculas
            if ( is_array( $raw ) ) {
                $selected = array_map( function ( $v ) {
                    return strtolower( remove_accents( trim( (string) $v ) ) );
                }, $raw );
            } else {
                $str      = strtolower( remove_accents( self::human( $raw ) ) );
                $selected = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $str ) ) );
            }

            // Recorrer el mapa en orden de prioridad (correctivo primero en el config)
            $map = $cfg['maintenance_type_map'] ?? [];
            foreach ( $map as $key => $mapped ) {
                if ( in_array( strtolower( remove_accents( $key ) ), $selected, true ) ) {
                    $maintenance_type = $mapped;
                    break;
                }
            }
        }

        $affects_av = CMH_Metrics::auto_affects_availability( $maintenance_type );

        $wpdb->insert( $t['interventions'], [
            'machine_id'          => (int) $machine->id,
            'forminator_form_id'  => (int) $form_id,
            'e2pdf_entry_id'      => $key,
            'intervention_date'   => $date ?: current_time( 'Y-m-d' ),
            'form_type'           => $cfg['form_type'],
            'maintenance_type'    => $maintenance_type,
            'technician'          => sanitize_text_field( $technician ),
            'hourmeter'           => $hourmeter,
            'worked_hours'        => $worked,
            'downtime_hours'      => $downtime,
            'cost'                => 0,
            'affects_availability'=> $affects_av,
            'parts'               => sanitize_textarea_field( $parts ),
            'services'            => sanitize_textarea_field( $services ),
            'observations'        => sanitize_textarea_field( $obs ),
        ] );

        if ( $wpdb->last_error ) {
            CMH_Core::log( 'error', $form_id, $machine_code, null,
                'Error DB: ' . $wpdb->last_error, $data );
            return;
        }

        $intervention_id = (int) $wpdb->insert_id;

        if ( $hourmeter > 0 ) {
            $wpdb->update( $t['machines'],
                [ 'current_hourmeter' => $hourmeter, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $machine->id ]
            );
        }

        CMH_Core::log( 'success', $form_id, $machine_code, $intervention_id,
            'Intervención creada desde Forminator.', [] );

        self::find_pdf( $intervention_id, (int) $machine->id, $machine_code );

        if ( ! wp_next_scheduled( 'cmh_find_e2pdf_pdf_event', [ $intervention_id, (int) $machine->id, $machine_code ] ) ) {
            wp_schedule_single_event( time() + 90, 'cmh_find_e2pdf_pdf_event',
                [ $intervention_id, (int) $machine->id, $machine_code ] );
        }
    }

    // -------------------------------------------------------------------------
    // E2PDF — búsqueda y asociación de PDF
    // -------------------------------------------------------------------------

    public static function find_pdf( $intervention_id, $machine_id, $machine_code ) {
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

        $candidate = self::latest_pdf( $base, $machine_code );
        if ( ! $candidate ) {
            CMH_Core::log( 'warning', null, $machine_code, $intervention_id,
                'PDF de E2PDF no encontrado en los últimos 15 min. Se reintentará.', [] );
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

    /** Busca el PDF más reciente (últimos 15 min) en uploads/e2pdf, priorizando los que contienen el código de máquina. */
    private static function latest_pdf( $base, $machine_code ) {
        $now  = time();
        $best = null;
        $top  = -1;

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $it as $file ) {
                if ( ! $file->isFile() ) continue;
                if ( strtolower( $file->getExtension() ) !== 'pdf' ) continue;
                $mtime = $file->getMTime();
                if ( $mtime < $now - 900 ) continue;

                $score = $mtime;
                $path  = $file->getPathname();
                if ( stripos( $path, $machine_code ) !== false ) $score += 1_000_000_000;
                if ( $score > $top ) { $top = $score; $best = $path; }
            }
        } catch ( Exception $e ) {
            return null;
        }
        return $best;
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

    public static function to_float( $value ) {
        $v = str_replace( ',', '.', self::human( $value ) );
        return is_numeric( $v ) ? (float) $v : 0.0;
    }

    public static function normalize_date( $value ) {
        $v = str_replace( '/', '-', self::human( $value ) );
        if ( ! $v ) return current_time( 'Y-m-d' );
        $ts = strtotime( $v );
        return $ts ? date( 'Y-m-d', $ts ) : current_time( 'Y-m-d' );
    }
}
