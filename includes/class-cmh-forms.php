<?php
/**
 * CMH_Forms — v2.0 Vinculador de formatos de Forminator.
 *
 * Hasta la v1.0.1 el mapeo de cada formulario (qué campo es la máquina, cuál el
 * horómetro, qué checkbox decide el tipo de mantenimiento…) vivía hardcodeado en
 * `CMH_Integration::config()`. Agregar un formato nuevo obligaba a tocar código,
 * publicar una versión y actualizar el plugin en el sitio, para algo que es
 * configuración y no lógica.
 *
 * Esta clase mueve esa configuración a la opción `cmh_forms` y le pone pantalla.
 * `CMH_Integration` ya leía todas las claves de forma defensiva (`?? ''`), así que
 * el cambio es de FUENTE, no de comportamiento.
 *
 * SIEMBRA Y RESPALDO. La primera vez, la opción se siembra desde el mapeo que
 * estaba en código (`legacy_seed()`), de modo que los formatos 215/225/226 quedan
 * exactamente como estaban. Si la opción se pierde o queda corrupta, `all()` cae
 * de nuevo a la siembra: la integración nunca se queda sin mapeo.
 *
 * FORMA DE UNA CONFIGURACIÓN (por form_id):
 *   enabled          bool     ¿se captura este formulario?
 *   label            string   nombre legible
 *   form_type        string   se guarda en interventions.form_type
 *   maintenance_type string   tipo por defecto si no hay campo que lo decida
 *   page_url         string   página donde está incrustado ('' = autodetectar)
 *   fields           array    destino => slug del campo (captura)
 *   type_field       string   campo que decide el tipo de mantenimiento
 *   type_map         array    valor del formulario => tipo del plugin
 *   system_map       array    valor del formulario => clave de sistema/falla
 *   prefill          array    slug del campo => fuente de dato (autorrelleno)
 *
 * PUENTE CON FORMINATOR. Listar formularios y sus campos se hace contra la API de
 * Forminator, envuelto en comprobaciones: si la API cambia o no está, la pantalla
 * degrada a escribir los slugs a mano en vez de romperse.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Forms {

    /** Opción donde vive la configuración de todos los formatos. */
    const OPTION = 'cmh_forms';

    /** Opción de una build intermedia que guardó solo las URLs; se absorbe aquí si existe. */
    const LEGACY_URL_OPTION = 'cmh_form_urls';

    // =========================================================================
    // Init
    // =========================================================================

    public static function init() {
        add_action( 'admin_post_cm_save_form_map',   [ __CLASS__, 'save_form_map' ] );
        add_action( 'admin_post_cm_delete_form_map', [ __CLASS__, 'delete_form_map' ] );
        add_action( 'admin_post_cm_toggle_form_map', [ __CLASS__, 'toggle_form_map' ] );
        add_action( 'admin_post_cm_duplicate_form_map', [ __CLASS__, 'duplicate_form_map' ] );
        add_action( 'admin_post_cm_reprocess_entry', [ __CLASS__, 'reprocess_entry' ] );
    }

    // =========================================================================
    // Catálogos: qué se puede capturar y qué se puede prellenar
    // =========================================================================

    /**
     * Destinos de captura: lo que el plugin sabe guardar de un envío.
     * La clave es el destino interno; el valor, cómo se le llama en la pantalla.
     */
    public static function capture_targets() {
        return [
            'machine'        => [ 'label' => 'Código de máquina',      'req' => true,  'hint' => 'Obligatorio. Sin esto no se puede registrar la intervención.' ],
            'date'           => [ 'label' => 'Fecha',                  'req' => false, 'hint' => 'Si falta, se usa la fecha del envío.' ],
            'hourmeter'      => [ 'label' => 'Horómetro',              'req' => false, 'hint' => 'Actualiza el horómetro de la máquina.' ],
            'technician'     => [ 'label' => 'Técnico',                'req' => false, 'hint' => '' ],
            'contact'        => [ 'label' => 'Contacto',               'req' => false, 'hint' => '' ],
            'remission'      => [ 'label' => 'Remisión / consecutivo', 'req' => false, 'hint' => 'Se anexa a las observaciones y ayuda a evitar duplicados.' ],
            'worked_hours'   => [ 'label' => 'Horas trabajadas',       'req' => false, 'hint' => '' ],
            'downtime_hours' => [ 'label' => 'Horas de parada',        'req' => false, 'hint' => 'Es lo que descuenta disponibilidad cuando el tipo es avería.' ],
            'failure_system' => [ 'label' => 'Sistema / falla',        'req' => false, 'hint' => 'Alimenta la gráfica «Averías por sistema».' ],
            'parts'          => [ 'label' => 'Repuestos',              'req' => false, 'hint' => '' ],
            'services'       => [ 'label' => 'Servicios',              'req' => false, 'hint' => '' ],
            'observations'   => [ 'label' => 'Observaciones',          'req' => false, 'hint' => '' ],
            'cost'           => [ 'label' => 'Costo',                  'req' => false, 'hint' => 'Opcional. Si se mapea, la intervención nace con su valor.' ],
            'paid_amount'    => [ 'label' => 'Monto abonado',          'req' => false, 'hint' => 'Opcional. El estado de pago se concilia solo.' ],
        ];
    }

    /** Fuentes de dato disponibles para el autorrelleno del formulario. */
    public static function prefill_sources() {
        return [
            'machine_code'            => 'Máquina — código',
            'brand'                   => 'Máquina — marca',
            'model'                   => 'Máquina — modelo',
            'serial'                  => 'Máquina — serial',
            'contact'                 => 'Máquina — contacto',
            'current_hourmeter'       => 'Máquina — horómetro actual',
            'scheduled_hours_monthly' => 'Máquina — horas programadas/mes',
            'next_maintenance_date'   => 'Máquina — próximo mantenimiento',
            'notes'                   => 'Máquina — notas',
            'company_name'            => 'Empresa',
            'city_name'               => 'Ciudad / Sucursal',
            'user_name'               => 'Usuario que abre el formato',
            'user_email'              => 'Correo del usuario que abre',
            'today'                   => 'Fecha de hoy',
            'task_title'              => 'Título de la tarea',
            'task_notes'              => 'Notas de la tarea',
        ];
    }

    /** Tipos de mantenimiento que entiende el plugin. */
    public static function maintenance_types() {
        return [
            'preventivo' => 'Preventivo',
            'correctivo' => 'Correctivo',
            'averia'     => 'Avería',
            'evaluacion' => 'Evaluación',
        ];
    }

    // =========================================================================
    // Almacenamiento
    // =========================================================================

    /** Forma de una configuración vacía. */
    public static function blank() {
        return [
            'enabled'          => 1,
            'label'            => '',
            'form_type'        => '',
            'maintenance_type' => 'preventivo',
            'page_url'         => '',
            'fields'           => [],
            'type_field'       => '',
            'type_map'         => [],
            'system_map'       => [],
            'prefill'          => [],
        ];
    }

    /**
     * Mapeo que estaba hardcodeado hasta la v1.0.1, en la forma nueva.
     * Es la siembra inicial y también el respaldo si la opción se pierde.
     */
    public static function legacy_seed() {
        return [
            215 => [
                'enabled'          => 1,
                'label'            => 'Preventivo — Combustión',
                'form_type'        => 'combustion',
                'maintenance_type' => 'preventivo',
                'page_url'         => '',
                'fields'           => [
                    'machine'      => 'text-14',
                    'hourmeter'    => 'number-1',
                    'date'         => 'date-1',
                    'technician'   => 'name-2',
                    'remission'    => 'hidden-1',
                    'contact'      => 'text-12',
                    'observations' => 'textarea-1',
                ],
                'type_field' => '',
                'type_map'   => [],
                'system_map' => [],
                'prefill'    => [],
            ],
            225 => [
                'enabled'          => 1,
                'label'            => 'Preventivo — Eléctricos',
                'form_type'        => 'electricos',
                'maintenance_type' => 'preventivo',
                'page_url'         => '',
                'fields'           => [
                    'machine'      => 'text-14',
                    'hourmeter'    => 'number-1',
                    'date'         => 'date-1',
                    'technician'   => 'name-2',
                    'remission'    => 'hidden-1',
                    'contact'      => 'text-12',
                    'observations' => 'textarea-1',
                ],
                'type_field' => '',
                'type_map'   => [],
                'system_map' => [],
                'prefill'    => [],
            ],
            226 => [
                'enabled'          => 1,
                'label'            => 'Correctivo / Avería / Evaluación',
                'form_type'        => 'correctivo',
                'maintenance_type' => 'preventivo',   // respaldo si el checkbox viene vacío
                'page_url'         => '',
                'fields'           => [
                    'machine'        => 'text-6',
                    'hourmeter'      => 'text-5',
                    'date'           => 'date-1',
                    'technician'     => 'name-2',
                    'remission'      => 'hidden-1',
                    'contact'        => 'text-4',
                    'parts'          => 'textarea-1',
                    'worked_hours'   => 'number-1',
                    'downtime_hours' => 'number-2',
                    'services'       => 'textarea-2',
                    'observations'   => 'textarea-3',
                ],
                'type_field' => 'checkbox-1',
                // El orden es prioridad: correctivo primero, como en el config original.
                'type_map'   => [
                    'correctivo' => 'averia',
                    'evaluacion' => 'evaluacion',
                    'remision'   => 'preventivo',
                    'preventivo' => 'preventivo',
                ],
                'system_map' => [],
                'prefill'    => [],
            ],
        ];
    }

    /** Todas las configuraciones guardadas (incluidas las desactivadas). */
    public static function all() {
        $saved = get_option( self::OPTION, null );
        if ( ! is_array( $saved ) || ! $saved ) return self::legacy_seed();

        $out = [];
        foreach ( $saved as $id => $cfg ) {
            $id = (int) $id;
            if ( $id > 0 && is_array( $cfg ) ) $out[ $id ] = self::normalize( $cfg );
        }
        return $out ?: self::legacy_seed();
    }

    /** Solo los formatos activos: es lo que la integración debe capturar. */
    public static function enabled() {
        return array_filter( self::all(), function ( $c ) { return ! empty( $c['enabled'] ); } );
    }

    public static function get( $form_id ) {
        $all = self::all();
        return $all[ (int) $form_id ] ?? null;
    }

    public static function exists( $form_id ) {
        return self::get( $form_id ) !== null;
    }

    /** Completa las claves que falten para que ningún consumidor tenga que adivinar. */
    public static function normalize( $cfg ) {
        $c = array_merge( self::blank(), is_array( $cfg ) ? $cfg : [] );
        $c['enabled']          = ! empty( $c['enabled'] ) ? 1 : 0;
        $c['fields']           = is_array( $c['fields'] )     ? $c['fields']     : [];
        $c['type_map']         = is_array( $c['type_map'] )   ? $c['type_map']   : [];
        $c['system_map']       = is_array( $c['system_map'] ) ? $c['system_map'] : [];
        $c['prefill']          = is_array( $c['prefill'] )    ? $c['prefill']    : [];
        $c['maintenance_type'] = isset( self::maintenance_types()[ $c['maintenance_type'] ] )
            ? $c['maintenance_type'] : 'preventivo';
        return $c;
    }

    /** Guarda (o reemplaza) la configuración de un formato. */
    public static function save( $form_id, $cfg ) {
        $all = self::all();
        $all[ (int) $form_id ] = self::normalize( $cfg );
        ksort( $all );
        update_option( self::OPTION, $all, true );
        self::flush_caches();
    }

    public static function remove( $form_id ) {
        $all = self::all();
        unset( $all[ (int) $form_id ] );
        update_option( self::OPTION, $all, true );
        self::flush_caches();
    }

    /**
     * Siembra inicial, absorbiendo la opción de URLs de una build intermedia si existiera.
     * Idempotente: se puede llamar en cada activación.
     */
    public static function maybe_seed() {
        $saved = get_option( self::OPTION, null );
        if ( ! is_array( $saved ) || ! $saved ) {
            $seed = self::legacy_seed();

            // Una build intermedia guardó las páginas aparte; se absorben aquí.
            $urls = get_option( self::LEGACY_URL_OPTION, [] );
            if ( is_array( $urls ) ) {
                foreach ( $urls as $id => $url ) {
                    if ( isset( $seed[ (int) $id ] ) && $url ) $seed[ (int) $id ]['page_url'] = $url;
                }
            }
            update_option( self::OPTION, $seed, true );
        }
    }

    private static function flush_caches() {
        foreach ( array_keys( self::all() ) as $id ) delete_transient( 'cmh_form_url_' . $id );
    }

    // =========================================================================
    // Puente con Forminator — siempre defensivo
    // =========================================================================

    public static function forminator_available() {
        return class_exists( 'Forminator_API' );
    }

    /** [ form_id => nombre ] de los formularios de Forminator, o [] si no se puede. */
    public static function forminator_forms() {
        if ( ! self::forminator_available() ) return [];
        try {
            $forms = Forminator_API::get_forms( null, 1, 300 );
        } catch ( \Throwable $e ) {
            return [];
        }
        $out = [];
        foreach ( (array) $forms as $f ) {
            $id = 0; $name = '';
            if ( is_object( $f ) ) {
                $id = isset( $f->id ) ? (int) $f->id : 0;
                if ( method_exists( $f, 'get_name' ) )        $name = (string) $f->get_name();
                elseif ( isset( $f->name ) )                  $name = (string) $f->name;
                elseif ( isset( $f->settings['formName'] ) )  $name = (string) $f->settings['formName'];
            } elseif ( is_array( $f ) ) {
                $id   = (int) ( $f['id'] ?? 0 );
                $name = (string) ( $f['name'] ?? '' );
            }
            if ( $id ) $out[ $id ] = $name !== '' ? $name : ( 'Formulario ' . $id );
        }
        return $out;
    }

    /**
     * [ slug => etiqueta ] de los campos de un formulario.
     * Devuelve [] si Forminator no está o si su API no responde como esperamos:
     * en ese caso la pantalla deja escribir los slugs a mano.
     */
    public static function forminator_fields( $form_id ) {
        if ( ! self::forminator_available() ) return [];
        try {
            $model = Forminator_API::get_form( (int) $form_id );
        } catch ( \Throwable $e ) {
            return [];
        }
        if ( ! is_object( $model ) || ! method_exists( $model, 'get_fields' ) ) return [];

        $out = [];
        foreach ( (array) $model->get_fields() as $f ) {
            $raw = [];
            if ( is_object( $f ) ) {
                $raw = ( isset( $f->raw ) && is_array( $f->raw ) ) ? $f->raw : get_object_vars( $f );
            } elseif ( is_array( $f ) ) {
                $raw = $f;
            }
            $slug  = (string) ( $raw['element_id'] ?? ( is_object( $f ) && isset( $f->slug ) ? $f->slug : '' ) );
            $label = (string) ( $raw['field_label'] ?? ( $raw['label'] ?? '' ) );
            if ( $slug === '' ) continue;
            $out[ $slug ] = $label !== '' ? $label : $slug;
        }
        return $out;
    }

    /**
     * Último envío real del formulario, aplanado a [ slug => valor ].
     * Es lo que alimenta el probador de mapeo.
     */
    public static function forminator_last_entry( $form_id ) {
        if ( ! self::forminator_available() ) return [];
        try {
            $entries = Forminator_API::get_entries( (int) $form_id, 1 );
        } catch ( \Throwable $e ) {
            return [];
        }
        $entry = is_array( $entries ) ? reset( $entries ) : $entries;
        if ( ! is_object( $entry ) || ! isset( $entry->meta_data ) || ! is_array( $entry->meta_data ) ) return [];

        $out = [];
        foreach ( $entry->meta_data as $slug => $meta ) {
            $out[ $slug ] = is_array( $meta ) && array_key_exists( 'value', $meta ) ? $meta['value'] : $meta;
        }
        return $out;
    }

    /**
     * Sugerencia de mapeo a partir de las etiquetas del formulario.
     *
     * Es la misma heurística que usaba el autorrelleno hasta la v1.0.1, pero
     * degradada a lo que siempre debió ser: una propuesta al configurar, que se
     * revisa una vez, y no una adivinanza en cada envío.
     */
    public static function suggest_mapping( $form_id, $fields = null ) {
        $fields = $fields === null ? self::forminator_fields( $form_id ) : $fields;
        if ( ! $fields ) return [];

        $kw = [
            'machine'        => [ 'código de máquina', 'codigo de maquina', 'máquina', 'maquina', 'equipo', 'placa' ],
            'date'           => [ 'fecha' ],
            'hourmeter'      => [ 'horómetro', 'horometro', 'hourmeter', 'odómetro', 'odometro' ],
            'technician'     => [ 'técnico', 'tecnico', 'responsable', 'quien realiza' ],
            'contact'        => [ 'contacto', 'encargado', 'operador' ],
            'remission'      => [ 'remisión', 'remision', 'consecutivo', 'número de orden', 'numero de orden' ],
            'worked_hours'   => [ 'horas trabajadas', 'tiempo de trabajo', 'duración del trabajo' ],
            'downtime_hours' => [ 'detenida', 'parada', 'inactiv', 'fuera de servicio' ],
            'failure_system' => [ 'sistema', 'falla', 'componente' ],
            'parts'          => [ 'repuesto', 'parte', 'insumo' ],
            'services'       => [ 'servicio', 'trabajo realizado', 'actividad' ],
            'observations'   => [ 'observacion', 'observación', 'nota', 'comentario' ],
            'cost'           => [ 'costo', 'valor', 'precio', 'total a pagar' ],
        ];

        $out  = [];
        $used = [];
        foreach ( $kw as $target => $words ) {
            foreach ( $fields as $slug => $label ) {
                if ( in_array( $slug, $used, true ) ) continue;
                $l = strtolower( remove_accents( $label ) );
                foreach ( $words as $w ) {
                    if ( strpos( $l, strtolower( remove_accents( $w ) ) ) !== false ) {
                        $out[ $target ] = $slug;
                        $used[]         = $slug;
                        continue 3;
                    }
                }
            }
        }
        return $out;
    }

    /** Campos mapeados que ya no existen en el formulario (slug renumerado o borrado). */
    public static function broken_fields( $form_id, $cfg = null ) {
        $fields = self::forminator_fields( $form_id );
        if ( ! $fields ) return [];   // sin catálogo no se puede afirmar nada

        $cfg  = $cfg ?: self::get( $form_id );
        if ( ! $cfg ) return [];

        $used = array_filter( (array) $cfg['fields'] );
        if ( ! empty( $cfg['type_field'] ) ) $used['type_field'] = $cfg['type_field'];
        foreach ( array_keys( (array) $cfg['prefill'] ) as $slug ) $used[ 'prefill:' . $slug ] = $slug;

        $broken = [];
        foreach ( $used as $target => $slug ) {
            if ( $slug !== '' && ! isset( $fields[ $slug ] ) ) $broken[ $target ] = $slug;
        }
        return $broken;
    }

    // =========================================================================
    // Autorrelleno — se resuelve en el servidor, no en el navegador
    // =========================================================================

    /**
     * Valores ya resueltos para prellenar, por formato: [ form_id => [ slug => valor ] ].
     *
     * Se calcula aquí y no en JavaScript a propósito: el navegador no tiene por qué
     * saber de qué máquina se trata ni pedirla por AJAX para poder pintar el
     * formulario. Si el usuario no está autenticado, las fuentes de usuario y de
     * tarea quedan vacías.
     */
    public static function resolve_prefill( $machine_code, $task_id = 0 ) {
        $machine_code = strtoupper( trim( (string) $machine_code ) );
        if ( $machine_code === '' ) return [];

        global $wpdb; $t = CMH_Core::tables();
        $machine = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             WHERE m.machine_code=%s", $machine_code
        ) );
        if ( ! $machine ) return [];

        $task = null;
        if ( $task_id ) {
            $task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['tasks']} WHERE id=%d", (int) $task_id ) );
            // La tarea solo aporta datos si es de esta misma máquina.
            if ( $task && (int) $task->machine_id !== (int) $machine->id ) $task = null;
        }

        $user = is_user_logged_in() ? wp_get_current_user() : null;

        $out = [];
        foreach ( self::enabled() as $form_id => $cfg ) {
            $map = [];
            foreach ( (array) $cfg['prefill'] as $slug => $source ) {
                $v = self::prefill_value( $source, $machine, $task, $user );
                if ( $v !== '' ) $map[ $slug ] = $v;
            }
            if ( $map ) $out[ $form_id ] = $map;
        }
        return $out;
    }

    private static function prefill_value( $source, $machine, $task, $user ) {
        switch ( $source ) {
            case 'machine_code':            return (string) $machine->machine_code;
            case 'brand':                   return (string) $machine->brand;
            case 'model':                   return (string) $machine->model;
            case 'serial':                  return (string) $machine->serial;
            case 'contact':                 return (string) $machine->contact;
            case 'current_hourmeter':       return (string) ( 0 + $machine->current_hourmeter );
            case 'scheduled_hours_monthly': return (string) ( 0 + $machine->scheduled_hours_monthly );
            case 'next_maintenance_date':   return (string) ( $machine->next_maintenance_date ?: '' );
            case 'notes':                   return (string) $machine->notes;
            case 'company_name':            return (string) $machine->company_name;
            case 'city_name':               return (string) $machine->city_name;
            case 'user_name':               return $user ? (string) $user->display_name : '';
            case 'user_email':              return $user ? (string) $user->user_email : '';
            case 'today':                   return current_time( 'Y-m-d' );
            case 'task_title':              return $task ? (string) $task->title : '';
            case 'task_notes':              return $task ? (string) $task->notes : '';
        }
        return '';
    }

    // =========================================================================
    // Página «Máquinas → Formatos»
    // =========================================================================

    public static function page_forms() {
        if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( 'Sin permisos.' );

        $edit = isset( $_GET['form'] ) ? (int) $_GET['form'] : 0;
        if ( $edit ) return self::page_edit( $edit );

        $all       = self::all();
        $available = self::forminator_forms();

        CMH_Admin::page_header( 'Formatos', [ [ 'label' => 'Formatos' ] ] );

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Integración</div>'
            . '<h2>Formatos de Forminator vinculados</h2>'
            . '<p>Qué formularios se capturan, qué campo es qué, y con qué datos llegan prellenados.</p>'
            . '</div></div>';

        if ( ! self::forminator_available() ) {
            echo '<div class="cmh-note" style="border-left:4px solid #dba617;margin-bottom:16px">'
                . 'No se pudo hablar con Forminator, así que no hay lista de formularios ni de campos: '
                . 'los slugs se escriben a mano (por ejemplo <code>text-14</code>). Todo lo demás funciona igual.</div>';
        }

        echo '<div class="cmh-layout"><div class="cmh-main"><div class="cmh-panel">'
            . '<h2>Formatos vinculados <small style="font-weight:400;font-size:13px;color:#646970">— ' . count( $all ) . '</small></h2>'
            . '<table class="widefat cmh"><thead><tr>'
            . '<th>Formato</th><th>ID</th><th>Tipo por defecto</th><th>Página</th><th>Estado</th><th></th>'
            . '</tr></thead><tbody>';

        foreach ( $all as $id => $cfg ) {
            $broken = self::broken_fields( $id, $cfg );
            $url    = CMH_Integration::form_url( $id );
            echo '<tr>'
                . '<td><strong>' . esc_html( $cfg['label'] ?: ( 'Formulario ' . $id ) ) . '</strong>'
                . ( $broken ? '<br><span class="cmh-badge" style="background:#fce8e8;color:#d63638">'
                    . count( $broken ) . ' campo(s) que ya no existen</span>' : '' )
                . '</td>'
                . '<td><code>' . intval( $id ) . '</code></td>'
                . '<td>' . esc_html( self::maintenance_types()[ $cfg['maintenance_type'] ] ?? '—' )
                . ( $cfg['type_field'] ? ' <span style="font-size:11px;color:#646970">(lo decide ' . esc_html( $cfg['type_field'] ) . ')</span>' : '' )
                . '</td>'
                . '<td style="font-size:12px">' . ( $url
                    ? '<a target="_blank" rel="noopener" href="' . esc_url( $url ) . '">Ver</a>'
                    : '<span style="color:#d63638">Sin página</span>' ) . '</td>'
                . '<td>' . ( $cfg['enabled']
                    ? '<span class="cmh-badge" style="background:#e6f4ea;color:#1a6630">Activo</span>'
                    : '<span class="cmh-badge" style="background:#f0f0f1;color:#3c434a">Inactivo</span>' ) . '</td>'
                . '<td style="display:flex;gap:6px;flex-wrap:wrap">'
                . '<a class="button button-small" href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-forms', [ 'form' => $id ] ) ) . '">Editar</a>'
                . self::mini_form( 'cm_toggle_form_map', $id, $cfg['enabled'] ? 'Desactivar' : 'Activar' )
                . self::mini_form( 'cm_delete_form_map', $id, 'Eliminar', '¿Eliminar la configuración de este formato? Los envíos dejarán de capturarse.' )
                . '</td></tr>';
        }
        if ( ! $all ) echo '<tr><td colspan="6"><em style="color:#646970">Sin formatos vinculados.</em></td></tr>';
        echo '</tbody></table></div></div>';

        // ── Vincular uno nuevo ────────────────────────────────────────────────
        echo '<div class="cmh-side"><div class="cmh-panel"><h2>Vincular formato nuevo</h2>';
        CMH_Admin::form_start( 'cm_save_form_map' );
        echo '<input type="hidden" name="is_new" value="1">';

        $free = array_diff_key( $available, $all );
        if ( $free ) {
            echo '<label>Formulario de Forminator<select name="form_id" required>'
                . '<option value="">— Seleccionar —</option>';
            foreach ( $free as $id => $name )
                echo '<option value="' . intval( $id ) . '">' . esc_html( $name . ' (' . $id . ')' ) . '</option>';
            echo '</select></label>'
                . '<p style="font-size:12px;color:#646970;margin:6px 0 12px">Al guardar se abre la pantalla de mapeo con los campos del formulario ya sugeridos.</p>';
        } else {
            echo '<label>ID del formulario<input type="number" name="form_id" min="1" required placeholder="231"></label>'
                . '<p style="font-size:12px;color:#646970;margin:6px 0 12px">'
                . ( $available ? 'Todos los formularios detectados ya están vinculados.' : 'Escribe el ID que aparece en Forminator.' )
                . '</p>';
        }
        echo '<button class="button button-primary">Vincular y mapear</button></form></div>';

        // ── Duplicar ──────────────────────────────────────────────────────────
        if ( $all ) {
            echo '<div class="cmh-panel"><h2>Duplicar configuración</h2>'
                . '<p style="font-size:12px;color:#646970;margin:-6px 0 10px">Copia el mapeo completo de un formato a otro formulario. Útil cuando el nuevo se parece a uno que ya funciona.</p>';
            CMH_Admin::form_start( 'cm_duplicate_form_map' );
            echo '<label>Copiar de<select name="from" required>';
            foreach ( $all as $id => $cfg )
                echo '<option value="' . intval( $id ) . '">' . esc_html( ( $cfg['label'] ?: $id ) . ' (' . $id . ')' ) . '</option>';
            echo '</select></label>'
                . '<label>Al formulario con ID<input type="number" name="to" min="1" required placeholder="231"></label>'
                . '<button class="button button-primary">Duplicar</button></form></div>';
        }

        echo '</div></div>';
        CMH_Admin::page_footer();
    }

    /** Formulario mínimo de una sola acción sobre un formato. */
    private static function mini_form( $action, $form_id, $label, $confirm = '' ) {
        $onsub = $confirm ? ' onsubmit="return confirm(' . esc_attr( json_encode( $confirm ) ) . ')"' : '';
        $style = $action === 'cm_delete_form_map' ? ' style="color:#d63638;border-color:#d63638"' : '';
        return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $onsub . '>'
            . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
            . '<input type="hidden" name="form_id" value="' . intval( $form_id ) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
            . '<button class="button button-small"' . $style . '>' . esc_html( $label ) . '</button></form>';
    }

    // =========================================================================
    // Pantalla de mapeo de un formato
    // =========================================================================

    private static function page_edit( $form_id ) {
        $cfg    = self::get( $form_id );
        $is_new = ! $cfg;
        if ( $is_new ) {
            $cfg           = self::blank();
            $cfg['fields'] = self::suggest_mapping( $form_id );
            $names         = self::forminator_forms();
            $cfg['label']  = $names[ $form_id ] ?? ( 'Formulario ' . $form_id );
        }

        $fields = self::forminator_fields( $form_id );
        $broken = self::broken_fields( $form_id, $cfg );
        $back   = CMH_Admin::admin_url( CMH_SLUG . '-forms' );

        CMH_Admin::page_header( $cfg['label'] ?: ( 'Formulario ' . $form_id ), [
            [ 'label' => 'Formatos', 'url' => $back ],
            [ 'label' => $cfg['label'] ?: ( 'Formulario ' . $form_id ) ],
        ] );

        if ( $is_new ) {
            echo '<div class="cmh-note" style="border-left:4px solid #2271b1;margin-bottom:16px">'
                . 'Formato nuevo. ' . ( $fields
                    ? 'Los campos vienen <strong>sugeridos</strong> por el nombre de cada campo del formulario: revísalos antes de guardar.'
                    : 'No se pudieron leer los campos del formulario, así que hay que escribir los slugs a mano.' )
                . '</div>';
        }

        if ( $broken ) {
            echo '<div class="cmh-note" style="border-left:4px solid #d63638;margin-bottom:16px">'
                . '<strong>Hay campos mapeados que ya no existen en el formulario.</strong> '
                . 'Forminator renumera los slugs cuando se borra y se vuelve a crear un campo, y un campo perdido llega vacío sin avisar. Revisa: <code>'
                . esc_html( implode( '</code>, <code>', array_values( $broken ) ) ) . '</code>.</div>';
        }

        echo '<div class="cmh-layout"><div class="cmh-main">';

        CMH_Admin::form_start( 'cm_save_form_map' );
        echo '<input type="hidden" name="form_id" value="' . intval( $form_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-forms', [ 'form' => $form_id ] ) ) . '">';

        // ── Datos generales ───────────────────────────────────────────────────
        echo '<div class="cmh-panel"><h2>Datos del formato</h2><div class="cmh-form-grid">'
            . '<label>Nombre <em>*</em><input name="label" value="' . esc_attr( $cfg['label'] ) . '" required></label>'
            . '<label>Etiqueta interna <span class="cmh-optional">(form_type)</span>'
            . '<input name="form_type" value="' . esc_attr( $cfg['form_type'] ) . '" placeholder="combustion"></label>'
            . '<label>Tipo de mantenimiento por defecto<select name="maintenance_type">';
        foreach ( self::maintenance_types() as $k => $v )
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $cfg['maintenance_type'], $k, false ) . '>' . esc_html( $v ) . '</option>';
        echo '</select></label>'
            . '<label>Página donde está el formulario<input type="url" name="page_url" value="' . esc_attr( $cfg['page_url'] ) . '" placeholder="' . esc_attr( CMH_Integration::detect_form_url( $form_id ) ?: 'https://tusitio.com/formato-…' ) . '"></label>'
            . '</div>'
            . '<label style="margin-top:10px"><input type="checkbox" name="enabled" value="1" ' . checked( $cfg['enabled'], 1, false ) . '> '
            . 'Capturar los envíos de este formulario</label>'
            . '<p style="font-size:12px;color:#646970;margin:6px 0 0">La página se usa para abrir el formato prellenado desde una tarea. Vacío = se detecta sola.</p>'
            . '</div>';

        // ── Captura ───────────────────────────────────────────────────────────
        echo '<div class="cmh-panel"><h2>Qué se guarda de cada envío</h2>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Cada dato del sistema se toma del campo que elijas. Lo que dejes vacío simplemente no se guarda.</p>'
            . '<table class="widefat cmh"><thead><tr><th style="width:32%">Dato</th><th>Campo del formulario</th></tr></thead><tbody>';
        foreach ( self::capture_targets() as $key => $meta ) {
            echo '<tr><td><strong>' . esc_html( $meta['label'] ) . '</strong>'
                . ( $meta['req'] ? ' <em style="color:#d63638">*</em>' : '' )
                . ( $meta['hint'] ? '<br><span style="font-size:11px;color:#646970">' . esc_html( $meta['hint'] ) . '</span>' : '' )
                . '</td><td>' . self::field_input( 'fields[' . $key . ']', $cfg['fields'][ $key ] ?? '', $fields, $meta['req'] ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        // ── Tipo de mantenimiento por valor ───────────────────────────────────
        echo '<div class="cmh-panel"><h2>Tipo de mantenimiento según lo que marque el técnico</h2>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Si el formulario tiene un checkbox, radio o lista que decide el tipo, indícalo aquí y traduce sus valores. '
            . 'El orden importa: gana la primera coincidencia. Marcar «avería» descuenta disponibilidad automáticamente.</p>'
            . '<label>Campo que decide el tipo' . self::field_input( 'type_field', $cfg['type_field'], $fields, false ) . '</label>';
        self::map_rows( 'type_map', $cfg['type_map'], self::maintenance_types(), 'Valor en el formulario', 'Tipo del plugin' );
        echo '</div>';

        // ── Sistema / falla ───────────────────────────────────────────────────
        echo '<div class="cmh-panel"><h2>Traducción del sistema / falla</h2>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Traduce lo que dice el formulario a la taxonomía del plugin, que es la que alimenta «Averías por sistema». '
            . 'Si un valor no está en la tabla se intenta reconocer solo; si no se logra, queda como «Sin especificar» y se anota en los logs.</p>';
        self::map_rows( 'system_map', $cfg['system_map'], CMH_Admin::failure_systems(), 'Valor en el formulario', 'Sistema del plugin' );
        echo '</div>';

        // ── Autorrelleno ──────────────────────────────────────────────────────
        echo '<div class="cmh-panel"><h2>Qué llega prellenado</h2>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Cuando el formato se abre desde una tarea o desde la ficha de la máquina, estos campos llegan llenos. '
            . 'Elige exactamente qué campo recibe qué dato: nada se adivina.</p>';
        self::prefill_rows( $cfg['prefill'], $fields );
        echo '</div>';

        echo '<p><button class="button button-primary">Guardar mapeo</button> '
            . '<a class="button" href="' . esc_url( $back ) . '">Volver</a></p></form>';

        echo '</div><div class="cmh-side">';

        // ── Probador ──────────────────────────────────────────────────────────
        echo '<div class="cmh-panel"><h2>Probador de mapeo</h2>';
        if ( $is_new ) {
            echo '<p style="font-size:13px;color:#646970;margin:0">Guarda el mapeo para poder probarlo.</p>';
        } else {
            echo '<p style="font-size:12px;color:#646970;margin:-6px 0 10px">Toma el <strong>último envío real</strong> de este formulario y muestra qué extraería el plugin. No crea ni modifica nada.</p>';
            self::render_tester( $form_id, $cfg );
        }
        echo '</div>';

        // ── Campos del formulario, como referencia ────────────────────────────
        if ( $fields ) {
            echo '<div class="cmh-panel"><h2>Campos del formulario</h2>'
                . '<table class="widefat cmh"><thead><tr><th>Slug</th><th>Etiqueta</th></tr></thead><tbody>';
            foreach ( $fields as $slug => $label )
                echo '<tr><td><code>' . esc_html( $slug ) . '</code></td><td style="font-size:12px">' . esc_html( $label ) . '</td></tr>';
            echo '</tbody></table></div>';
        }

        echo '</div></div>';
        CMH_Admin::page_footer();
    }

    /**
     * Selector de campo. Con catálogo, un <select>; sin catálogo, texto libre.
     * Un slug guardado que ya no existe se conserva y se marca, en vez de perderse
     * silenciosamente al guardar.
     */
    private static function field_input( $name, $current, $fields, $required = false ) {
        $req = $required ? ' required' : '';
        if ( ! $fields ) {
            return '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $current ) . '" placeholder="text-14"' . $req . '>';
        }

        $html = '<select name="' . esc_attr( $name ) . '"' . $req . '><option value="">— Ninguno —</option>';
        foreach ( $fields as $slug => $label ) {
            $html .= '<option value="' . esc_attr( $slug ) . '" ' . selected( $current, $slug, false ) . '>'
                . esc_html( $slug . ' — ' . CMH_Charts::truncate( $label, 45 ) ) . '</option>';
        }
        if ( $current !== '' && ! isset( $fields[ $current ] ) ) {
            $html .= '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( $current ) . ' — ya no existe</option>';
        }
        return $html . '</select>';
    }

    /**
     * Filas «valor del formulario → opción del plugin».
     * Se pintan las existentes más tres vacías: al guardar se conservan las llenas.
     * Sin JavaScript a propósito, que es como funciona el resto del panel.
     */
    private static function map_rows( $name, $current, $options, $left, $right ) {
        $rows = [];
        foreach ( (array) $current as $k => $v ) $rows[] = [ $k, $v ];
        for ( $i = 0; $i < 3; $i++ ) $rows[] = [ '', '' ];

        echo '<table class="widefat cmh"><thead><tr><th style="width:50%">' . esc_html( $left ) . '</th><th>' . esc_html( $right ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $i => $row ) {
            echo '<tr><td><input type="text" name="' . esc_attr( $name ) . '[' . $i . '][k]" value="' . esc_attr( $row[0] ) . '" placeholder="Como aparece en el formulario"></td>'
                . '<td><select name="' . esc_attr( $name ) . '[' . $i . '][v]"><option value="">— Ignorar —</option>';
            foreach ( $options as $ok => $ov )
                echo '<option value="' . esc_attr( $ok ) . '" ' . selected( $row[1], $ok, false ) . '>' . esc_html( $ov ) . '</option>';
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Filas «campo del formulario → fuente de dato» para el autorrelleno. */
    private static function prefill_rows( $current, $fields ) {
        $rows = [];
        foreach ( (array) $current as $slug => $source ) $rows[] = [ $slug, $source ];
        for ( $i = 0; $i < 4; $i++ ) $rows[] = [ '', '' ];

        echo '<table class="widefat cmh"><thead><tr><th style="width:50%">Campo del formulario</th><th>Se llena con</th></tr></thead><tbody>';
        foreach ( $rows as $i => $row ) {
            echo '<tr><td>' . self::field_input( 'prefill[' . $i . '][k]', $row[0], $fields ) . '</td>'
                . '<td><select name="prefill[' . $i . '][v]"><option value="">— Nada —</option>';
            foreach ( self::prefill_sources() as $sk => $sv )
                echo '<option value="' . esc_attr( $sk ) . '" ' . selected( $row[1], $sk, false ) . '>' . esc_html( $sv ) . '</option>';
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Resultado del probador: qué extraería el plugin del último envío real. */
    private static function render_tester( $form_id, $cfg ) {
        $data = self::forminator_last_entry( $form_id );
        if ( ! $data ) {
            echo '<p style="font-size:13px;color:#646970;margin:0">No hay envíos de este formulario para probar '
                . ( self::forminator_available() ? '(o Forminator no los devolvió).' : '— Forminator no está disponible.' ) . '</p>';
            return;
        }

        $parsed = CMH_Integration::parse_entry( $form_id, $data, $cfg );

        echo '<table class="widefat cmh"><tbody>';
        foreach ( [
            'machine_code'     => 'Código de máquina',
            'machine_found'    => '¿Existe la máquina?',
            'intervention_date'=> 'Fecha',
            'maintenance_type' => 'Tipo',
            'affects'          => '¿Descuenta disponibilidad?',
            'hourmeter'        => 'Horómetro',
            'worked_hours'     => 'Horas trabajadas',
            'downtime_hours'   => 'Horas de parada',
            'failure_system'   => 'Sistema',
            'technician'       => 'Técnico',
            'cost'             => 'Costo',
        ] as $k => $label ) {
            $v = $parsed[ $k ] ?? '';
            if ( $k === 'machine_found' ) $v = $parsed['machine_found'] ? 'Sí' : 'NO — no se registraría';
            if ( $k === 'affects' )       $v = $parsed['affects'] ? 'Sí' : 'No';
            $color = ( $k === 'machine_found' && ! $parsed['machine_found'] ) ? 'color:#d63638;font-weight:600' : '';
            echo '<tr><td style="width:48%;font-size:12px;color:#646970">' . esc_html( $label ) . '</td>'
                . '<td style="' . $color . '">' . esc_html( $v === '' ? '—' : (string) $v ) . '</td></tr>';
        }
        echo '</tbody></table>';

        if ( ! empty( $parsed['warnings'] ) ) {
            echo '<div class="cmh-note" style="border-left:4px solid #dba617;margin-top:10px;font-size:12px">'
                . esc_html( implode( ' · ', $parsed['warnings'] ) ) . '</div>';
        }
    }

    // =========================================================================
    // Handlers
    // =========================================================================

    public static function save_form_map() {
        CMH_Admin::check();
        $form_id = (int) ( $_POST['form_id'] ?? 0 );
        if ( ! $form_id ) wp_die( 'Falta el ID del formulario.' );

        // Al vincular uno nuevo se guarda el esqueleto y se abre el mapeo sugerido.
        if ( ! empty( $_POST['is_new'] ) ) {
            if ( self::exists( $form_id ) ) {
                CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-forms', [ 'form' => $form_id ] ), '', 'Ese formulario ya estaba vinculado.' );
            }
            $names = self::forminator_forms();
            $cfg   = self::blank();
            $cfg['label']  = $names[ $form_id ] ?? ( 'Formulario ' . $form_id );
            $cfg['fields'] = self::suggest_mapping( $form_id );
            self::save( $form_id, $cfg );
            CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-forms', [ 'form' => $form_id ] ), 'Formato vinculado. Revisa el mapeo antes de usarlo.' );
        }

        $cfg = self::blank();
        $cfg['enabled']          = isset( $_POST['enabled'] ) ? 1 : 0;
        $cfg['label']            = sanitize_text_field( $_POST['label'] ?? '' );
        $cfg['form_type']        = sanitize_key( $_POST['form_type'] ?? '' ) ?: 'formato';
        $cfg['maintenance_type'] = sanitize_key( $_POST['maintenance_type'] ?? 'preventivo' );
        $cfg['page_url']         = esc_url_raw( trim( $_POST['page_url'] ?? '' ) );
        $cfg['type_field']       = self::clean_slug( $_POST['type_field'] ?? '' );

        foreach ( array_keys( self::capture_targets() ) as $key ) {
            $slug = self::clean_slug( $_POST['fields'][ $key ] ?? '' );
            if ( $slug !== '' ) $cfg['fields'][ $key ] = $slug;
        }

        $cfg['type_map']   = self::collect_map( $_POST['type_map'] ?? [], array_keys( self::maintenance_types() ) );
        $cfg['system_map'] = self::collect_map( $_POST['system_map'] ?? [], array_keys( CMH_Admin::failure_systems() ) );

        foreach ( (array) ( $_POST['prefill'] ?? [] ) as $row ) {
            $slug   = self::clean_slug( $row['k'] ?? '' );
            $source = sanitize_key( $row['v'] ?? '' );
            if ( $slug !== '' && isset( self::prefill_sources()[ $source ] ) ) $cfg['prefill'][ $slug ] = $source;
        }

        $warn = empty( $cfg['fields']['machine'] )
            ? 'Ojo: sin campo de máquina los envíos de este formato no se pueden registrar.' : '';

        self::save( $form_id, $cfg );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-forms', [ 'form' => $form_id ] ), 'Mapeo guardado.', $warn );
    }

    /** Filas [k,v] del POST → mapa limpio, conservando el orden (que es prioridad). */
    private static function collect_map( $rows, $valid_values ) {
        $out = [];
        foreach ( (array) $rows as $row ) {
            $k = sanitize_text_field( $row['k'] ?? '' );
            $v = sanitize_key( $row['v'] ?? '' );
            if ( $k === '' || $v === '' ) continue;
            if ( ! in_array( $v, $valid_values, true ) ) continue;
            $out[ $k ] = $v;
        }
        return $out;
    }

    /** Los slugs de Forminator son del tipo `text-14`: nada más pasa. */
    private static function clean_slug( $v ) {
        $v = strtolower( trim( (string) $v ) );
        return preg_match( '/^[a-z0-9_\-]+$/', $v ) ? $v : '';
    }

    public static function delete_form_map() {
        CMH_Admin::check();
        self::remove( (int) ( $_POST['form_id'] ?? 0 ) );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-forms' ), 'Formato eliminado.' );
    }

    public static function toggle_form_map() {
        CMH_Admin::check();
        $id  = (int) ( $_POST['form_id'] ?? 0 );
        $cfg = self::get( $id );
        if ( ! $cfg ) wp_die( 'Formato no encontrado.' );
        $cfg['enabled'] = empty( $cfg['enabled'] ) ? 1 : 0;
        self::save( $id, $cfg );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-forms' ),
            $cfg['enabled'] ? 'Formato activado.' : 'Formato desactivado.' );
    }

    public static function duplicate_form_map() {
        CMH_Admin::check();
        $from = (int) ( $_POST['from'] ?? 0 );
        $to   = (int) ( $_POST['to'] ?? 0 );
        $src  = self::get( $from );
        if ( ! $src )        wp_die( 'Formato de origen no encontrado.' );
        if ( ! $to )         wp_die( 'Falta el ID de destino.' );
        if ( $to === $from ) CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-forms' ), '', 'El origen y el destino son el mismo formulario.' );
        if ( self::exists( $to ) ) {
            CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-forms' ), '', 'El formulario ' . $to . ' ya está vinculado: edítalo en vez de duplicar sobre él.' );
        }

        $names = self::forminator_forms();
        // La página NO se copia: son formularios distintos y viven en páginas distintas.
        $src['page_url'] = '';
        $src['label']    = $names[ $to ] ?? ( $src['label'] . ' (copia)' );
        self::save( $to, $src );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-forms', [ 'form' => $to ] ),
            'Configuración duplicada. Revisa los campos: los slugs pueden no coincidir entre formularios.' );
    }

    /**
     * Reprocesa un envío guardado en los logs.
     *
     * Sirve cuando un envío se perdió porque el mapeo estaba mal: se corrige el
     * mapeo y se vuelve a procesar el payload original. La protección contra
     * duplicados por `e2pdf_entry_id` evita que se registre dos veces.
     */
    public static function reprocess_entry() {
        CMH_Admin::check();
        global $wpdb; $t = CMH_Core::tables();
        $log_id = (int) ( $_POST['log_id'] ?? 0 );

        $log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['logs']} WHERE id=%d", $log_id ) );
        if ( ! $log ) wp_die( 'Registro de log no encontrado.' );

        $data = maybe_unserialize( $log->payload );
        if ( ! is_array( $data ) || ! $data ) {
            CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-integration' ), '', 'Ese registro no guardó el contenido del envío, así que no se puede reprocesar.' );
        }

        $form_id = (int) $log->form_id;
        if ( ! self::exists( $form_id ) ) {
            CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-integration' ), '', 'El formulario ' . $form_id . ' ya no está vinculado.' );
        }

        $result = CMH_Integration::process_entry( $form_id, $data );
        $msg    = $result['created']
            ? 'Envío reprocesado: intervención #' . $result['intervention_id'] . ' creada.'
            : '';
        $warn   = $result['created'] ? '' : ( 'No se pudo reprocesar: ' . $result['message'] );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-integration' ), $msg, $warn );
    }
}
