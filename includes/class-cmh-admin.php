<?php
/**
 * CMH_Admin — menú, páginas y handlers del plugin CM Machine History.
 * v0.8.0 — exportar CSV, imprimir hoja de vida, estado automático al intervenir.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Admin {

    // =========================================================================
    // Init
    // =========================================================================

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'admin_head',            [ __CLASS__, 'menu_styles' ] );

        foreach ( [ 'company', 'city', 'branch', 'machine', 'intervention' ] as $type ) {
            add_action( 'admin_post_cm_save_' . $type, [ __CLASS__, 'save_' . $type ] );
        }
        add_action( 'admin_post_cm_upload_file',       [ __CLASS__, 'upload_file' ] );
        add_action( 'admin_post_cm_update_machine',    [ __CLASS__, 'update_machine' ] );
        add_action( 'admin_post_cm_export_csv',        [ __CLASS__, 'export_csv' ] );
        add_action( 'admin_post_cm_edit_intervention', [ __CLASS__, 'edit_intervention' ] );
        add_action( 'admin_post_cm_schedule_maintenance', [ __CLASS__, 'schedule_maintenance' ] );
        add_action( 'admin_post_cm_find_pdf',          [ __CLASS__, 'find_pdf_now' ] );
        add_action( 'admin_post_cm_update_company',    [ __CLASS__, 'update_company' ] );
        add_action( 'admin_post_cm_update_city',       [ __CLASS__, 'update_city' ] );
        add_action( 'admin_post_cm_delete_intervention', [ __CLASS__, 'delete_intervention' ] );
        add_action( 'admin_post_cm_delete_machine',    [ __CLASS__, 'delete_machine' ] );
        add_action( 'admin_post_cm_delete_city',       [ __CLASS__, 'delete_city' ] );
        add_action( 'admin_post_cm_delete_company',    [ __CLASS__, 'delete_company' ] );
        add_action( 'admin_post_cm_assign_tech',       [ __CLASS__, 'assign_tech' ] );
        add_action( 'admin_post_cm_unassign_tech',     [ __CLASS__, 'unassign_tech' ] );
        add_action( 'admin_post_cm_set_primary_tech',  [ __CLASS__, 'set_primary_tech' ] );
        add_action( 'admin_post_cm_save_task',         [ __CLASS__, 'save_task' ] );
        add_action( 'admin_post_cm_update_task',       [ __CLASS__, 'update_task' ] );
        add_action( 'admin_post_cm_delete_task',       [ __CLASS__, 'delete_task' ] );
        add_action( 'wp_ajax_cmh_get_machine',         [ __CLASS__, 'ajax_get_machine' ] );
        add_action( 'wp_ajax_nopriv_cmh_get_machine',  [ __CLASS__, 'ajax_get_machine_public' ] );
    }

    public static function admin_menu() {
        $slug = CMH_SLUG;
        add_menu_page( 'Historial de Máquinas', 'Máquinas', 'edit_others_posts', $slug, [ __CLASS__, 'page_dashboard' ], 'dashicons-hammer', 26 );

        // v2.4 — Mismas páginas, ordenadas por lo que uno viene a hacer. Los
        // separadores son entradas inertes: no llevan a ninguna parte y el CSS
        // las pinta como rótulo. Nada cambió de nombre ni desapareció.
        add_submenu_page( $slug, 'Dashboard',       'Dashboard',       'edit_others_posts', $slug,                  [ __CLASS__, 'page_dashboard' ] );
        add_submenu_page( $slug, 'Buscar máquinas', 'Buscar máquinas', 'edit_others_posts', $slug . '-machines',    [ __CLASS__, 'page_machines' ] );
        add_submenu_page( $slug, 'Empresas',        'Empresas',        'edit_others_posts', $slug . '-companies',   [ __CLASS__, 'page_companies' ] );

        self::menu_separator( $slug, 'Seguimiento', 1 );
        add_submenu_page( $slug, 'Intervenciones',  'Intervenciones',  'edit_others_posts', $slug . '-interventions', [ __CLASS__, 'page_interventions' ] );
        add_submenu_page( $slug, 'Equipo técnico',  'Equipo técnico',  'edit_others_posts', $slug . '-time',        [ 'CMH_Time', 'page_time' ] );
        add_submenu_page( $slug, 'Reportes',        'Reportes',        'edit_others_posts', $slug . '-reports',     [ 'CMH_Reports', 'page_reports' ] );

        self::menu_separator( $slug, 'Configuración', 2 );
        add_submenu_page( $slug, 'Formatos',        'Formatos',        'edit_others_posts', $slug . '-forms',       [ 'CMH_Forms', 'page_forms' ] );
        add_submenu_page( $slug, 'Integración',     'Integración',     'edit_others_posts', $slug . '-integration', [ __CLASS__, 'page_integration' ] );
        add_submenu_page( $slug, 'Ajustes',         'Ajustes',         'edit_others_posts', $slug . '-settings',    [ 'CMH_Schedule', 'page_settings' ] );
    }

    /** Rótulo inerte que separa grupos dentro del submenú. */

    /**
     * Los rótulos del submenú se ven en TODAS las pantallas del panel, también
     * donde no se carga el CSS del plugin. Por eso van aquí, en línea y mínimos.
     */
    public static function menu_styles() {
        echo '<style id="cmh-nav-sep-style">'
            . '#adminmenu .cmh-nav-sep{display:block;padding:6px 0 2px;font-size:10px;font-weight:700;'
            . 'letter-spacing:.08em;text-transform:uppercase;color:#8c8f94;cursor:default}'
            . '#adminmenu li a:has(.cmh-nav-sep),#adminmenu a[href*="-sep-"]{pointer-events:none;background:transparent!important}'
            . '#adminmenu li a .cmh-nav-sep{border-top:1px solid rgba(255,255,255,.12);margin-top:4px;padding-top:8px}'
            . '</style>';
    }
    private static function menu_separator( $slug, $label, $n ) {
        add_submenu_page(
            $slug, '', '<span class="cmh-nav-sep">' . esc_html( $label ) . '</span>',
            'edit_others_posts', $slug . '-sep-' . intval( $n ), '__return_false'
        );
    }

    public static function assets( $hook ) {
        if ( strpos( $hook, CMH_SLUG ) === false && strpos( $hook, 'cmh-tech' ) === false && strpos( $hook, 'cmh-client' ) === false ) return;
        wp_enqueue_style(  'cmh-admin', CMH_URL . 'assets/admin.css', [],          CMH_VERSION );
        wp_enqueue_script( 'cmh-admin', CMH_URL . 'assets/admin.js',  [ 'jquery' ], CMH_VERSION, true );

        $data = [ 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'lastHourmeter' => 0 ];
        $mid  = intval( $_GET['machine_id'] ?? 0 );
        if ( $mid ) {
            global $wpdb; $t = CMH_Core::tables();
            $data['lastHourmeter'] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT current_hourmeter FROM {$t['machines']} WHERE id=%d", $mid
            ) );
        }
        wp_localize_script( 'cmh-admin', 'CMH', $data );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public static function admin_url( $page, $args = [] ) {
        return admin_url( 'admin.php?page=' . $page . ( $args ? '&' . http_build_query( $args ) : '' ) );
    }

    public static function check() {
        if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'cmh_action' );
    }

    public static function notice() {
        if ( ! empty( $_GET['cmh_msg'] ) )
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $_GET['cmh_msg'] ) . '</p></div>';
        if ( ! empty( $_GET['cmh_warn'] ) )
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $_GET['cmh_warn'] ) . '</p></div>';
    }

    public static function clean_code( $v ) {
        return strtoupper( preg_replace( '/[^A-Z0-9]/', '', remove_accents( $v ) ) );
    }

    /**
     * Un nombre de carpeta seguro a partir de un texto libre (v2.8).
     *
     * El código de máquina lleva espacios y un punto —«APC BOG TY No. 001»—, así
     * que no se puede reducir a letras y números sin desfigurarlo. Lo que se
     * quita es todo lo que permita salirse del directorio: barras, dos puntos y
     * cualquier secuencia de puntos.
     */
    public static function safe_folder( $v ) {
        $v = remove_accents( (string) $v );
        $v = preg_replace( '/[^A-Za-z0-9 ._-]/', '', $v );   // fuera / \ : y demás
        $v = preg_replace( '/\.{2,}/', '.', $v );            // ningún «..»
        $v = trim( $v, ' .' );                               // ni empezar/terminar en punto
        return substr( $v, 0, 120 );
    }

    public static function brand_code( $brand ) {
        static $map = [
            'TOYOTA'       => 'TY',
            'CATERPILLAR'  => 'CT', 'CAT'          => 'CT',
            'NISSAN'       => 'NI',
            'YALE'         => 'YT',
            'CROWN'        => 'CR',
            'HYSTER'       => 'HY',
            'LINDE'        => 'LI',
            'EQUIPMENT'    => 'EP',
            'JUNGHEINRICH' => 'JH', 'JUNG'         => 'JH',
            'HANGCHA'      => 'HG',
            'HELI'         => 'HI',
            'JLG'          => 'JL',
            'KOMATSU'      => 'KO',
            'UNICARRIERS'  => 'UN',
            'RAYMOND'      => 'RA',
            'GENIE'        => 'GN', 'GENNIE'       => 'GN',
            'MITSUBISHI'   => 'MB', 'MITSU'        => 'MB',
            'TCM'          => 'TC',
            'STILL'        => 'ST',
        ];
        $b = strtoupper( trim( remove_accents( $brand ) ) );
        return $map[ $b ] ?? substr( self::clean_code( $brand ), 0, 3 );
    }

    public static function page_header( $title, $crumbs = [] ) {
        echo '<div class="wrap cmh"><h1>' . esc_html( $title ) . '</h1>';
        self::notice();
        if ( $crumbs ) {
            echo '<nav class="cmh-breadcrumbs" aria-label="Ruta">';
            foreach ( $crumbs as $i => $c ) {
                if ( $i ) echo '<span>›</span>';
                echo ! empty( $c['url'] )
                    ? '<a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['label'] ) . '</a>'
                    : '<span aria-current="page">' . esc_html( $c['label'] ) . '</span>';
            }
            echo '</nav>';
        }
    }

    public static function page_footer() { echo '</div>'; }

    /**
     * v2.4 — Los formularios llevan `cmh-guard`: si se escribió algo y se sale
     * de la página sin guardar, el navegador avisa. Los formularios de filtro no
     * pasan por aquí, así que no molestan con el aviso.
     */
    public static function form_start( $action, $multipart = false ) {
        $enc = $multipart ? ' enctype="multipart/form-data"' : '';
        echo '<form method="post" class="cmh-guard" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $enc . '>';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        wp_nonce_field( 'cmh_action' );
    }

    public static function redirect_to( $fallback, $msg, $warn = '' ) {
        $to = ! empty( $_POST['redirect_to'] ) ? esc_url_raw( $_POST['redirect_to'] ) : $fallback;
        $to = add_query_arg( 'cmh_msg', rawurlencode( $msg ), $to );
        if ( $warn ) $to = add_query_arg( 'cmh_warn', rawurlencode( $warn ), $to );
        wp_safe_redirect( $to ); exit;
    }

    public static function status_badge( $status ) {
        $status = sanitize_key( $status ?: 'activa' );
        $labels = [ 'activa' => 'Activa', 'mantenimiento' => 'En mantenimiento', 'inactiva' => 'Inactiva', 'fuera_servicio' => 'Fuera de servicio' ];
        $label  = $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
        return '<span class="cmh-badge cmh-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
    }

    // =========================================================================
    // Datos de contacto de empresa y sucursal (v2.2)
    // =========================================================================

    /**
     * Columnas de contacto/ubicación comunes a empresa y sucursal, y las de
     * facturación, que solo existen a nivel de empresa.
     */
    public static function contact_columns( $with_billing = false ) {
        $cols = [
            'contact_name'   => [ 'Encargado',                 'text',     'Nombre de quien recibe al técnico' ],
            'contact_role'   => [ 'Cargo del encargado',       'text',     'Jefe de mantenimiento, almacenista…' ],
            'contact_phone'  => [ 'Teléfono',                  'text',     '' ],
            'contact_mobile' => [ 'Celular / WhatsApp',        'text',     '' ],
            'contact_email'  => [ 'Correo',                    'email',    '' ],
            'contact2_name'  => [ 'Segundo contacto',          'text',     'Respaldo si el principal no responde' ],
            'contact2_phone' => [ 'Teléfono del segundo',      'text',     '' ],
            'contact2_email' => [ 'Correo del segundo',        'email',    '' ],
            'address'        => [ 'Dirección',                 'text',     '' ],
            'area'           => [ 'Barrio / zona',             'text',     '' ],
            'access_notes'   => [ 'Notas de acceso',           'textarea', 'Portería, horario de ingreso, qué preguntar al llegar' ],
        ];
        if ( $with_billing ) {
            $cols['tax_id']        = [ 'NIT / identificación',   'text', '' ];
            $cols['legal_name']    = [ 'Razón social',           'text', 'Si difiere del nombre comercial' ];
            $cols['billing_email'] = [ 'Correo de facturación',  'email', '' ];
            $cols['payment_terms'] = [ 'Condiciones de pago',    'text', 'Crédito 30 días, contado…' ];
        }
        return $cols;
    }

    /**
     * Pinta los campos de contacto. `$inherit_from` recibe la fila de la empresa
     * cuando se está editando una sucursal: lo que quede vacío aquí hereda de
     * allí, y se le dice al usuario para que no lo escriba dos veces.
     */
    public static function contact_fields_form( $row, $with_billing = false, $inherit_from = null ) {
        $cols = self::contact_columns( $with_billing );

        echo '<div class="cmh-form-section"><p class="cmh-form-section-title">Contacto y ubicación</p>';
        if ( $inherit_from ) {
            echo '<p style="font-size:12px;color:#646970;margin:0 0 10px">Lo que dejes vacío aquí toma el valor de la empresa <strong>'
                . esc_html( $inherit_from->name ) . '</strong>. Escribe solo lo que cambie en esta sucursal.</p>';
        }

        echo '<div class="cmh-form-grid">';
        foreach ( $cols as $key => $meta ) {
            list( $label, $type, $hint ) = $meta;
            $value = ( $row && isset( $row->$key ) ) ? (string) $row->$key : '';

            // Marca de agua con lo que se heredaría si se deja vacío.
            $ph = $hint;
            if ( $inherit_from && isset( $inherit_from->$key ) && (string) $inherit_from->$key !== '' ) {
                $ph = 'Hereda: ' . (string) $inherit_from->$key;
            }

            // El textarea ocupa la fila completa de la rejilla en vez de cerrarla
            // y volverla a abrir, que dejaba un contenedor vacío al final (v2.3).
            if ( $type === 'textarea' ) {
                echo '<label class="cmh-span-all">' . esc_html( $label )
                    . '<textarea name="' . esc_attr( $key ) . '" rows="2" placeholder="' . esc_attr( $ph ) . '">'
                    . esc_textarea( $value ) . '</textarea></label>';
                continue;
            }
            echo '<label>' . esc_html( $label )
                . '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" '
                . 'placeholder="' . esc_attr( $ph ) . '"></label>';
        }
        echo '</div></div>';
    }

    /** Los mismos campos, leídos del POST y saneados según su tipo. */
    public static function contact_fields_from_post( $with_billing = false ) {
        $out = [];
        foreach ( self::contact_columns( $with_billing ) as $key => $meta ) {
            $type = $meta[1];
            $raw  = $_POST[ $key ] ?? '';
            if ( $type === 'textarea' )   $out[ $key ] = sanitize_textarea_field( $raw );
            elseif ( $type === 'email' )  $out[ $key ] = sanitize_email( $raw );
            else                          $out[ $key ] = sanitize_text_field( $raw );
        }
        return $out;
    }

    /**
     * Tarjeta de indicador. Con `$url` se vuelve un enlace a la lista que la
     * explica (v2.3): un número suelto obliga a salir a buscar de dónde sale.
     *
     * v2.4 — Un valor largo (un costo de siete cifras, por ejemplo) reduce su
     * tamaño en vez de desbordar la tarjeta o quedar cortado.
     */
    public static function metric_card( $label, $value, $hint = '', $accent = '', $url = '' ) {
        $value = (string) $value;
        $long  = mb_strlen( $value ) > 9 ? ' cmh-long' : '';
        $acc   = $accent ? '<div class="cmh-card-accent cmh-card-accent-' . esc_attr( $accent ) . '"></div>' : '';
        $body  = $acc
            . '<span>' . esc_html( $label ) . '</span>'
            . '<strong class="' . trim( $long ) . '">' . esc_html( $value ) . '</strong>'
            . ( $hint !== '' ? '<small>' . esc_html( $hint ) . '</small>' : '' );

        if ( $url ) {
            echo '<a class="cmh-card cmh-card-link" href="' . esc_url( $url ) . '">' . $body
                . '<span class="cmh-card-go" aria-hidden="true">›</span></a>';
            return;
        }
        echo '<div class="cmh-card">' . $body . '</div>';
    }

    /**
     * Indicador secundario de la franja compacta (v2.4). Mismo dato, menos peso
     * visual: lo que se consulta de vez en cuando no tiene por qué competir con
     * lo que se mira todos los días.
     */
    public static function stat_item( $label, $value, $url = '' ) {
        $value = (string) $value;
        $long  = mb_strlen( $value ) > 8 ? ' cmh-long' : '';
        $body  = '<span class="cmh-stat-label">' . esc_html( $label ) . '</span>'
            . '<span class="cmh-stat-value' . $long . '">' . esc_html( $value ) . '</span>';

        echo $url
            ? '<a class="cmh-stat" href="' . esc_url( $url ) . '">' . $body . '</a>'
            : '<div class="cmh-stat">' . $body . '</div>';
    }

    private static function export_nonce_url( $type, $args = [] ) {
        $args['action'] = 'cm_export_csv';
        $args['type']   = $type;
        return wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( $args ) ), 'cmh_action' );
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public static function page_dashboard() {
        global $wpdb;
        $t     = CMH_Core::tables();
        $month = (int) current_time( 'n' );
        $year  = (int) current_time( 'Y' );

        $machines      = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$t['machines']}" );
        $interventions = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$t['interventions']}" );
        $preventivos   = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$t['interventions']} WHERE maintenance_type='preventivo'" );
        $correctivos   = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$t['interventions']} WHERE maintenance_type IN('correctivo','averia')" );
        $cost_total    = (float) $wpdb->get_var( "SELECT " . CMH_Taxonomy::money_sum_sql( 'cost' ) . " FROM {$t['interventions']}" );
        $por_cobrar_total = (float) $wpdb->get_var( "SELECT " . CMH_Taxonomy::balance_sum_sql() . " FROM {$t['interventions']}" );
        $en_tramite_total = (float) $wpdb->get_var( "SELECT " . CMH_Taxonomy::quote_sum_sql()   . " FROM {$t['interventions']}" );
        $fleet_avail   = CMH_Metrics::fleet_availability( $month, $year );
        $fleet_mttr    = CMH_Metrics::mttr( 0, $month, $year );
        $month_dt      = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(downtime_hours),0) FROM {$t['interventions']} WHERE affects_availability=1 AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d",
            $month, $year
        ) );
        $month_label   = CMH_Metrics::month_label( $month, $year );
        $critical      = CMH_Metrics::critical_machines();

        $avail_accent = $fleet_avail === null ? 'blue' : ( $fleet_avail >= 90 ? 'ok' : ( $fleet_avail >= 70 ? 'warn' : 'danger' ) );

        // El dashboard es la vista del administrador: sin ACL y con su vocabulario.
        CMH_Reports::reset_context();

        self::page_header( 'Dashboard' );

        // ── Cabecera del dashboard (v2.4) ────────────────────────────────
        echo '<div class="cmh-head"><div class="cmh-head-info">'
            . '<div class="cmh-head-title"><h2>Resumen operativo</h2></div>'
            . '<p class="cmh-head-meta"><span>Vista general de la flota</span>'
            . '<span>Mes en curso: <strong>' . esc_html( $month_label ) . '</strong></span>'
            . '<span><strong>' . intval( $machines ) . '</strong> máquinas registradas</span></p>'
            . '</div><div class="cmh-head-actions">'
            . '<a class="button button-primary" href="' . esc_url( self::admin_url( CMH_SLUG . '-machines' ) ) . '">Buscar máquinas</a>'
            . '<div class="cmh-menu"><button type="button" class="button cmh-menu-toggle">Más acciones ▾</button>'
            . '<div class="cmh-menu-list" style="display:none">'
            . '<a href="' . esc_url( self::admin_url( CMH_SLUG . '-companies' ) ) . '">Gestionar empresas y sucursales</a>'
            . '<a href="' . esc_url( self::interv_url() ) . '">Ver todas las intervenciones</a>'
            . '<a href="' . esc_url( self::admin_url( CMH_SLUG . '-time' ) ) . '">Equipo técnico</a>'
            . '<a href="' . esc_url( self::admin_url( CMH_SLUG . '-reports' ) ) . '">Reportes</a>'
            . '</div></div></div></div>';

        // ── Indicadores en dos niveles (v2.4) ────────────────────────────
        echo '<div class="cmh-grid-primary">';
        self::metric_card( 'Disponibilidad ' . $month_label, CMH_Metrics::fmt_pct( $fleet_avail ),
            'flota, mes en curso', $avail_accent, self::admin_url( CMH_SLUG . '-reports' ) );
        self::metric_card( 'Intervenciones', $interventions, 'historial total', 'blue', self::interv_url() );
        self::metric_card( 'Por cobrar', '$' . number_format( $por_cobrar_total, 0, ',', '.' ),
            'saldo pendiente', $por_cobrar_total > 0 ? 'warn' : 'ok', self::interv_url( [ 'pay' => 'pending' ] ) );
        self::metric_card( 'Costo total', '$' . number_format( $cost_total, 0, ',', '.' ),
            'historial', 'blue', self::interv_url() );
        echo '</div>';

        echo '<div class="cmh-stats-strip">';
        self::stat_item( 'Máquinas',       $machines,    self::admin_url( CMH_SLUG . '-machines' ) );
        self::stat_item( 'Preventivos',    $preventivos, self::interv_url( [ 'type' => 'preventivo' ] ) );
        self::stat_item( 'Correctivos/Averías', $correctivos, self::interv_url( [ 'affects' => 1 ] ) );
        self::stat_item( 'MTTR ' . $month_label, CMH_Metrics::fmt_mttr( $fleet_mttr ), self::interv_url( [ 'affects' => 1 ] ) );
        self::stat_item( 'MTBF flota',     CMH_Metrics::fmt_mttr( CMH_Metrics::mtbf( 0, 12 ) ) );
        self::stat_item( 'Horas parada ' . $month_label, number_format( $month_dt, 1, ',', '.' ) . ' h', self::interv_url( [ 'affects' => 1 ] ) );
        // Solo aparece si hay estados marcados «En trámite»: a quien no cotiza
        // no se le mete un indicador en cero que no significa nada.
        if ( CMH_Taxonomy::quote_pstates() ) {
            self::stat_item( 'En trámite', '$' . number_format( $en_tramite_total, 0, ',', '.' ),
                self::interv_url( [ 'pay' => 'quote' ] ) );
        }
        echo '</div>';

        // v2.0 — Tendencia gráfica de la flota (disponibilidad, mezcla y costos).
        CMH_Reports::dashboard_charts();

        if ( $critical ) {
            echo '<div class="cmh-panel cmh-panel-critical"><h2>Atención — Máquinas críticas este mes</h2>'
                . '<p style="color:#646970;font-size:13px;margin:-8px 0 14px">Disponibilidad &lt; 70% o 3+ averías en ' . esc_html( $month_label ) . '.</p>'
                . '<table class="widefat cmh"><thead><tr>'
                . '<th>Código</th><th>Equipo</th><th>Ubicación</th><th>Disponibilidad</th><th>Averías</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ( $critical as $cr ) {
                $cls = $cr['availability'] < 50 ? 'cmh-avail-danger' : 'cmh-avail-warn';
                echo '<tr>'
                    . '<td><strong>' . esc_html( $cr['machine_code'] ) . '</strong></td>'
                    . '<td>' . esc_html( $cr['brand_model'] ) . '</td>'
                    . '<td>' . esc_html( $cr['company_city'] ) . '</td>'
                    . '<td><span class="cmh-avail-badge ' . $cls . '">' . esc_html( CMH_Metrics::fmt_pct( $cr['availability'] ) ) . '</span></td>'
                    . '<td>' . intval( $cr['averia_count'] ) . '</td>'
                    . '<td><a class="button button-small" href="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $cr['id'] ] ) ) . '">Ver hoja de vida</a></td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
        }

        // Mantenimientos próximos (≤ 30 días o vencidos)
        $in30     = date( 'Y-m-d', strtotime( '+30 days', current_time( 'timestamp' ) ) );
        $upcoming = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.machine_code, m.brand, m.model, m.next_maintenance_date, m.maintenance_interval_days,
                    c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}   ci ON ci.id=m.city_id
             WHERE m.next_maintenance_date IS NOT NULL AND m.next_maintenance_date <= %s
             ORDER BY m.next_maintenance_date ASC",
            $in30
        ) );
        if ( $upcoming ) {
            $alerts_on = (bool) CMH_Schedule::setting( 'alerts_enabled' );
            echo '<div class="cmh-panel">'
                . '<div class="cmh-toolbar">'
                . '<h2>Mantenimientos próximos <small style="font-weight:400;font-size:13px;color:#646970">— próximos 30 días o vencidos</small></h2>'
                . '<a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-settings' ) ) . '">'
                . ( $alerts_on ? 'Alertas activas' : 'Alertas desactivadas' ) . ' — Ajustes</a>'
                . '</div>'
                . '<table class="widefat cmh"><thead><tr>'
                . '<th>Máquina</th><th>Equipo</th><th>Ubicación</th><th>Fecha programada</th><th>Recurrencia</th><th>Estado</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ( $upcoming as $um ) {
                $days = CMH_Metrics::maintenance_days( $um->next_maintenance_date );
                if ( $days < 0 )       $sbadge = '<span class="cmh-badge" style="background:#fce8e8;color:#d63638">Vencido hace ' . abs( $days ) . ' d</span>';
                elseif ( $days <= 7 )  $sbadge = '<span class="cmh-badge" style="background:#fce8e8;color:#d63638">En ' . $days . ' días</span>';
                elseif ( $days <= 15 ) $sbadge = '<span class="cmh-badge" style="background:#fff3cd;color:#7a4f00">En ' . $days . ' días</span>';
                else                   $sbadge = '<span class="cmh-badge" style="background:#e6f4ea;color:#1a6630">En ' . $days . ' días</span>';
                echo '<tr>'
                    . '<td><strong>' . esc_html( $um->machine_code ) . '</strong></td>'
                    . '<td>' . esc_html( trim( $um->brand . ' ' . $um->model ) ) . '</td>'
                    . '<td style="font-size:12px">' . esc_html( $um->company_name . ' / ' . $um->city_name ) . '</td>'
                    . '<td>' . esc_html( $um->next_maintenance_date ) . '</td>'
                    . '<td style="font-size:12px;color:#646970">' . esc_html( CMH_Schedule::interval_label( $um->maintenance_interval_days ) ) . '</td>'
                    . '<td>' . $sbadge . '</td>'
                    . '<td><a class="button button-small" href="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $um->id ] ) ) . '">Ver</a></td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<div class="cmh-layout">';
        echo '<div class="cmh-main"><div class="cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Últimas intervenciones</h2>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'interventions' ) ) . '">Exportar CSV</a></div>';
        self::interventions_table( 12 );
        echo '</div></div>';
        echo '<div class="cmh-side"><div class="cmh-panel"><h2>Máquinas recientes</h2>';
        self::machines_mini_table();
        echo '</div><div class="cmh-panel"><h2>Integración</h2>'
            . '<p style="font-size:13px;color:#646970">Forminator crea intervenciones y E2PDF asocia PDFs automáticamente.</p>'
            . '<a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-integration' ) ) . '">Ver logs</a>'
            . '</div></div></div>';

        self::page_footer();
    }

    // =========================================================================
    // Empresas / Ciudades / Sucursales
    // =========================================================================

    public static function page_companies() {
        $company_id = intval( $_GET['company_id'] ?? 0 );
        $city_id    = intval( $_GET['city_id']    ?? 0 );
        $branch_id  = intval( $_GET['branch_id']  ?? 0 );
        if ( $branch_id  ) return self::page_branch( $branch_id );
        if ( $city_id    ) return self::page_city( $city_id );
        if ( $company_id ) return self::page_company( $company_id );

        global $wpdb; $t = CMH_Core::tables();
        self::page_header( 'Empresas', [ [ 'label' => 'Empresas' ] ] );

        echo '<div class="cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Empresas registradas</h2>'
            . '<div class="cmh-toolbar-actions">'
            . '<button type="button" class="button button-primary cmh-open-modal" data-target="cmh-box-empresa" '
            . 'data-title="Agregar una empresa nueva" data-subtitle="Es el nivel más alto: dentro de una empresa van sus sucursales y, dentro de estas, las máquinas.">'
            . 'Agregar empresa</button>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'machines' ) ) . '">Exportar máquinas (CSV)</a>'
            . '</div></div>'
            . '<div class="cmh-tablebar">'
            . '<input type="search" class="cmh-table-search" data-table="cmh-tbl-empresas" placeholder="Buscar empresa por nombre o código…">'
            . '<span class="cmh-count"></span></div>'
            . '<div class="cmh-table-scroll"><table id="cmh-tbl-empresas" class="widefat cmh cmh-sortable"><thead><tr>'
            . '<th data-sort="text">Empresa</th><th data-sort="text">Código</th>'
            . '<th data-sort="num">Ciudades</th><th data-sort="num">Máquinas</th><th></th></tr></thead><tbody>';

        $rows = $wpdb->get_results(
            "SELECT c.*, (SELECT COUNT(*) FROM {$t['cities']} ci WHERE ci.company_id=c.id) cities, (SELECT COUNT(*) FROM {$t['machines']} m WHERE m.company_id=c.id) machines FROM {$t['companies']} c ORDER BY c.name"
        );
        foreach ( $rows as $r ) {
            echo '<tr><td><strong>' . esc_html( $r->name ) . '</strong></td>'
                . '<td><code>' . esc_html( $r->code ) . '</code></td>'
                . '<td>' . intval( $r->cities ) . '</td><td>' . intval( $r->machines ) . '</td>'
                . '<td style="display:flex;gap:6px;align-items:center">'
                . '<a class="button button-small" href="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $r->id ] ) ) . '">Entrar</a>'
                . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Eliminar empresa \'+' . json_encode( $r->name ) . '+\' con ' . intval( $r->cities ) . ' ciudades y ' . intval( $r->machines ) . ' máquinas? Esta acción es irreversible.\')">'
                . '<input type="hidden" name="action" value="cm_delete_company">'
                . '<input type="hidden" name="company_id" value="' . intval( $r->id ) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                . '<button type="submit" class="button button-small" style="color:#d63638;border-color:#d63638">Eliminar</button>'
                . '</form>'
                . '</td></tr>';
        }
        if ( ! $rows ) {
            echo '<tr><td colspan="5">';
            self::empty_state( 'dashicons-building', 'Sin empresas', 'Las máquinas se registran dentro de una empresa, así que se empieza por aquí.',
                [ 'label' => 'Crear la primera empresa', 'modal' => 'cmh-box-empresa', 'title' => 'Nueva empresa' ] );
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';

        // Formulario de alta en ventana (v2.4): deja de estrechar la tabla.
        echo '<div id="cmh-box-empresa" style="display:none">';
        self::form_start( 'cm_save_company' );
        echo '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies' ) ) . '">'
            . '<div class="cmh-form-grid">'
            . '<label>Nombre de la empresa <em>*</em><input name="name" required class="cmh-uppercase"></label>'
            . '<label>Código corto <em>*</em><input name="code" placeholder="APC" maxlength="10" required class="cmh-uppercase"></label>'
            . '</div>'
            . '<p class="cmh-hint" style="margin-top:12px">El código corto se usa para armar el de cada máquina: <strong>APC BOG TY No. 001</strong></p>';

        // v2.7 — Los datos de contacto se pueden llenar aquí mismo. Van plegados
        // para que dar de alta siga siendo dos campos, pero sin obligar a crear
        // la empresa y volver a entrar solo para escribir un teléfono.
        echo '<details class="cmh-optional-block"><summary>Datos de contacto, ubicación y facturación'
            . ' <span>opcional, se pueden completar después</span></summary>';
        self::contact_fields_form( null, true );
        echo '</details>';

        echo '<div class="cmh-form-actions"><button class="button button-primary">Guardar empresa</button></div>'
            . '</form></div>';
        self::page_footer();
    }

    public static function page_company( $company_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['companies']} WHERE id=%d", $company_id ) );
        if ( ! $c ) wp_die( 'Empresa no encontrada.' );

        self::page_header( $c->name, [
            [ 'label' => 'Empresas', 'url' => self::admin_url( CMH_SLUG . '-companies' ) ],
            [ 'label' => $c->name ],
        ] );

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT (SELECT COUNT(*) FROM {$t['cities']} WHERE company_id=%d) cities, (SELECT COUNT(*) FROM {$t['machines']} WHERE company_id=%d) machines",
            $company_id, $company_id
        ) );

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Empresa</div>'
            . '<h2>' . esc_html( $c->name ) . '</h2>'
            . '<p>Código: <strong>' . esc_html( $c->code ) . '</strong> &nbsp;·&nbsp; '
            . intval( $stats->cities ) . ' ciudades · ' . intval( $stats->machines ) . ' máquinas</p>'
            . '</div><div class="cmh-hero-actions">'
            . '<a class="button button-primary" href="' . esc_url( self::admin_url( CMH_SLUG . '-reports', [ 'company_id' => $company_id ] ) ) . '">Ver reporte de la empresa</a>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'machines', [ 'company_id' => $company_id ] ) ) . '">Exportar máquinas (CSV)</a>'
            . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Eliminar empresa \'+' . json_encode( $c->name ) . '+\' con ' . intval( $stats->cities ) . ' ciudades y ' . intval( $stats->machines ) . ' máquinas? Esta acción es irreversible.\')">'
            . '<input type="hidden" name="action" value="cm_delete_company">'
            . '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
            . '<button type="submit" class="button" style="color:#d63638;border-color:#d63638">Eliminar empresa</button>'
            . '</form>'
            . '</div></div>';

        echo '<div class="cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Ciudades / Sucursales</h2>'
            . '<button type="button" class="button button-primary cmh-open-modal" data-target="cmh-box-sucursal" '
            . 'data-title="Agregar una sucursal a ' . esc_attr( $c->name ) . '" '
            . 'data-subtitle="Es la sede o ciudad donde están las máquinas. Sus datos de contacto se heredan de la empresa si los dejas vacíos.">'
            . 'Agregar sucursal</button></div>'
            . '<div class="cmh-table-scroll"><table class="widefat cmh"><thead><tr>'
            . '<th>Ciudad/Sucursal</th><th>Código</th><th>Máquinas</th><th></th></tr></thead><tbody>';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ci.*, (SELECT COUNT(*) FROM {$t['machines']} m WHERE m.city_id=ci.id) machines FROM {$t['cities']} ci WHERE ci.company_id=%d ORDER BY ci.name",
            $company_id
        ) );
        foreach ( $rows as $r ) {
            echo '<tr><td><strong>' . esc_html( $r->name ) . '</strong></td>'
                . '<td><code>' . esc_html( $r->code ) . '</code></td>'
                . '<td>' . intval( $r->machines ) . '</td>'
                . '<td><a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $r->id ] ) ) . '">Entrar</a></td></tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="4">' . self::empty_state_inline( 'Sin ciudades/sucursales aún.' ) . '</td></tr>';
        echo '</tbody></table></div></div>';

        // v0.10 — Clientes con acceso a esta empresa (portal de solo lectura).
        echo '<div class="cmh-panel"><h2>Clientes con acceso</h2>';
        CMH_Client::company_clients_panel( $company_id );
        echo '</div>';

        // v2.4 — La ficha de la empresa pasa a ancho completo: con los datos de
        // contacto y facturación no cabía en la columna de 340px sin verse mal.
        echo '<div class="cmh-panel"><h2>Datos de la empresa</h2>';
        self::form_start( 'cm_update_company' );
        echo '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ) ) . '">'
            . '<div class="cmh-form-grid">'
            . '<label>Nombre <em>*</em><input name="name" value="' . esc_attr( $c->name ) . '" required class="cmh-uppercase"></label>'
            . '<label>Código <em>*</em><input name="code" value="' . esc_attr( $c->code ) . '" maxlength="10" required class="cmh-uppercase"></label>'
            . '</div>'
            . '<p class="cmh-hint" style="margin-top:8px">Cambiar el código <strong>no</strong> actualiza los códigos de máquinas existentes.</p>';
        self::contact_fields_form( $c, true );
        echo '<div class="cmh-form-actions"><button class="button button-primary">Guardar cambios</button></div></form>';
        echo '</div>';

        // Alta de sucursal en ventana (v2.4).
        echo '<div id="cmh-box-sucursal" style="display:none">';
        self::form_start( 'cm_save_city' );
        echo '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ) ) . '">'
            . '<div class="cmh-form-grid">'
            . '<label>Nombre de la sucursal <em>*</em><input name="name" placeholder="BOGOTÁ" required class="cmh-uppercase"></label>'
            . '<label>Código corto <em>*</em><input name="code" placeholder="BOG" maxlength="10" required class="cmh-uppercase"></label>'
            . '</div>';

        // v2.7 — Igual que en la empresa: se pueden dejar desde aquí. La sucursal
        // hereda de la empresa lo que quede vacío, y el marcador de agua lo dice.
        echo '<details class="cmh-optional-block"><summary>Datos de contacto y ubicación'
            . ' <span>opcional, hereda de la empresa lo que dejes vacío</span></summary>';
        self::contact_fields_form( null, false, $c );
        echo '</details>';

        echo '<div class="cmh-form-actions"><button class="button button-primary">Guardar sucursal</button></div>'
            . '</form></div>';

        self::page_footer();
    }

    public static function page_city( $city_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $city = $wpdb->get_row( $wpdb->prepare(
            "SELECT ci.*, c.name company_name, c.id company_id FROM {$t['cities']} ci JOIN {$t['companies']} c ON c.id=ci.company_id WHERE ci.id=%d",
            $city_id
        ) );
        if ( ! $city ) wp_die( 'Ciudad/Sucursal no encontrada.' );

        self::page_header( $city->name, [
            [ 'label' => 'Empresas',          'url' => self::admin_url( CMH_SLUG . '-companies' ) ],
            [ 'label' => $city->company_name, 'url' => self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $city->company_id ] ) ],
            [ 'label' => $city->name ],
        ] );

        $city_machine_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['machines']} WHERE city_id=%d", $city_id ) );

        // ── Cabecera (v2.4) ──────────────────────────────────────────────
        echo '<div class="cmh-head"><div class="cmh-head-info">'
            . '<div class="cmh-head-title"><h2>' . esc_html( $city->name ) . '</h2>'
            . '<span class="cmh-badge" style="background:#e7f0fb;color:#1c4d80">' . esc_html( $city->code ) . '</span></div>'
            . '<p class="cmh-head-meta"><span>Sucursal de <strong>' . esc_html( $city->company_name ) . '</strong></span>'
            . '<span><strong>' . intval( $city_machine_count ) . '</strong> máquinas</span></p>'
            . '</div><div class="cmh-head-actions">'
            . '<button type="button" class="button button-primary cmh-open-modal" data-target="cmh-box-maquina" '
            . 'data-title="Agregar una máquina a ' . esc_attr( $city->name ) . '" '
            . 'data-subtitle="El código se arma solo con la empresa, la sucursal y la marca.">Agregar máquina</button>'
            . '<div class="cmh-menu"><button type="button" class="button cmh-menu-toggle">Más acciones ▾</button>'
            . '<div class="cmh-menu-list" style="display:none">'
            . '<a href="' . esc_url( self::admin_url( CMH_SLUG . '-reports', [ 'city_id' => $city_id ] ) ) . '">Ver reporte de la sucursal</a>'
            . '<a href="' . esc_url( self::export_nonce_url( 'machines', [ 'city_id' => $city_id ] ) ) . '">Exportar máquinas (CSV)</a>'
            . '<a href="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $city->company_id ] ) ) . '">Volver a la empresa</a>'
            . '<div class="cmh-menu-sep"></div>'
            . '<button type="button" class="cmh-open-modal" data-target="cmh-box-borrar-ciudad" '
            . 'data-title="Eliminar la sucursal ' . esc_attr( $city->name ) . '" style="color:#d63638">Eliminar sucursal</button>'
            . '</div></div></div></div>';

        echo '<div class="cmh-panel"><h2>Máquinas en ' . esc_html( $city->name ) . '</h2>';
        self::machines_table( $city_id, 0 );
        echo '</div>';

        // v2.0 — Acceso de clientes acotado a ESTA ciudad/sucursal.
        echo '<div class="cmh-panel"><h2>Clientes con acceso a esta sucursal</h2>';
        CMH_Client::city_clients_panel( $city_id );
        echo '</div>';

        // v2.4 — A ancho completo: con los datos de contacto no cabía bien en la
        // columna estrecha de la derecha.
        echo '<div class="cmh-panel"><h2>Datos de la sucursal</h2>';
        self::form_start( 'cm_update_city' );
        echo '<input type="hidden" name="city_id" value="' . intval( $city_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] ) ) . '">'
            . '<div class="cmh-form-grid">'
            . '<label>Nombre <em>*</em><input name="name" value="' . esc_attr( $city->name ) . '" required class="cmh-uppercase"></label>'
            . '<label>Código <em>*</em><input name="code" value="' . esc_attr( $city->code ) . '" maxlength="10" required class="cmh-uppercase"></label>'
            . '</div>'
            . '<p class="cmh-hint" style="margin-top:8px">Cambiar el código <strong>no</strong> actualiza los códigos de máquinas existentes.</p>';
        $parent_company = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['companies']} WHERE id=%d", (int) $city->company_id ) );
        self::contact_fields_form( $city, false, $parent_company );
        echo '<div class="cmh-form-actions"><button class="button button-primary">Guardar cambios</button></div></form>';
        echo '</div>';

        // ── Ventanas ─────────────────────────────────────────────────────
        echo '<div id="cmh-box-maquina" style="display:none">';
        self::machine_form( $city->company_id, $city_id );
        echo '</div>';

        echo '<div id="cmh-box-borrar-ciudad" style="display:none">'
            . '<p>Se eliminará la sucursal <strong>' . esc_html( $city->name ) . '</strong> y sus '
            . intval( $city_machine_count ) . ' máquina(s), con todo su historial. <strong>No se puede deshacer.</strong></p>'
            . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Seguro? Esta acción es irreversible.\')">'
            . '<input type="hidden" name="action" value="cm_delete_city">'
            . '<input type="hidden" name="city_id" value="' . intval( $city_id ) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
            . '<div class="cmh-form-actions">'
            . '<button type="submit" class="button" style="background:#d63638;border-color:#d63638;color:#fff">Sí, eliminar la sucursal</button>'
            . '</div></form></div>';

        self::page_footer();
    }

    public static function page_branch( $branch_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $b = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, ci.name city_name, ci.id city_id, c.name company_name, c.id company_id FROM {$t['branches']} b JOIN {$t['cities']} ci ON ci.id=b.city_id JOIN {$t['companies']} c ON c.id=b.company_id WHERE b.id=%d",
            $branch_id
        ) );
        if ( ! $b ) wp_die( 'Sucursal no encontrada.' );

        self::page_header( $b->name, [
            [ 'label' => 'Empresas',       'url' => self::admin_url( CMH_SLUG . '-companies' ) ],
            [ 'label' => $b->company_name, 'url' => self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $b->company_id ] ) ],
            [ 'label' => $b->city_name,    'url' => self::admin_url( CMH_SLUG . '-companies', [ 'city_id'    => $b->city_id    ] ) ],
            [ 'label' => $b->name ],
        ] );

        echo '<div class="cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Máquinas en ' . esc_html( $b->name ) . '</h2>'
            . '<div class="cmh-toolbar-actions">'
            . '<button type="button" class="button button-primary cmh-open-modal" data-target="cmh-box-maquina" '
            . 'data-title="Agregar una máquina a ' . esc_attr( $b->name ) . '">Agregar máquina</button>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'machines', [ 'branch_id' => $branch_id ] ) ) . '">Exportar CSV</a>'
            . '</div></div>';
        self::machines_table( 0, $branch_id );
        echo '</div>';

        echo '<div id="cmh-box-maquina" style="display:none">';
        self::machine_form( $b->company_id, $b->city_id, $branch_id );
        echo '</div>';
        self::page_footer();
    }

    // =========================================================================
    // Máquinas
    // =========================================================================

    public static function page_machines() {
        $machine_id = intval( $_GET['machine_id'] ?? 0 );
        if ( $machine_id ) return self::page_machine( $machine_id );

        self::page_header( 'Buscar máquinas', [ [ 'label' => 'Buscar máquinas' ] ] );
        $q      = sanitize_text_field( $_GET['q']      ?? '' );
        $status = sanitize_key(        $_GET['status'] ?? '' );

        echo '<div class="cmh-panel"><form method="get" class="cmh-filterbar">'
            . '<input type="hidden" name="page" value="' . esc_attr( CMH_SLUG . '-machines' ) . '">'
            . '<label><span>Buscar</span><input name="q" value="' . esc_attr( $q ) . '" placeholder="Código, serial, marca, modelo o contacto"></label>'
            . '<label><span>Estado</span><select name="status"><option value="">Todos los estados</option>';
        foreach ( [ 'activa' => 'Activa', 'mantenimiento' => 'En mantenimiento', 'inactiva' => 'Inactiva', 'fuera_servicio' => 'Fuera de servicio' ] as $k => $v ) {
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $status, $k, false ) . '>' . esc_html( $v ) . '</option>';
        }
        echo '</select></label>'
            . '<button class="button button-primary">Filtrar</button>'
            . '<a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-machines' ) ) . '">Limpiar</a>'
            . '</form></div>';

        echo '<div class="cmh-panel"><div class="cmh-toolbar"><h2>Resultados</h2>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'machines', [ 'q' => $q, 'status' => $status ] ) ) . '">Exportar CSV</a></div>';
        self::machines_table( 0, 0, [ 'q' => $q, 'status' => $status ] );
        echo '</div>';
        self::page_footer();
    }

    public static function page_machine( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, c.name company_name, c.id company_id, ci.name city_name, ci.id city_id, b.name branch_name FROM {$t['machines']} m JOIN {$t['companies']} c ON c.id=m.company_id JOIN {$t['cities']} ci ON ci.id=m.city_id LEFT JOIN {$t['branches']} b ON b.id=m.branch_id WHERE m.id=%d",
            $machine_id
        ) );
        if ( ! $m ) wp_die( 'Máquina no encontrada.' );

        $crumbs = [
            [ 'label' => 'Empresas',       'url' => self::admin_url( CMH_SLUG . '-companies' ) ],
            [ 'label' => $m->company_name, 'url' => self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $m->company_id ] ) ],
            [ 'label' => $m->city_name,    'url' => self::admin_url( CMH_SLUG . '-companies', [ 'city_id'    => $m->city_id    ] ) ],
        ];
        if ( $m->branch_id ) $crumbs[] = [ 'label' => $m->branch_name, 'url' => self::admin_url( CMH_SLUG . '-companies', [ 'branch_id' => $m->branch_id ] ) ];
        $crumbs[] = [ 'label' => $m->machine_code ];

        self::page_header( $m->machine_code, $crumbs );

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN affects_availability=1 THEN downtime_hours ELSE 0 END),0) downtime_averia, COALESCE(SUM(CASE WHEN affects_availability=0 THEN downtime_hours ELSE 0 END),0) downtime_maintenance, " . CMH_Taxonomy::money_sum_sql( 'cost' ) . " cost, " . CMH_Taxonomy::balance_sum_sql() . " por_cobrar, " . CMH_Taxonomy::quote_sum_sql() . " en_tramite, SUM(CASE WHEN maintenance_type='preventivo' THEN 1 ELSE 0 END) preventivos, SUM(CASE WHEN maintenance_type IN('correctivo','averia') THEN 1 ELSE 0 END) correctivos FROM {$t['interventions']} WHERE machine_id=%d",
            $machine_id
        ) );
        $last = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['interventions']} WHERE machine_id=%d ORDER BY intervention_date DESC, id DESC LIMIT 1", $machine_id ) );

        $month      = (int) current_time( 'n' );
        $year       = (int) current_time( 'Y' );
        $avail_now  = CMH_Metrics::availability( $machine_id, $month, $year );
        $mttr_all   = CMH_Metrics::mttr( $machine_id );
        $averia_now = CMH_Metrics::averia_count( $machine_id, $month, $year );
        $is_crit    = CMH_Metrics::is_critical( $machine_id );
        $avail_acc  = $avail_now === null ? 'blue' : ( $avail_now >= 90 ? 'ok' : ( $avail_now >= 70 ? 'warn' : 'danger' ) );
        $maint_days = CMH_Metrics::maintenance_days( $m->next_maintenance_date );
        if ( $maint_days === null ) {
            $maint_badge = '';
            $maint_label = '—';
        } elseif ( $maint_days < 0 ) {
            $maint_badge = ' <span class="cmh-badge" style="background:#fce8e8;color:#d63638">Mant. vencido hace ' . abs( $maint_days ) . ' d</span>';
            $maint_label = esc_html( $m->next_maintenance_date ) . ' <em style="color:#d63638">(vencido hace ' . abs( $maint_days ) . ' días)</em>';
        } elseif ( $maint_days <= 15 ) {
            $maint_badge = ' <span class="cmh-badge" style="background:#fff3cd;color:#7a4f00">Mant. en ' . $maint_days . ' días</span>';
            $maint_label = esc_html( $m->next_maintenance_date ) . ' <em style="color:#7a4f00">(en ' . $maint_days . ' días)</em>';
        } else {
            $maint_badge = '';
            $maint_label = esc_html( $m->next_maintenance_date ) . ' <em style="color:#646970">(en ' . $maint_days . ' días)</em>';
        }

        // ── Cabecera (v2.4) ──────────────────────────────────────────────
        // Antes: título, tres badges y CINCO botones peleando la misma línea.
        // Ahora: identidad y datos de la máquina a la izquierda, UNA acción
        // principal a la derecha y el resto recogido en un menú.
        $loc = $m->company_name . ' / ' . $m->city_name . ( $m->branch_id ? ' / ' . $m->branch_name : '' );

        echo '<div class="cmh-head"><div class="cmh-head-info">'
            . '<div class="cmh-head-title">'
            . '<h2>' . esc_html( $m->machine_code ) . '</h2>'
            . self::status_badge( $m->status )
            . ( $is_crit ? '<span class="cmh-badge cmh-badge-critical">Crítica</span>' : '' )
            . $maint_badge
            . '</div>'
            . '<p class="cmh-head-meta">'
            . '<span>' . esc_html( trim( $m->brand . ' ' . $m->model ) ?: 'Sin marca/modelo' ) . '</span>'
            . '<span>' . esc_html( $loc ) . '</span>'
            . '<span>Horómetro <strong>' . esc_html( number_format( (float) $m->current_hourmeter, 0, ',', '.' ) ) . ' h</strong></span>'
            . ( $m->next_maintenance_date
                ? '<span>Próximo mantenimiento <strong>' . esc_html( $m->next_maintenance_date ) . '</strong></span>'
                : '<span>Sin mantenimiento programado</span>' )
            . '</p></div>'

            . '<div class="cmh-head-actions">'
            . '<button type="button" class="button button-primary cmh-open-modal" data-target="cmh-box-intervencion" '
            . 'data-title="Registrar intervención" data-subtitle="Máquina ' . esc_attr( $m->machine_code ) . '">'
            . 'Registrar intervención</button>'

            . '<div class="cmh-menu">'
            . '<button type="button" class="button cmh-menu-toggle">Más acciones ▾</button>'
            . '<div class="cmh-menu-list" style="display:none">'
            . '<button type="button" class="cmh-open-modal" data-target="cmh-box-archivo" '
            . 'data-title="Anexar PDF o archivo" data-subtitle="Máquina ' . esc_attr( $m->machine_code ) . '">Anexar PDF o archivo</button>'
            . '<button type="button" class="cmh-open-modal" data-target="cmh-box-programar" '
            . 'data-title="Programar próximo mantenimiento" data-subtitle="Solo fija la fecha. No crea una intervención.">Programar mantenimiento</button>'
            . '<div class="cmh-menu-sep"></div>'
            . '<a href="' . esc_url( self::admin_url( CMH_SLUG . '-reports', [ 'machine_id' => $machine_id ] ) ) . '">Ver reporte de la máquina</a>'
            . '<a href="' . esc_url( self::interv_url( [ 'machine_id' => $machine_id ] ) ) . '">Ver todas sus intervenciones</a>'
            . '<a class="cmh-btn-print" href="#">Imprimir hoja de vida</a>'
            . '<a href="' . esc_url( self::export_nonce_url( 'interventions', [ 'machine_id' => $machine_id ] ) ) . '">Exportar intervenciones (CSV)</a>'
            . '<div class="cmh-menu-sep"></div>'
            . '<a href="' . esc_url( $m->branch_id
                ? self::admin_url( CMH_SLUG . '-companies', [ 'branch_id' => $m->branch_id ] )
                : self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $m->city_id ] ) ) . '">Volver a la sucursal</a>'
            . '</div></div>'
            . '</div></div>';

        // ── Indicadores en dos niveles (v2.4) ────────────────────────────
        // Cuatro que se miran a diario, grandes; los otros seis en una franja
        // compacta debajo. Siguen estando todos y siguen enlazando a su lista.
        $mu = function ( $args = [] ) use ( $machine_id ) {
            return self::interv_url( array_merge( [ 'machine_id' => $machine_id ], $args ) );
        };

        echo '<div class="cmh-grid-primary">';
        self::metric_card( 'Disponibilidad ' . CMH_Metrics::month_label( $month, $year ),
            CMH_Metrics::fmt_pct( $avail_now ), 'mes actual', $avail_acc,
            self::admin_url( CMH_SLUG . '-reports', [ 'machine_id' => $machine_id ] ) );
        self::metric_card( 'Intervenciones', $stats->total, 'historial completo', 'blue', $mu() );
        self::metric_card( 'Por cobrar', '$' . number_format( (float) $stats->por_cobrar, 0, ',', '.' ),
            'saldo pendiente', (float) $stats->por_cobrar > 0 ? 'warn' : 'ok', $mu( [ 'pay' => 'pending' ] ) );
        self::metric_card( 'Costo total', '$' . number_format( (float) $stats->cost, 0, ',', '.' ),
            'historial completo', 'blue', $mu() );
        echo '</div>';

        echo '<div class="cmh-stats-strip">';
        self::stat_item( 'Preventivos',      (int) $stats->preventivos, $mu( [ 'type' => 'preventivo' ] ) );
        self::stat_item( 'Correctivos/Averías', (int) $stats->correctivos, $mu( [ 'affects' => 1 ] ) );
        self::stat_item( 'H. parada averías', number_format( (float) $stats->downtime_averia, 1, ',', '.' ) . ' h', $mu( [ 'affects' => 1 ] ) );
        if ( CMH_Taxonomy::quote_pstates() ) {
            self::stat_item( 'En trámite', '$' . number_format( (float) $stats->en_tramite, 0, ',', '.' ),
                $mu( [ 'pay' => 'quote' ] ) );
        }
        self::stat_item( 'MTTR',            CMH_Metrics::fmt_mttr( $mttr_all ) );
        self::stat_item( 'MTBF',            CMH_Metrics::fmt_mttr( CMH_Metrics::mtbf( $machine_id, 12 ) ) );
        self::stat_item( 'Horómetro',       number_format( (float) $m->current_hourmeter, 1, ',', '.' ) . ' h' );
        echo '</div>';


        // Tabs
        echo '<div class="cmh-tabs-wrapper">'
            . '<div class="cmh-tabs">'
            . '<a href="#tab-resumen"  class="cmh-tab" data-tab="resumen">Resumen</a>'
            . '<a href="#tab-interv"   class="cmh-tab" data-tab="interv">Intervenciones (' . intval( $stats->total ) . ')</a>'
            . '<a href="#tab-disponib" class="cmh-tab" data-tab="disponib">Indicadores</a>'
            . '<a href="#tab-pdfs"     class="cmh-tab" data-tab="pdfs">PDFs</a>'
            . '<a href="#tab-tecnicos" class="cmh-tab" data-tab="tecnicos">Técnicos</a>'
            . '<a href="#tab-editar"   class="cmh-tab" data-tab="editar">Editar</a>'
            . '</div>';

        echo '<div class="cmh-main">';   // v2.4 — a todo el ancho: la columna lateral se fue a ventanas

        // Tab: Resumen
        echo '<div id="tab-resumen" class="cmh-tab-panel cmh-panel"><h2>Datos de la máquina</h2>'
            . '<div class="cmh-info-grid">'
            . '<div><span>Código</span><strong>' . esc_html( $m->machine_code ) . '</strong></div>'
            . '<div><span>Marca / Modelo</span><strong>' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</strong></div>'
            . '<div><span>Serial</span><strong>' . esc_html( $m->serial ?: '—' ) . '</strong></div>'
            . '<div><span>Contacto</span><strong>' . esc_html( $m->contact ?: '—' ) . '</strong></div>'
            . '<div><span>H. programadas / mes</span><strong>' . esc_html( number_format( (float)$m->scheduled_hours_monthly, 0 ) ) . ' h</strong></div>'
            . '<div><span>Última intervención</span><strong>' . esc_html( $last ? $last->intervention_date : '—' ) . '</strong></div>'
            . '<div><span>Último técnico</span><strong>' . esc_html( $last && $last->technician ? $last->technician : '—' ) . '</strong></div>'
            . '<div><span>Averías este mes</span><strong>' . intval( $averia_now ) . '</strong></div>'
            . '<div><span>Próximo mantenimiento</span><strong>' . $maint_label . '</strong></div>'
            . '<div><span>Recurrencia</span><strong>' . esc_html( CMH_Schedule::interval_label( $m->maintenance_interval_days ?? 0 ) ) . '</strong></div>'
            . '</div>'
            . ( $m->notes ? '<div class="cmh-note"><strong>Notas:</strong> ' . esc_html( $m->notes ) . '</div>' : '' )
            . '</div>';

        // Tab: Intervenciones — tabla por defecto, timeline como registro detallado.
        echo '<div id="tab-interv" class="cmh-tab-panel cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Intervenciones</h2>'
            . '<div class="cmh-view-switch">'
            . '<button type="button" class="button button-small cmh-view-btn active" data-view="tabla">Tabla</button>'
            . '<button type="button" class="button button-small cmh-view-btn" data-view="linea">Línea de tiempo</button>'
            . '</div></div>';

        echo '<div class="cmh-view cmh-view-tabla">';
        self::intervention_table( $machine_id );
        echo '</div>';

        echo '<div class="cmh-view cmh-view-linea" style="display:none">'
            . '<p class="cmh-hint">Cada tarjeta es una intervención con todo su detalle y sus acciones.</p>';
        self::intervention_cards( $machine_id );
        echo '</div></div>';

        // Tab: Indicadores — gráficas de la máquina + tabla de disponibilidad mensual
        echo '<div id="tab-disponib" class="cmh-tab-panel cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Indicadores de la máquina</h2>'
            . '<div style="display:flex;gap:8px;align-items:center">'
            . '<a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-reports', [ 'machine_id' => $machine_id ] ) ) . '">Reporte completo</a>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'availability', [ 'machine_id' => $machine_id ] ) ) . '">Exportar CSV</a>'
            . '</div></div>';
        CMH_Reports::machine_charts( $machine_id, true );
        echo '<h3 class="cmh-chart-title" style="margin-top:22px">Disponibilidad mensual — detalle</h3>';
        self::availability_table( $machine_id );
        echo '</div>';

        // Tab: PDFs
        echo '<div id="tab-pdfs" class="cmh-tab-panel cmh-panel"><h2>Archivos y PDFs</h2>';
        self::files_table( $machine_id );
        echo '</div>';

        // Tab: Técnicos (v0.9)
        echo '<div id="tab-tecnicos" class="cmh-tab-panel cmh-panel"><h2>Técnicos y tareas</h2>';
        self::machine_techs_tab( $machine_id );
        echo '</div>';

        // Tab: Editar
        $interv_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['interventions']} WHERE machine_id=%d", $machine_id ) );
        echo '<div id="tab-editar" class="cmh-tab-panel cmh-panel"><h2>Editar máquina</h2>';
        self::edit_machine_form( $m );
        echo '<div style="margin-top:28px;padding:16px;border:1px solid #d63638;border-radius:6px">'
            . '<h3 style="color:#d63638;margin:0 0 8px;font-size:14px">Zona de peligro</h3>'
            . '<p style="margin:0 0 12px;font-size:13px;color:#646970">Elimina esta máquina y todos sus registros (' . $interv_count . ' intervenciones). Esta acción es irreversible.</p>'
            . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Eliminar máquina \'+' . json_encode( $m->machine_code ) . '+\' con ' . $interv_count . ' intervenciones? Esta acción es irreversible.\')">'
            . '<input type="hidden" name="action" value="cm_delete_machine">'
            . '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $m->city_id ] ) ) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
            . '<button type="submit" class="button" style="background:#d63638;border-color:#d63638;color:#fff">Eliminar máquina</button>'
            . '</form>'
            . '</div>';
        echo '</div>';

        echo '</div></div>';   // cierra .cmh-main y .cmh-tabs-wrapper

        // ── Formularios que se abren en ventana (v2.4) ────────────────────
        // Antes vivían en una columna fija de 340px que estrechaba la tabla y
        // los dejaba apretados. Ahora el HTML está aquí, oculto, y el modal lo
        // trae al abrirse y lo devuelve al cerrarse, sin perder lo escrito.
        echo '<div id="cmh-box-intervencion" style="display:none">';
        self::intervention_form( $machine_id, (float) $m->current_hourmeter, $m->status );
        echo '</div>';

        echo '<div id="cmh-box-archivo" style="display:none">';
        self::upload_form( $machine_id );
        echo '</div>';

        echo '<div id="cmh-box-programar" style="display:none">';
        self::form_start( 'cm_schedule_maintenance' );
        echo '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ) ) . '">'
            . '<div class="cmh-form-grid">'
            . '<label>Fecha del próximo mantenimiento'
            . '<input type="date" name="next_maintenance_date" value="' . esc_attr( $m->next_maintenance_date ?: '' ) . '" min="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></label>'
            . '<label>Repetir cada'
            . CMH_Schedule::interval_field( (int) ( $m->maintenance_interval_days ?? 0 ) ) . '</label>'
            . '</div>'
            . '<p class="cmh-hint" style="margin-top:12px">Solo fija la fecha del próximo mantenimiento. No crea una intervención. '
            . 'Si las tareas automáticas están activas, se creará la tarea para el técnico principal.</p>'
            . '<div class="cmh-form-actions">'
            . '<button class="button button-primary">Guardar fecha</button>'
            . ( $m->next_maintenance_date ? '<button type="submit" name="clear_date" value="1" formnovalidate class="button" style="color:#d63638;border-color:#d63638">Quitar fecha</button>' : '' )
            . '</div></form></div>';

        self::page_footer();
    }

    // =========================================================================
    // Componentes UI
    // =========================================================================

    /**
     * Estado vacío con salida (v2.4).
     *
     * Una pantalla vacía que solo dice «sin datos» deja al usuario sin saber
     * qué sigue. El cuarto argumento agrega el botón de lo que toca hacer:
     * `[ 'label' => …, 'modal' => 'cmh-box-…' ]` abre el formulario que ya está
     * en la página, y `[ 'label' => …, 'url' => … ]` lleva a otra pantalla.
     */
    private static function empty_state( $icon, $title, $desc = '', $cta = [] ) {
        echo '<div class="cmh-empty">'
            . '<div class="cmh-empty-icon"><span class="dashicons ' . esc_attr( $icon ) . '"></span></div>'
            . '<strong>' . esc_html( $title ) . '</strong>'
            . ( $desc ? '<p>' . esc_html( $desc ) . '</p>' : '' )
            . self::empty_state_cta( $cta )
            . '</div>';
    }

    private static function empty_state_cta( $cta ) {
        if ( empty( $cta['label'] ) ) return '';

        if ( ! empty( $cta['modal'] ) ) {
            return '<div class="cmh-empty-actions"><button type="button" class="button button-primary cmh-open-modal" '
                . 'data-target="' . esc_attr( $cta['modal'] ) . '" '
                . 'data-title="' . esc_attr( $cta['title'] ?? $cta['label'] ) . '" '
                . 'data-subtitle="' . esc_attr( $cta['subtitle'] ?? '' ) . '">'
                . esc_html( $cta['label'] ) . '</button></div>';
        }
        if ( ! empty( $cta['url'] ) ) {
            return '<div class="cmh-empty-actions"><a class="button button-primary" href="' . esc_url( $cta['url'] ) . '">'
                . esc_html( $cta['label'] ) . '</a></div>';
        }
        return '';
    }

    private static function empty_state_inline( $msg ) {
        return '<em style="color:#646970">' . esc_html( $msg ) . '</em>';
    }

    public static function machines_table( $city_id = 0, $branch_id = 0, $filters = [] ) {
        global $wpdb; $t = CMH_Core::tables();
        $where = []; $params = [];

        if ( $city_id   ) { $where[] = 'm.city_id=%d';   $params[] = $city_id; }
        if ( $branch_id ) { $where[] = 'm.branch_id=%d'; $params[] = $branch_id; }
        if ( ! empty( $filters['q'] ) ) {
            $like = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
            $where[] = '(m.machine_code LIKE %s OR m.serial LIKE %s OR m.brand LIKE %s OR m.model LIKE %s OR m.contact LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like );
        }
        if ( ! empty( $filters['status'] ) ) { $where[] = 'm.status=%s'; $params[] = $filters['status']; }

        $w   = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql = "SELECT m.*, c.name company_name, ci.name city_name, COALESCE(b.name,'') branch_name,
                (SELECT COUNT(*) FROM {$t['interventions']} i WHERE i.machine_id=m.id) interventions,
                (SELECT MAX(i.intervention_date) FROM {$t['interventions']} i WHERE i.machine_id=m.id) last_intervention
                FROM {$t['machines']} m JOIN {$t['companies']} c ON c.id=m.company_id JOIN {$t['cities']} ci ON ci.id=m.city_id LEFT JOIN {$t['branches']} b ON b.id=m.branch_id $w ORDER BY m.machine_code";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

        if ( ! $rows ) {
            // La salida depende de por qué está vacío: filtrando se ofrece quitar
            // el filtro; dentro de una ciudad o sucursal, agregar la máquina.
            $filtrando = (bool) array_filter( (array) $filters, function ( $v ) { return $v !== '' && $v !== null; } );
            if ( $filtrando ) {
                self::empty_state( 'dashicons-hammer', 'Sin máquinas', 'Ninguna máquina coincide con estos filtros.',
                    [ 'label' => 'Ver todas las máquinas', 'url' => self::admin_url( CMH_SLUG . '-machines' ) ] );
            } elseif ( $city_id || $branch_id ) {
                self::empty_state( 'dashicons-hammer', 'Sin máquinas', 'Aquí todavía no hay ninguna máquina registrada.',
                    [ 'label' => 'Agregar la primera máquina', 'modal' => 'cmh-box-maquina', 'title' => 'Agregar máquina' ] );
            } else {
                self::empty_state( 'dashicons-hammer', 'Sin máquinas', 'Aún no hay máquinas registradas.' );
            }
            return;
        }

        // v2.4 — Buscador que filtra al escribir y encabezados que ordenan.
        echo '<div class="cmh-tablebar">'
            . '<input type="search" class="cmh-table-search" data-table="cmh-tbl-maquinas" placeholder="Filtrar por código, marca, serial, ubicación…">'
            . '<span class="cmh-count"></span></div>';
        echo '<div class="cmh-table-scroll"><table id="cmh-tbl-maquinas" class="widefat cmh cmh-machine-table cmh-sortable"><thead><tr>'
            . '<th data-sort="text">Código</th><th data-sort="text">Marca / Modelo</th><th data-sort="text">Serial</th><th data-sort="text">Ubicación</th>'
            . '<th data-sort="num">Horómetro</th><th data-sort="text">Estado</th><th data-sort="num">Interv.</th><th data-sort="text">Última</th><th></th>'
            . '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $loc = $r->company_name . ' / ' . $r->city_name . ( $r->branch_name ? ' / ' . $r->branch_name : '' );
            $url = esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $r->id ] ) );
            echo '<tr>'
                . '<td><strong>' . esc_html( $r->machine_code ) . '</strong></td>'
                . '<td>' . esc_html( trim( $r->brand . ' ' . $r->model ) ) . '</td>'
                . '<td>' . esc_html( $r->serial ?: '—' ) . '</td>'
                . '<td style="font-size:12px">' . esc_html( $loc ) . '</td>'
                . '<td>' . esc_html( $r->current_hourmeter ) . ' h</td>'
                . '<td>' . self::status_badge( $r->status ) . '</td>'
                . '<td>' . intval( $r->interventions ) . '</td>'
                . '<td>' . esc_html( $r->last_intervention ?: '—' ) . '</td>'
                . '<td><a class="button button-small" href="' . $url . '">Hoja de vida</a></td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function machines_mini_table() {
        global $wpdb; $t = CMH_Core::tables();
        $rows = $wpdb->get_results( "SELECT id, machine_code, brand, model, current_hourmeter, status FROM {$t['machines']} ORDER BY id DESC LIMIT 8" );
        if ( ! $rows ) { echo '<p style="color:#646970;font-size:13px">Aún no hay máquinas.</p>'; return; }
        echo '<div class="cmh-mini-list">';
        foreach ( $rows as $r ) {
            echo '<a href="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $r->id ] ) ) . '">'
                . '<div><strong>' . esc_html( $r->machine_code ) . '</strong>' . self::status_badge( $r->status ) . '</div>'
                . '<span>' . esc_html( trim( $r->brand . ' ' . $r->model ) ) . ' · H: ' . esc_html( $r->current_hourmeter ) . '</span>'
                . '</a>';
        }
        echo '</div>';
    }

    public static function machine_form( $company_id, $city_id, $branch_id = 0 ) {
        global $wpdb; $t = CMH_Core::tables();
        $company  = $wpdb->get_row( $wpdb->prepare( "SELECT code FROM {$t['companies']} WHERE id=%d", $company_id ) );
        $city     = $wpdb->get_row( $wpdb->prepare( "SELECT code FROM {$t['cities']}    WHERE id=%d", $city_id ) );
        $redirect = self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] );
        $example  = ( $company && $city ) ? $company->code . ' ' . $city->code . ' TY No. 001' : 'EMP CIU TY No. 001';

        self::form_start( 'cm_save_machine' );
        echo '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="city_id"    value="' . intval( $city_id )    . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect ) . '">';

        echo '<label>N.º de máquina <em>*</em> <span class="cmh-tooltip" title="Número o código alfanumérico que identifica la máquina. Se construye el código: ' . esc_attr( $example ) . '">[?]</span></label>'
            . '<input name="machine_number" placeholder="001" required class="cmh-uppercase">'
            . '<p style="font-size:12px;color:#646970;margin:4px 0 12px">Código resultante: <strong>' . esc_html( $example ) . '</strong></p>';

        echo '<div class="cmh-form-grid">'
            . '<label>Marca <em>*</em><input name="brand" placeholder="TOYOTA" required class="cmh-uppercase"></label>'
            . '<label>Modelo<input name="model" placeholder="8FGU25" class="cmh-uppercase"></label>'
            . '<label>Serial<input name="serial" class="cmh-uppercase"></label>'
            . '<label>Contacto<input name="contact"></label>'
            . '<label>Horómetro <span class="cmh-optional">(opcional)</span><input type="number" step="0.01" name="current_hourmeter" value="" min="0" placeholder="0"></label>'
            . '<label>H. programadas / mes <span class="cmh-tooltip" title="Horas de turno mensual. Base del cálculo de disponibilidad.">[?]</span>'
            . '<input type="number" step="1" name="scheduled_hours_monthly" value="480" min="1" required></label>'
            . '</div>'
            . '<label>Estado<select name="status"><option value="activa">Activa</option><option value="mantenimiento">En mantenimiento</option><option value="inactiva">Inactiva</option></select></label>'
            . '<label>Próximo mantenimiento <span class="cmh-optional">(opcional)</span><input type="date" name="next_maintenance_date"></label>'
            . '<label>Mantenimiento recurrente <span class="cmh-tooltip" title="Al registrar un preventivo se reprograma la próxima fecha sumando este intervalo.">[?]</span>'
            . CMH_Schedule::interval_field( 0 ) . '</label>'
            . '<label>Notas<textarea name="notes"></textarea></label>'
            . '<div class="cmh-form-actions"><button class="button button-primary">Guardar máquina</button></div>'
            . '</form>';
    }

    public static function edit_machine_form( $m ) {
        self::form_start( 'cm_update_machine' );
        echo '<input type="hidden" name="machine_id" value="' . intval( $m->id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $m->id ] ) ) . '">';

        echo '<label>Código de máquina</label>'
            . '<input name="machine_code" value="' . esc_attr( $m->machine_code ) . '" class="cmh-uppercase">'
            . '<p style="font-size:12px;color:#646970;margin:4px 0 12px">Modifica solo si es necesario corregir el código. Debe ser único.</p>';

        echo '<div class="cmh-form-grid">'
            . '<label>Marca <em>*</em><input name="brand" value="' . esc_attr( $m->brand ) . '" required class="cmh-uppercase"></label>'
            . '<label>Modelo<input name="model" value="' . esc_attr( $m->model ) . '" class="cmh-uppercase"></label>'
            . '<label>Serial<input name="serial" value="' . esc_attr( $m->serial ) . '" class="cmh-uppercase"></label>'
            . '<label>Contacto<input name="contact" value="' . esc_attr( $m->contact ) . '"></label>'
            . '<label>Horómetro actual<input type="number" step="0.01" name="current_hourmeter" value="' . esc_attr( $m->current_hourmeter ) . '" data-prev-hourmeter="' . esc_attr( $m->current_hourmeter ) . '"></label>'
            . '<label>H. programadas / mes<input type="number" step="1" name="scheduled_hours_monthly" value="' . esc_attr( $m->scheduled_hours_monthly ) . '" min="1" required></label>'
            . '</div><label>Estado<select name="status">';
        foreach ( [ 'activa' => 'Activa', 'mantenimiento' => 'En mantenimiento', 'inactiva' => 'Inactiva', 'fuera_servicio' => 'Fuera de servicio' ] as $k => $v )
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $m->status, $k, false ) . '>' . esc_html( $v ) . '</option>';
        echo '</select></label>'
            . '<label>Próximo mantenimiento <span class="cmh-optional">(opcional)</span><input type="date" name="next_maintenance_date" value="' . esc_attr( $m->next_maintenance_date ?: '' ) . '"></label>'
            . '<label>Mantenimiento recurrente <span class="cmh-tooltip" title="Al registrar un preventivo se reprograma la próxima fecha sumando este intervalo.">[?]</span>'
            . CMH_Schedule::interval_field( (int) ( $m->maintenance_interval_days ?? 0 ) ) . '</label>'
            . '<label>Notas<textarea name="notes">' . esc_textarea( (string) $m->notes ) . '</textarea></label>'
            . '<button class="button button-primary">Guardar cambios</button></form>';
    }

    public static function interventions_table( $limit = 20, $machine_id = 0 ) {
        global $wpdb; $t = CMH_Core::tables();
        $where = $machine_id ? $wpdb->prepare( 'WHERE i.machine_id=%d', $machine_id ) : '';
        $rows  = $wpdb->get_results(
            "SELECT i.*, m.machine_code, f.file_url FROM {$t['interventions']} i LEFT JOIN {$t['machines']} m ON m.id=i.machine_id LEFT JOIN {$t['files']} f ON f.intervention_id=i.id $where GROUP BY i.id ORDER BY i.intervention_date DESC, i.id DESC LIMIT " . intval( $limit )
        );
        if ( ! $rows ) {
            self::empty_state( 'dashicons-calendar-alt', 'Sin intervenciones',
                'Entran solas cuando un técnico envía un formato, y también se pueden registrar a mano desde la máquina.',
                $machine_id
                    ? [ 'label' => 'Registrar la primera intervención', 'modal' => 'cmh-box-intervencion', 'title' => 'Registrar intervención' ]
                    : [ 'label' => 'Ir a las máquinas', 'url' => self::admin_url( CMH_SLUG . '-machines' ) ] );
            return;
        }

        echo '<table class="widefat cmh"><thead><tr><th>Fecha</th><th>Máquina</th><th>Tipo</th><th>Técnico</th><th>H. parada</th><th>Costo</th><th>Pago</th><th>PDF</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $pay = self::payment_badge( $r->payment_status, $r->cost, $r->paid_amount );
            echo '<tr>'
                . '<td>' . esc_html( $r->intervention_date ) . '</td>'
                . '<td>' . esc_html( $r->machine_code ) . '</td>'
                . '<td>' . self::mtype_badge( $r->maintenance_type ?: $r->form_type ) . '</td>'
                . '<td>' . esc_html( $r->technician ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->downtime_hours ) . ' h</td>'
                . '<td>$' . number_format( (float) $r->cost, 0, ',', '.' ) . '</td>'
                . '<td>' . ( $pay ?: '—' ) . '</td>'
                . '<td>' . ( $r->file_url ? '<a target="_blank" href="' . esc_url( $r->file_url ) . '">Ver PDF</a>' : '—' ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }

    /** v2.3 — Delegado en la taxonomía configurable. */
    public static function mtype_badge( $type ) {
        return CMH_Taxonomy::mtype_badge( $type );
    }

    /** Estados de pago disponibles, ya configurables desde Ajustes. */
    public static function payment_statuses() {
        return CMH_Taxonomy::pstate_labels();
    }

    /**
     * Concilia estado y monto abonado antes de guardar.
     *
     * El estado que elige el usuario manda, y lo que hace con el dinero depende
     * de cómo esté configurado ese estado (v2.3):
     *   · «cobrado» iguala lo abonado al costo;
     *   · «anulado» respeta lo abonado y deja de contar en Por cobrar;
     *   · «sigue por cobrar» respeta lo abonado, acotado al costo.
     *
     * Los dos estados históricos conservan su comportamiento exacto para no
     * alterar datos que ya existen: «Pagado» iguala al costo y «Pendiente» pone
     * el abonado en cero, que es la conciliación que introdujo la v1.0.1.
     *
     * @return array{0:string,1:float} [ estado, abonado ]
     */
    public static function normalize_payment( $status, $cost, $paid ) {
        // Los ceros van como 0.0 y no como 0: max() devuelve el primer argumento
        // cuando empatan, y un entero suelto en el camino del dinero termina
        // saliendo por la API con otro tipo del que tienen los demás importes.
        $cost   = max( 0.0, (float) $cost );
        $paid   = max( 0.0, (float) $paid );
        $status = sanitize_key( $status );

        $states = CMH_Taxonomy::pstates();
        if ( ! isset( $states[ $status ] ) ) $status = self::derive_payment_status( $cost, $paid );

        $money = CMH_Taxonomy::pstate_money( $status );

        if ( 'paid' === $money ) return [ $status, $cost ];
        if ( 'void' === $money ) return [ $status, min( $paid, $cost ) ];

        // En trámite es una etapa del proceso, no un monto derivado: se respeta
        // lo que haya abonado (raro, pero un anticipo puede existir) y no se
        // autocorrige a otro estado aunque el abono cubra el costo.
        if ( 'quote' === $money ) return [ $status, min( $paid, $cost ) ];

        // «No contabilizar» tampoco se autocorrige: lo que el usuario escribió
        // queda tal cual, simplemente no entra en ninguna suma.
        if ( 'ignore' === $money ) return [ $status, min( $paid, $cost ) ];

        // Comportamiento histórico de los dos estados de siempre.
        if ( 'pendiente' === $status ) return [ 'pendiente', 0.0 ];

        $paid = min( $paid, $cost );

        // «Parcial» es el único que se autocorrige al salirse del rango: es un
        // estado derivado del monto, no una etapa del proceso.
        if ( 'parcial' === $status ) {
            if ( $cost > 0 && $paid >= $cost ) return [ 'pagado', $cost ];
            if ( $paid <= 0 )                  return [ 'pendiente', 0.0 ];
        }
        return [ $status, $paid ];
    }

    /** Deriva el estado de pago a partir del costo y lo abonado. */
    public static function derive_payment_status( $cost, $paid ) {
        $cost = (float) $cost; $paid = (float) $paid;
        if ( $cost <= 0 ) return $paid > 0 ? 'pagado' : 'pendiente';
        if ( $paid >= $cost ) return 'pagado';
        if ( $paid > 0 )      return 'parcial';
        return 'pendiente';
    }

    /** Badge de estado de pago con saldo. Vacío si no hay costo ni abono (no aplica). */
    public static function payment_badge( $status, $cost = 0, $paid = 0 ) {
        $cost = (float) $cost; $paid = (float) $paid;
        if ( $cost <= 0 && $paid <= 0 ) return '';

        $status = $status ?: self::derive_payment_status( $cost, $paid );
        $money  = CMH_Taxonomy::pstate_money( $status );
        $saldo  = max( 0, $cost - $paid );

        // El saldo solo se muestra cuando de verdad se espera cobrarlo. Lo que
        // está en trámite lleva su propia palabra: es un monto cotizado, no una
        // deuda, y llamarlo «saldo» haría creer que el cliente ya lo debe.
        $extra = '';
        if ( $saldo > 0 && $money === 'pending' ) {
            $extra = ' <span style="color:#646970;font-size:11px;white-space:nowrap">Saldo $' . number_format( $saldo, 0, ',', '.' ) . '</span>';
        } elseif ( $saldo > 0 && $money === 'quote' ) {
            $extra = ' <span style="color:#646970;font-size:11px;white-space:nowrap">Cotizado $' . number_format( $saldo, 0, ',', '.' ) . '</span>';
        } elseif ( $money === 'ignore' ) {
            $extra = ' <span style="color:#646970;font-size:11px;white-space:nowrap">No se contabiliza</span>';
        }

        return CMH_Taxonomy::pstate_badge( $status ) . $extra;
    }

    /**
     * v2.3 — Intervenciones de una máquina, una fila por intervención.
     *
     * Fuente única para la tabla y para el timeline: antes cada uno traía sus
     * filas por su cuenta y el timeline se quedó sin `GROUP BY`, así que una
     * intervención con dos archivos aparecía dos veces.
     */
    // =========================================================================
    // Pantalla «Intervenciones» — el destino de los cuadros del dashboard (v2.3)
    // =========================================================================

    /** Filtros de la lista de intervenciones, saneados. */
    public static function interv_filters() {
        $type = sanitize_key( $_GET['type'] ?? '' );
        if ( $type !== '' && ! isset( CMH_Taxonomy::mtypes()[ $type ] ) ) $type = '';

        $pay = sanitize_key( $_GET['pay'] ?? '' );
        if ( ! in_array( $pay, [ '', 'pending', 'paid', 'void' ], true ) ) $pay = '';

        $state = sanitize_key( $_GET['state'] ?? '' );
        if ( $state !== '' && ! isset( CMH_Taxonomy::pstates()[ $state ] ) ) $state = '';

        return [
            'type'       => $type,
            'pay'        => $pay,
            'state'      => $state,
            'affects'    => ( ( $_GET['affects'] ?? '' ) === '1' ) ? 1 : 0,
            'company_id' => intval( $_GET['company_id'] ?? 0 ),
            'machine_id' => intval( $_GET['machine_id'] ?? 0 ),
            'q'          => sanitize_text_field( $_GET['q'] ?? '' ),
        ];
    }

    /** URL de la lista con un filtro puesto. Lo usan las tarjetas del dashboard. */
    public static function interv_url( $args = [] ) {
        return self::admin_url( CMH_SLUG . '-interventions', array_filter( $args ) );
    }

    private static function interv_where( $f ) {
        global $wpdb;
        $w = [];
        if ( $f['type'] )       $w[] = $wpdb->prepare( 'i.maintenance_type=%s', $f['type'] );
        if ( $f['state'] )      $w[] = $wpdb->prepare( 'i.payment_status=%s', $f['state'] );
        if ( $f['affects'] )    $w[] = 'i.affects_availability=1';
        if ( $f['company_id'] ) $w[] = $wpdb->prepare( 'm.company_id=%d', $f['company_id'] );
        if ( $f['machine_id'] ) $w[] = $wpdb->prepare( 'i.machine_id=%d', $f['machine_id'] );
        if ( $f['q'] !== '' )   $w[] = $wpdb->prepare( '(m.machine_code LIKE %s OR i.technician LIKE %s)',
                                    '%' . $wpdb->esc_like( $f['q'] ) . '%', '%' . $wpdb->esc_like( $f['q'] ) . '%' );

        // El filtro de dinero se arma con la taxonomía, no con nombres fijos.
        if ( $f['pay'] === 'pending' ) {
            // Lo mismo que suma el KPI: ni anulado ni en trámite.
            $w[] = 'i.cost > i.paid_amount' . CMH_Taxonomy::not_billable_sql( 'i.' );
        } elseif ( $f['pay'] === 'paid' ) {
            $w[] = 'i.cost > 0 AND i.paid_amount >= i.cost';
        } elseif ( $f['pay'] === 'void' || $f['pay'] === 'quote' ) {
            $states = ( $f['pay'] === 'void' ) ? CMH_Taxonomy::void_pstates() : CMH_Taxonomy::quote_pstates();
            $w[]    = $states
                ? 'i.payment_status IN (' . implode( ',', array_map( function ( $s ) { return "'" . esc_sql( $s ) . "'"; }, $states ) ) . ')'
                : '1=0';
        }

        return $w ? ( 'WHERE ' . implode( ' AND ', $w ) ) : '';
    }

    public static function page_interventions() {
        if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( 'Sin permisos.' );
        global $wpdb; $t = CMH_Core::tables();

        $f     = self::interv_filters();
        $where = self::interv_where( $f );

        self::page_header( 'Intervenciones', [
            [ 'label' => 'Máquinas', 'url' => self::admin_url( CMH_SLUG ) ],
            [ 'label' => 'Intervenciones' ],
        ] );

        $rows = $wpdb->get_results(
            "SELECT i.*, m.machine_code, c.name AS company_name, MAX(fi.file_url) AS file_url
             FROM {$t['interventions']} i
             LEFT JOIN {$t['machines']}  m  ON m.id  = i.machine_id
             LEFT JOIN {$t['companies']} c  ON c.id  = m.company_id
             LEFT JOIN {$t['files']}     fi ON fi.intervention_id = i.id
             $where GROUP BY i.id
             ORDER BY i.intervention_date DESC, i.id DESC LIMIT 500"
        );

        $totals = $wpdb->get_row(
            "SELECT COUNT(DISTINCT i.id) n, " . CMH_Taxonomy::money_sum_sql( 'cost', 'i.' ) . " cost, "
            . CMH_Taxonomy::balance_sum_sql( 'i.' ) . " saldo, "
            . CMH_Taxonomy::quote_sum_sql( 'i.' ) . " en_tramite
             FROM {$t['interventions']} i
             LEFT JOIN {$t['machines']} m ON m.id = i.machine_id
             $where"
        );

        // ── Resumen de lo filtrado ───────────────────────────────────────────
        echo '<div class="cmh-grid">';
        self::metric_card( 'Intervenciones', intval( $totals->n ?? 0 ), 'con este filtro', 'blue' );
        self::metric_card( 'Costo', '$' . number_format( (float) ( $totals->cost ?? 0 ), 0, ',', '.' ), 'suma del filtro', 'blue' );
        self::metric_card( 'Por cobrar', '$' . number_format( (float) ( $totals->saldo ?? 0 ), 0, ',', '.' ),
            'saldo del filtro', ( (float) ( $totals->saldo ?? 0 ) ) > 0 ? 'warn' : 'ok' );
        if ( CMH_Taxonomy::quote_pstates() ) {
            self::metric_card( 'En trámite', '$' . number_format( (float) ( $totals->en_tramite ?? 0 ), 0, ',', '.' ),
                'cotizado, sin aprobar', 'blue' );
        }
        echo '</div>';

        self::interv_filter_bar( $f );

        if ( ! $rows ) {
            echo '<div class="cmh-panel">';
            self::empty_state( 'dashicons-search', 'Nada con estos filtros', 'Prueba a quitar alguno o a ampliar la búsqueda.',
                [ 'label' => 'Quitar los filtros', 'url' => self::interv_url( [] ) ] );
            echo '</div>';   // cierra .cmh-panel; page_footer cierra el .wrap
            self::page_footer();
            return;
        }

        // v2.7 — Editar desde aquí, sin tener que entrar a la máquina.
        // El formulario se pinta SOLO para la fila que se está editando, que
        // llega por la URL: con 500 filas, 500 formularios ocultos harían la
        // pantalla inusable. Así además se conserva el filtro al volver.
        $editando = intval( $_GET['edit'] ?? 0 );

        echo '<div class="cmh-panel"><div class="cmh-table-scroll"><table class="widefat cmh"><thead><tr>'
            . '<th>Fecha</th><th>Máquina</th><th>Tipo</th><th>Técnico</th>'
            . '<th class="cmh-num">Parada</th><th class="cmh-num">Costo</th><th>Pago</th><th>PDF</th><th></th>'
            . '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $cost = (float) $r->cost;
            echo '<tr>'
                . '<td class="cmh-nowrap">' . esc_html( $r->intervention_date ) . '</td>'
                . '<td class="cmh-nowrap"><a href="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => (int) $r->machine_id ] ) ) . '">'
                . esc_html( $r->machine_code ?: '—' ) . '</a>'
                . ( $r->company_name ? '<br><span class="cmh-muted">' . esc_html( $r->company_name ) . '</span>' : '' ) . '</td>'
                . '<td>' . self::mtype_badge( $r->maintenance_type ?: $r->form_type )
                . ( $r->affects_availability ? ' <span class="cmh-badge cmh-badge-averia cmh-badge-xs">Disp.</span>' : '' ) . '</td>'
                . '<td>' . esc_html( $r->technician ?: '—' ) . '</td>'
                . '<td class="cmh-num">' . esc_html( 0 + $r->downtime_hours ) . ' h</td>'
                . '<td class="cmh-num">' . ( $cost > 0 ? '$' . number_format( $cost, 0, ',', '.' ) : '—' ) . '</td>'
                . '<td>' . ( self::payment_badge( $r->payment_status, $r->cost, $r->paid_amount ) ?: '—' ) . '</td>'
                . '<td>' . ( $r->file_url
                    ? '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( $r->file_url ) . '">Ver</a>'
                    : '<span class="cmh-muted">—</span>' ) . '</td>'
                . '<td class="cmh-nowrap">'
                . ( $editando === (int) $r->id
                    ? '<a class="button button-small" href="' . esc_url( self::interv_url( $f ) . '#iv-' . intval( $r->id ) ) . '">Cerrar</a>'
                    : '<a class="button button-small" href="' . esc_url( self::interv_url( array_merge( $f, [ 'edit' => (int) $r->id ] ) ) . '#iv-' . intval( $r->id ) ) . '">Editar</a>' )
                . '</td>'
                . '</tr>';

            if ( $editando === (int) $r->id ) {
                echo '<tr class="cmh-edit-row" id="iv-' . intval( $r->id ) . '"><td colspan="9">'
                    . '<p class="cmh-hint" style="margin:0 0 10px">Editando la intervención <strong>#' . intval( $r->id )
                    . '</strong> de <strong>' . esc_html( $r->machine_code ?: '—' ) . '</strong>. '
                    . 'El cambio queda anotado en la línea de tiempo de la máquina.</p>';
                self::intervention_edit_form( $r, self::interv_url( $f ),
                    '<a class="button button-small" href="' . esc_url( self::interv_url( $f ) ) . '">Cancelar</a>' );
                echo '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
        if ( count( $rows ) >= 500 )
            echo '<p class="cmh-hint">Se muestran las 500 más recientes de este filtro.</p>';
        echo '</div>';   // cierra .cmh-panel; page_footer cierra el .wrap
        self::page_footer();
    }

    private static function interv_filter_bar( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        $companies = $wpdb->get_results( "SELECT id, name FROM {$t['companies']} ORDER BY name ASC" );

        echo '<div class="cmh-panel"><form method="get" class="cmh-filter-form">'
            . '<input type="hidden" name="page" value="' . esc_attr( CMH_SLUG . '-interventions' ) . '">'
            . '<div class="cmh-form-grid">'
            . '<label>Buscar<input type="search" name="q" value="' . esc_attr( $f['q'] ) . '" placeholder="Código o técnico"></label>'
            . '<label>Tipo<select name="type"><option value="">— Todos —</option>';
        foreach ( CMH_Taxonomy::mtype_labels() as $k => $v )
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $f['type'], $k, false ) . '>' . esc_html( $v ) . '</option>';
        echo '</select></label>'
            . '<label>Estado de pago<select name="state"><option value="">— Todos —</option>';
        foreach ( CMH_Taxonomy::pstate_labels() as $k => $v )
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $f['state'], $k, false ) . '>' . esc_html( $v ) . '</option>';
        echo '</select></label>'
            . '<label>Cobro<select name="pay">'
            . '<option value="">— Todo —</option>'
            . '<option value="pending" ' . selected( $f['pay'], 'pending', false ) . '>Con saldo por cobrar</option>'
            . '<option value="paid" ' . selected( $f['pay'], 'paid', false ) . '>Cobradas</option>'
            . ( CMH_Taxonomy::quote_pstates()
                ? '<option value="quote" ' . selected( $f['pay'], 'quote', false ) . '>En trámite (cotizadas)</option>'
                : '' )
            . '<option value="void" ' . selected( $f['pay'], 'void', false ) . '>Anuladas</option>'
            . '</select></label>'
            . '<label>Empresa<select name="company_id"><option value="0">— Todas —</option>';
        foreach ( $companies as $c )
            echo '<option value="' . intval( $c->id ) . '" ' . selected( $f['company_id'], $c->id, false ) . '>' . esc_html( $c->name ) . '</option>';
        echo '</select></label>'
            . '</div>';

        if ( $f['affects'] ) echo '<input type="hidden" name="affects" value="1">';
        if ( $f['machine_id'] ) echo '<input type="hidden" name="machine_id" value="' . intval( $f['machine_id'] ) . '">';

        echo '<div class="cmh-form-actions">'
            . '<button class="button button-primary">Aplicar</button>'
            . '<a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-interventions' ) ) . '">Limpiar</a>'
            . '</div></form></div>';
    }

    /**
     * Modificaciones registradas de varias intervenciones, en UNA sola consulta.
     * Devuelve [ intervention_id => [ filas ] ], de la más antigua a la más nueva.
     */
    public static function intervention_changes( $ids ) {
        global $wpdb; $t = CMH_Core::tables();
        $ids = array_filter( array_map( 'intval', (array) $ids ) );
        if ( ! $ids ) return [];

        $rows = $wpdb->get_results(
            "SELECT intervention_id, created_at, message FROM {$t['logs']}
             WHERE level='edit' AND intervention_id IN (" . implode( ',', $ids ) . ")
             ORDER BY id ASC" );

        $out = [];
        foreach ( $rows as $r ) $out[ (int) $r->intervention_id ][] = $r;
        return $out;
    }

    /**
     * Formulario de edición de una intervención (v2.7).
     *
     * Vive en un solo sitio a propósito: lo usan el timeline de la máquina y la
     * pantalla de Intervenciones, y dos copias del mismo formulario acaban
     * separándose —una gana un campo, la otra no— sin que nadie lo note.
     *
     * @param object $r           Fila de la intervención.
     * @param string $redirect    A dónde volver tras guardar. Vacío = la máquina.
     * @param string $cancel_html Botón o enlace de cancelar, propio de cada sitio.
     */
    public static function intervention_edit_form( $r, $redirect = '', $cancel_html = '' ) {
        self::form_start( 'cm_edit_intervention' );
        echo '<input type="hidden" name="intervention_id" value="' . intval( $r->id ) . '">';
        if ( $redirect ) echo '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect ) . '">';

        echo '<div class="cmh-form-grid">'
            . '<label>Fecha<input type="date" name="intervention_date" value="' . esc_attr( $r->intervention_date ) . '"></label>'
            . '<label>Tipo<select name="maintenance_type">';
        foreach ( CMH_Taxonomy::mtype_labels() as $k => $v )
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r->maintenance_type, $k, false ) . '>' . esc_html( $v ) . '</option>';
        echo '</select></label>'
            . '<label>Técnico<input name="technician" value="' . esc_attr( $r->technician ) . '"></label>'
            . '<label>Horas parada' . ( $r->worked_hours > 0 ? ' <small style="color:#646970">(H. trabajadas: ' . esc_html( $r->worked_hours ) . ' h)</small>' : '' )
            . '<input type="number" step="0.01" name="downtime_hours" value="' . esc_attr( $r->downtime_hours ) . '" min="0" placeholder="' . esc_attr( $r->worked_hours > 0 ? $r->worked_hours : '0' ) . '"></label>'
            . '<label>Costo<input type="number" step="100" name="cost" value="' . esc_attr( $r->cost ) . '" min="0"></label>'
            . '<label>Estado de pago<select name="payment_status">';
        foreach ( self::payment_statuses() as $k => $v )
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r->payment_status, $k, false ) . '>' . esc_html( $v ) . '</option>';
        echo '</select></label>'
            . '<label>Monto abonado<input type="number" step="100" name="paid_amount" value="' . esc_attr( $r->paid_amount ) . '" min="0"></label>'
            . '</div>';

        // v2.7 — Los sistemas también se editan: hasta ahora había que corregirlos
        // desde el formulario original o quedarse con lo que llegó.
        $marcados = CMH_Taxonomy::systems_from_string( $r->failure_system ?? '' );
        echo '<label>Sistema / falla <span class="cmh-optional">(puedes marcar varios)</span><span class="cmh-checklist">';
        foreach ( self::failure_systems() as $k => $v )
            echo '<label class="cmh-inline-check"><input type="checkbox" name="failure_system[]" value="' . esc_attr( $k ) . '" '
                . checked( in_array( $k, $marcados, true ), true, false ) . '> ' . esc_html( $v ) . '</label>';
        echo '</span></label>';

        echo '<label class="cmh-inline-check" style="margin-top:8px"><input type="checkbox" name="affects_availability" value="1" '
            . checked( $r->affects_availability, 1, false ) . '> Afecta disponibilidad</label>'
            . '<label style="display:block;margin-top:8px">Observaciones<textarea name="observations">' . esc_textarea( (string) $r->observations ) . '</textarea></label>'
            . '<div class="cmh-form-actions">'
            . '<button class="button button-primary button-small">Guardar</button>'
            . $cancel_html
            . '</div></form>';
    }

    public static function machine_interventions( $machine_id, $limit = 150 ) {
        global $wpdb; $t = CMH_Core::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, MAX(f.file_url) AS file_url, COUNT(f.id) AS file_count
             FROM {$t['interventions']} i
             LEFT JOIN {$t['files']} f ON f.intervention_id=i.id
             WHERE i.machine_id=%d GROUP BY i.id
             ORDER BY i.intervention_date DESC, i.id DESC LIMIT %d",
            $machine_id, (int) $limit
        ) );
    }

    /**
     * Vista de tabla: una línea por intervención, sin tarjetas ni repeticiones.
     * Es la vista por defecto; el timeline queda al lado como registro detallado.
     */
    public static function intervention_table( $machine_id ) {
        $rows = self::machine_interventions( $machine_id );
        if ( ! $rows ) {
            self::empty_state( 'dashicons-calendar-alt', 'Sin intervenciones', 'Esta máquina todavía no tiene ninguna registrada.',
                [ 'label' => 'Registrar la primera intervención', 'modal' => 'cmh-box-intervencion', 'title' => 'Registrar intervención' ] );
            return;
        }

        echo '<div class="cmh-table-scroll"><table class="widefat cmh cmh-interv-table"><thead><tr>'
            . '<th>Fecha</th><th>Tipo</th><th>Técnico</th>'
            . '<th class="cmh-num">Horóm.</th><th class="cmh-num">Parada</th>'
            . '<th class="cmh-num">Costo</th><th>Pago</th><th>PDF</th>'
            . '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $cost = (float) $r->cost;
            echo '<tr>'
                . '<td class="cmh-nowrap">' . esc_html( $r->intervention_date ) . '</td>'
                . '<td>' . self::mtype_badge( $r->maintenance_type ?: $r->form_type )
                . ( $r->affects_availability ? ' <span class="cmh-badge cmh-badge-averia cmh-badge-xs">Disp.</span>' : '' ) . '</td>'
                . '<td>' . esc_html( $r->technician ?: '—' ) . '</td>'
                . '<td class="cmh-num">' . esc_html( 0 + $r->hourmeter ) . '</td>'
                . '<td class="cmh-num">' . esc_html( 0 + $r->downtime_hours ) . ' h</td>'
                . '<td class="cmh-num">' . ( $cost > 0 ? '$' . number_format( $cost, 0, ',', '.' ) : '—' ) . '</td>'
                . '<td>' . ( self::payment_badge( $r->payment_status, $r->cost, $r->paid_amount ) ?: '—' ) . '</td>'
                . '<td>' . ( $r->file_url
                    ? '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( $r->file_url ) . '">Ver</a>'
                    : '<span class="cmh-muted">—</span>' ) . '</td>'
                . '</tr>';

            // Lo largo (repuestos, servicios, observaciones) va en una segunda línea
            // discreta, para que la tabla no se deforme pero no se pierda el detalle.
            $notes = array_filter( [
                $r->parts        ? 'Repuestos: ' . $r->parts        : '',
                $r->services     ? 'Servicios: ' . $r->services     : '',
                $r->observations ? 'Obs.: ' . $r->observations      : '',
            ] );
            if ( $notes ) {
                echo '<tr class="cmh-subrow"><td colspan="8">' . esc_html( wp_trim_words( implode( ' · ', $notes ), 40 ) ) . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';

        if ( count( $rows ) >= 150 )
            echo '<p class="cmh-hint">Se muestran las 150 más recientes.</p>';
    }

    public static function intervention_cards( $machine_id ) {
        // v2.3 — Misma fuente que la tabla, así las dos vistas no pueden diferir.
        $rows = self::machine_interventions( $machine_id );
        if ( ! $rows ) {
            self::empty_state( 'dashicons-calendar-alt', 'Sin intervenciones', 'Esta máquina todavía no tiene ninguna registrada.',
                [ 'label' => 'Registrar la primera intervención', 'modal' => 'cmh-box-intervencion', 'title' => 'Registrar intervención' ] );
            return;
        }


        // v2.3 — Los filtros salen de la taxonomía: un tipo nuevo aparece aquí solo.
        echo '<div class="cmh-filter-bar">'
            . '<span class="cmh-filter-label">Filtrar:</span>'
            . '<button type="button" class="button button-small cmh-tl-filter active" data-filter="">Todas</button>';
        foreach ( CMH_Taxonomy::mtype_labels() as $mk => $ml )
            echo '<button type="button" class="button button-small cmh-tl-filter" data-filter="' . esc_attr( $mk ) . '">' . esc_html( $ml ) . '</button>';
        echo '</div>';
        // v2.7 — Las modificaciones de todas las tarjetas, en una sola consulta:
        // pedirlas dentro del bucle serían 150 consultas en una máquina cargada.
        $modificaciones = self::intervention_changes( wp_list_pluck( $rows, 'id' ) );

        echo '<div class="cmh-timeline">';
        // v2.3 — El color del punto y del borde sale de la taxonomía, así que un
        // tipo nuevo no queda gris ni indistinguible del resto.
        foreach ( $rows as $r ) {
            $mt    = strtolower( $r->maintenance_type ?: '' );
            $tone  = CMH_Taxonomy::COLORS[ CMH_Taxonomy::mtypes()[ $mt ]['color'] ?? 'gray' ] ?? CMH_Taxonomy::COLORS['gray'];
            $dstyle = 'background:' . $tone['fg'] . ';box-shadow:0 0 0 4px ' . $tone['bg'];
            $cstyle = 'border-left:3px solid ' . $tone['fg'];
            echo '<div class="cmh-timeline-item" data-mtype="' . esc_attr( $mt ) . '">'
                . '<div class="cmh-dot" style="' . $dstyle . '"></div>'
                . '<div class="cmh-timeline-card" style="' . $cstyle . '">'
                . '<div class="cmh-timeline-head">'
                . '<strong>' . self::mtype_badge( $r->maintenance_type ?: $r->form_type )
                . ( $r->affects_availability ? ' <span class="cmh-badge cmh-badge-averia">Descuenta disponibilidad</span>' : '' )
                . ( self::payment_badge( $r->payment_status, $r->cost, $r->paid_amount ) ? ' ' . self::payment_badge( $r->payment_status, $r->cost, $r->paid_amount ) : '' )
                . '</strong>'
                . '<time>' . esc_html( $r->intervention_date ) . '</time></div>'
                . '<div class="cmh-meta">'
                . '<span>Técnico: ' . esc_html( $r->technician ?: '—' ) . '</span>'
                . '<span>H: ' . esc_html( $r->hourmeter ) . '</span>'
                . '<span>Parada: ' . esc_html( $r->downtime_hours ) . ' h</span>'
                . ( (float) $r->cost > 0 ? '<span>Costo: $' . number_format( (float) $r->cost, 0, ',', '.' ) . '</span>' : '' )
                . ( $r->failure_system ? '<span>' . esc_html( CMH_Taxonomy::systems_label( $r->failure_system ) ) . '</span>' : '' )
                . '</div>';
            if ( $r->services )     echo '<p><strong>Servicios:</strong> '     . esc_html( wp_trim_words( $r->services,     32 ) ) . '</p>';
            if ( $r->observations ) echo '<p><strong>Observaciones:</strong> ' . esc_html( wp_trim_words( $r->observations, 32 ) ) . '</p>';

            // v2.7 — Lo que se editó a mano, con quién y cuándo: una hoja de vida
            // que se puede corregir sin dejar rastro no sirve como historial.
            if ( ! empty( $modificaciones[ (int) $r->id ] ) ) {
                echo '<div class="cmh-changes"><strong>Modificaciones</strong><ul>';
                foreach ( $modificaciones[ (int) $r->id ] as $m )
                    echo '<li><time>' . esc_html( mysql2date( 'd/m/Y H:i', $m->created_at ) ) . '</time> '
                        . esc_html( $m->message ) . '</li>';
                echo '</ul></div>';
            }

            echo '<div class="cmh-card-actions">';
            if ( $r->file_url ) {
                echo '<a class="button button-small" target="_blank" href="' . esc_url( $r->file_url ) . '">Ver PDF</a>';
            } else {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
                    . '<input type="hidden" name="action" value="cm_find_pdf">'
                    . '<input type="hidden" name="intervention_id" value="' . intval( $r->id ) . '">'
                    . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                    . '<button type="submit" class="button button-small" title="Busca el PDF generado por E2PDF y lo asocia a esta intervención">Buscar PDF</button>'
                    . '</form>';
            }
            echo '<button type="button" class="button button-small cmh-btn-toggle-edit" data-target="cmh-edit-' . intval( $r->id ) . '">Editar</button>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'¿Eliminar esta intervención? La acción es irreversible.\')">'
                . '<input type="hidden" name="action" value="cm_delete_intervention">'
                . '<input type="hidden" name="intervention_id" value="' . intval( $r->id ) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                . '<button type="submit" class="button button-small" style="color:#d63638;border-color:#d63638">Eliminar</button>'
                . '</form>';
            echo '<span class="cmh-id-hint">ID #' . intval( $r->id ) . '</span></div>';

            // ── Formulario inline de edición ──────────────────────────────────
            echo '<div id="cmh-edit-' . intval( $r->id ) . '" class="cmh-edit-form" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid rgba(0,0,0,.08)">';
            self::intervention_edit_form( $r, '',
                '<button type="button" class="button button-small cmh-btn-toggle-edit" data-target="cmh-edit-'
                . intval( $r->id ) . '">Cancelar</button>' );
            echo '</div>';

            echo '</div></div>'; // close .cmh-timeline-card, .cmh-timeline-item
        }
        echo '</div>';
    }

    public static function availability_table( $machine_id ) {
        $breakdown = CMH_Metrics::monthly_breakdown( $machine_id, 13 );
        if ( empty( $breakdown ) ) {
            self::empty_state( 'dashicons-chart-area', 'Sin datos', 'La disponibilidad mensual se calcula a partir de las intervenciones de la máquina.',
                [ 'label' => 'Registrar una intervención', 'modal' => 'cmh-box-intervencion', 'title' => 'Registrar intervención' ] );
            return;
        }

        echo '<table class="widefat cmh cmh-avail-table"><thead><tr>'
            . '<th>Mes</th><th>H. programadas</th><th>H. parada averías</th>'
            . '<th>H. mantenimiento</th><th>H. operación real</th>'
            . '<th>Disponibilidad</th><th>Averías</th><th>MTTR</th>'
            . '</tr></thead><tbody>';
        foreach ( $breakdown as $row ) {
            $a   = $row['availability'];
            $cls = $a >= 90 ? 'cmh-avail-ok' : ( $a >= 70 ? 'cmh-avail-warn' : 'cmh-avail-danger' );
            echo '<tr>'
                . '<td><strong>' . esc_html( $row['label'] ) . '</strong></td>'
                . '<td>' . esc_html( number_format( $row['scheduled'],            2, ',', '.' ) ) . ' h</td>'
                . '<td>' . esc_html( number_format( $row['downtime_averia'],      2, ',', '.' ) ) . ' h</td>'
                . '<td>' . esc_html( number_format( $row['downtime_maintenance'], 2, ',', '.' ) ) . ' h</td>'
                . '<td>' . esc_html( number_format( $row['real_operation'],       2, ',', '.' ) ) . ' h</td>'
                . '<td><span class="cmh-avail-badge ' . $cls . '">' . esc_html( CMH_Metrics::fmt_pct( $a ) ) . '</span></td>'
                . '<td>' . intval( $row['averia_count'] ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $row['mttr'] ) ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>'
            . '<p style="font-size:12px;color:#646970;margin-top:10px">Solo las <strong>averías</strong> descuentan disponibilidad. El mantenimiento no impacta este indicador.</p>';
    }

    /**
     * Taxonomía estándar de sistemas/fallas (viene de la plantilla Excel del cliente).
     * Fuente única: la usan el formulario de intervención y los reportes.
     */
    /** v2.3.1 — Delegado en la taxonomía configurable. */
    public static function failure_systems() {
        return CMH_Taxonomy::system_labels();
    }

    /**
     * Formulario de intervención.
     *
     * v2.4 — Se abre en ventana, no en la columna estrecha de la derecha, así que
     * los campos se reparten en rejilla por secciones en vez de apilarse en una
     * sola columna larguísima. Los nombres, ids y clases son los mismos: lo que
     * cambia es cómo se acomodan, no qué envían.
     */
    public static function intervention_form( $machine_id, $last_hourmeter = 0, $current_status = 'activa' ) {
        $systems = self::failure_systems();

        self::form_start( 'cm_save_intervention' );
        echo '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ) ) . '">';

        // ── Lo esencial ──────────────────────────────────────────────────
        echo '<fieldset class="cmh-fieldset"><legend>Datos de la intervención</legend>'
            . '<div class="cmh-form-grid">'
            . '<label>Fecha <em>*</em><input type="date" name="intervention_date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></label>'
            . '<label>Tipo <em>*</em><select name="maintenance_type" id="cmh-mtype">';
        foreach ( CMH_Taxonomy::mtype_labels() as $mk => $ml )
            echo '<option value="' . esc_attr( $mk ) . '" data-affects="' . ( CMH_Taxonomy::mtype_affects( $mk ) ? '1' : '0' ) . '">' . esc_html( $ml ) . '</option>';
        echo '</select></label>'
            . '<label>Técnico<input name="technician"></label>'
            . '<label>Horómetro<input type="number" step="0.01" name="hourmeter" min="0" id="cmh-hourmeter-input" data-last-hourmeter="' . esc_attr( $last_hourmeter ) . '"></label>'
            . '</div>'
            . '<div id="cmh-hourmeter-warn" class="cmh-field-warning" style="display:none"></div>'
            . '</fieldset>';

        // ── Falla y parada (solo cuando el tipo lo pide) ──────────────────
        echo '<fieldset class="cmh-fieldset" id="cmh-downtime-fields"><legend>Falla y parada</legend>'
            . '<div class="cmh-form-grid">'
            // v2.6 — Varios sistemas: una avería puede tocar más de uno.
            . '<label>Sistema / falla <span class="cmh-optional">(puedes marcar varios)</span>'
            . '<span class="cmh-checklist">';
        foreach ( $systems as $k => $v )
            echo '<label class="cmh-inline-check"><input type="checkbox" name="failure_system[]" value="' . esc_attr( $k ) . '"> ' . esc_html( $v ) . '</label>';
        echo '</span></label>'
            . '<label>Horas de parada <span class="cmh-optional">(averías)</span>'
            . '<input type="number" step="0.01" name="downtime_hours" value="0" min="0"></label>'
            . '</div></fieldset>';

        // ── Tiempos y costo ──────────────────────────────────────────────
        echo '<fieldset class="cmh-fieldset"><legend>Tiempos y costo</legend>'
            . '<div class="cmh-form-grid">'
            . '<label>Horas trabajadas<input type="number" step="0.01" name="worked_hours" value="0" min="0"></label>'
            . '<label>Costo<input type="number" step="100" name="cost" id="cmh-cost-input" value="0" min="0"></label>'
            . '</div>'
            . '<div id="cmh-av-row" style="margin-top:8px">'
            . '<label class="cmh-inline-check"><input type="checkbox" name="affects_availability" value="1"> Afecta disponibilidad'
            . ' <span class="cmh-auto-note" style="display:none;color:#2271b1;font-size:11px">(automático según el tipo)</span></label>'
            . '</div></fieldset>';

        // ── Cobro ────────────────────────────────────────────────────────
        echo '<fieldset class="cmh-fieldset cmh-payment-section"><legend>Cobro</legend>'
            . '<div class="cmh-form-grid">'
            . '<label>Estado de pago<select name="payment_status" id="cmh-payment-status">';
        foreach ( self::payment_statuses() as $k => $v ) echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
        echo '</select></label>'
            . '<label>Monto abonado<input type="number" step="100" name="paid_amount" id="cmh-paid-input" value="0" min="0"></label>'
            . '</div>'
            . '<p id="cmh-saldo-hint" class="cmh-hint" style="margin:8px 0 0">Saldo = costo − abonado.</p>'
            . '</fieldset>';

        // ── Estado de la máquina y próxima fecha ─────────────────────────
        echo '<fieldset class="cmh-fieldset" id="cmh-status-row"><legend>Después de esta intervención</legend>'
            . '<div class="cmh-form-grid">'
            . '<label>Estado de la máquina <span class="cmh-optional">(ahora: ' . wp_strip_all_tags( self::status_badge( $current_status ) ) . ')</span>'
            . '<select name="new_machine_status"><option value="">— Mantener el actual —</option>'
            . '<option value="activa">Activa</option>'
            . '<option value="mantenimiento">En mantenimiento</option>'
            . '<option value="inactiva">Inactiva</option>'
            . '<option value="fuera_servicio">Fuera de servicio</option>'
            . '</select></label>'
            . '<label>Próximo mantenimiento <span class="cmh-optional">(opcional)</span>'
            . '<input type="date" name="next_maintenance_date" min="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></label>'
            . '</div></fieldset>';

        // ── Detalle libre ────────────────────────────────────────────────
        echo '<fieldset class="cmh-fieldset"><legend>Detalle del servicio</legend>'
            . '<label>Repuestos / insumos<textarea name="parts" rows="2"></textarea></label>'
            . '<label>Servicios prestados<textarea name="services" rows="2"></textarea></label>'
            . '<label>Observaciones<textarea name="observations" rows="2"></textarea></label>'
            . '</fieldset>';

        echo '<div class="cmh-form-actions">'
            . '<button class="button button-primary">Guardar intervención</button>'
            . '<span class="cmh-dirty-flag">Tienes cambios sin guardar</span>'
            . '</div></form>';
    }

    public static function files_table( $machine_id = 0 ) {
        global $wpdb; $t = CMH_Core::tables();
        $where = $machine_id ? $wpdb->prepare( 'WHERE machine_id=%d', $machine_id ) : '';
        $rows  = $wpdb->get_results( "SELECT * FROM {$t['files']} $where ORDER BY id DESC LIMIT 100" );
        if ( ! $rows ) {
            self::empty_state( 'dashicons-media-document', 'Sin archivos', 'Aquí se guardan los PDF que genera cada formato y lo que anexes a mano.',
                $machine_id ? [ 'label' => 'Anexar el primer archivo', 'modal' => 'cmh-box-archivo', 'title' => 'Anexar PDF o archivo' ] : [] );
            return;
        }
        echo '<table class="widefat cmh"><thead><tr><th>Archivo</th><th>Intervención</th><th>Fecha</th></tr></thead><tbody>';
        foreach ( $rows as $r )
            echo '<tr><td><a target="_blank" href="' . esc_url( $r->file_url ) . '">' . esc_html( $r->file_name ) . '</a></td>'
                . '<td>' . ( $r->intervention_id ? '#' . esc_html( $r->intervention_id ) : '—' ) . '</td>'
                . '<td>' . esc_html( $r->created_at ) . '</td></tr>';
        echo '</tbody></table>';
    }

    public static function upload_form( $machine_id ) {
        self::form_start( 'cm_upload_file', true );
        echo '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ) ) . '">'
            . '<label>ID intervención <span class="cmh-optional">(opcional)</span></label><input type="number" name="intervention_id" min="1">'
            . '<label>Archivo PDF o imagen <em>*</em></label><input type="file" name="format_file" accept="application/pdf,image/*" required>'
            . '<button class="button button-primary" style="margin-top:12px">Subir archivo</button></form>';
    }

    // =========================================================================
    // Integración
    // =========================================================================

    public static function page_integration() {
        global $wpdb; $t = CMH_Core::tables();
        self::page_header( 'Integración Forminator / E2PDF', [ [ 'label' => 'Integración' ] ] );

        echo '<div class="cmh-panel"><div class="cmh-toolbar"><h2>Formularios conectados</h2>'
            . '<a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-forms' ) ) . '">Administrar formatos</a></div>'
            . '<table class="widefat cmh"><thead><tr><th>Form ID</th><th>Formato</th><th>Campo máquina</th><th>Mantenimiento</th><th>Estado</th></tr></thead><tbody>';
        foreach ( CMH_Forms::all() as $fid => $cfg ) {
            $mfield = $cfg['fields']['machine'] ?? '';
            echo '<tr><td><strong>' . intval( $fid ) . '</strong></td>'
                . '<td>' . esc_html( $cfg['label'] ?: $cfg['form_type'] ) . '</td>'
                . '<td>' . ( $mfield ? '<code>' . esc_html( $mfield ) . '</code>' : '<span style="color:#d63638">sin mapear</span>' ) . '</td>'
                . '<td>' . esc_html( $cfg['maintenance_type'] ) . '</td>'
                . '<td>' . ( $cfg['enabled']
                    ? '<span class="cmh-badge cmh-status-activa">Activo</span>'
                    : '<span class="cmh-badge" style="background:#f0f0f1;color:#3c434a">Inactivo</span>' ) . '</td></tr>';
        }
        echo '</tbody></table><p style="font-size:12px;color:#646970;margin-top:10px">Forminator captura los envíos, crea la intervención y E2PDF asocia el PDF generado. Si el PDF no aparece de inmediato, WP-Cron lo reintenta 90 s después.</p></div>';

        // Las ediciones a mano se guardan en la misma tabla pero NO son eventos de
        // integración: su sitio es la línea de tiempo de la máquina.
        $rows = $wpdb->get_results( "SELECT * FROM {$t['logs']} WHERE level <> 'edit' ORDER BY id DESC LIMIT 100" );
        echo '<div class="cmh-panel"><div class="cmh-toolbar"><h2>Logs de integración</h2>'
            . '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cm_export_csv&type=logs' ), 'cmh_action' ) ) . '">Exportar CSV</a></div>'
            . '<table class="widefat cmh"><thead><tr><th>Fecha</th><th>Nivel</th><th>Form</th><th>Máquina</th><th>Mensaje</th><th></th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $cls = $r->level === 'error' ? 'cmh-log-error' : ( $r->level === 'success' ? 'cmh-log-ok' : '' );

            // v2.0 — Solo se puede reprocesar lo que guardó el contenido del envío,
            // que es justamente lo que se registra cuando algo salió mal.
            $can_retry = $r->payload && in_array( $r->level, [ 'warning', 'error' ], true )
                && $r->form_id && ! $r->intervention_id;

            echo '<tr class="' . $cls . '"><td>' . esc_html( $r->created_at ) . '</td><td>' . esc_html( $r->level ) . '</td>'
                . '<td>' . esc_html( $r->form_id ?: '—' ) . '</td><td>' . esc_html( $r->machine_code ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->message ) . '</td>'
                . '<td>';
            if ( $can_retry ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
                    . '<input type="hidden" name="action" value="cm_reprocess_entry">'
                    . '<input type="hidden" name="log_id" value="' . intval( $r->id ) . '">'
                    . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                    . '<button class="button button-small" title="Vuelve a procesar este envío con el mapeo actual">Reprocesar</button></form>';
            }
            echo '</td></tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="6">' . self::empty_state_inline( 'Sin logs todavía.' ) . '</td></tr>';
        echo '</tbody></table></div>';
        self::page_footer();
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    // v2.7 — El alta acepta los mismos datos de contacto que la edición: crear y
    // tener que volver a entrar solo para escribir un teléfono no tiene sentido.
    // Los campos son opcionales, así que lo que no venga se guarda vacío.
    public static function save_company() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $wpdb->insert( $t['companies'], array_merge( [
            'name' => strtoupper( sanitize_text_field( $_POST['name'] ) ),
            'code' => self::clean_code( $_POST['code'] ),
        ], self::contact_fields_from_post( true ) ) );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies' ), 'Empresa guardada.' );
    }

    public static function save_city() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $cid = intval( $_POST['company_id'] );
        $wpdb->insert( $t['cities'], array_merge( [
            'company_id' => $cid,
            'name'       => strtoupper( sanitize_text_field( $_POST['name'] ) ),
            'code'       => self::clean_code( $_POST['code'] ),
        ], self::contact_fields_from_post( false ) ) );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $cid ] ), 'Ciudad/Sucursal guardada.' );
    }

    public static function save_branch() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $cid = intval( $_POST['city_id'] );
        $wpdb->insert( $t['branches'], [ 'company_id' => intval( $_POST['company_id'] ), 'city_id' => $cid, 'name' => sanitize_text_field( $_POST['name'] ), 'code' => self::clean_code( $_POST['code'] ), 'address' => sanitize_textarea_field( $_POST['address'] ) ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $cid ] ), 'Sucursal guardada.' );
    }

    public static function save_machine() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $company = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['companies']} WHERE id=%d", $_POST['company_id'] ) );
        $city    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['cities']}    WHERE id=%d", $_POST['city_id'] ) );
        if ( ! $company || ! $city ) wp_die( 'Empresa o ciudad no encontrada.' );

        $brand       = strtoupper( sanitize_text_field( $_POST['brand'] ) );
        $brand_code  = self::brand_code( $brand );
        $machine_num = strtoupper( preg_replace( '/[^A-Z0-9]/', '', strtoupper( sanitize_text_field( $_POST['machine_number'] ?? '' ) ) ) );
        if ( ! $machine_num ) {
            self::redirect_to(
                self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => intval( $_POST['city_id'] ) ] ),
                '', 'El N.º de máquina no puede estar vacío.'
            );
        }
        $machine_code = $company->code . ' ' . $city->code . ' ' . $brand_code . ' No. ' . $machine_num;

        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['machines']} WHERE machine_code=%s", $machine_code ) ) ) {
            self::redirect_to(
                self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => intval( $_POST['city_id'] ) ] ),
                '', 'Ya existe una máquina con el código ' . $machine_code . '. Elige otro N.º.'
            );
        }

        $hm = ( isset( $_POST['current_hourmeter'] ) && strlen( trim( $_POST['current_hourmeter'] ) ) )
            ? floatval( $_POST['current_hourmeter'] ) : 0.0;

        $wpdb->insert( $t['machines'], [
            'company_id'  => intval( $_POST['company_id'] ), 'city_id' => intval( $_POST['city_id'] ), 'branch_id' => null,
            'machine_code' => $machine_code, 'brand' => $brand, 'brand_code' => $brand_code,
            'model'   => strtoupper( sanitize_text_field( $_POST['model'] ) ),
            'serial'  => strtoupper( sanitize_text_field( $_POST['serial'] ) ),
            'contact' => sanitize_text_field( $_POST['contact'] ),
            'current_hourmeter'       => $hm,
            'scheduled_hours_monthly' => max( 1, floatval( $_POST['scheduled_hours_monthly'] ) ) ?: 480,
            'status'  => sanitize_text_field( $_POST['status'] ),
            'next_maintenance_date' => sanitize_text_field( $_POST['next_maintenance_date'] ?? '' ) ?: null,
            'maintenance_interval_days' => CMH_Schedule::interval_from_post(),
            'notes'   => sanitize_textarea_field( $_POST['notes'] ),
            'updated_at' => current_time( 'mysql' ),
        ] );

        // v2.2 — Si la máquina nace con fecha de mantenimiento, su tarea también.
        CMH_Schedule::sync_machine_task( (int) $wpdb->insert_id );

        self::redirect_to(
            self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => intval( $_POST['city_id'] ) ] ),
            'Máquina guardada. Código: ' . $machine_code
        );
    }

    public static function save_intervention() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $mtype      = sanitize_text_field( $_POST['maintenance_type'] );
        $manual_av  = isset( $_POST['affects_availability'] ) ? 1 : 0;
        $hourmeter  = floatval( $_POST['hourmeter'] );
        $prev_hm    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT current_hourmeter FROM {$t['machines']} WHERE id=%d", $machine_id ) );

        $hm_warn = '';
        if ( $hourmeter > 0 && $prev_hm > 0 && $hourmeter < $prev_hm )
            $hm_warn = sprintf( 'Horómetro ingresado (%.2f h) es menor al registrado anteriormente (%.2f h).', $hourmeter, $prev_hm );

        list( $pay_status, $pay_paid ) = self::normalize_payment( $_POST['payment_status'] ?? '', $_POST['cost'] ?? 0, $_POST['paid_amount'] ?? 0 );

        $wpdb->insert( $t['interventions'], [
            'machine_id'           => $machine_id, 'forminator_form_id' => null,
            'intervention_date'    => sanitize_text_field( $_POST['intervention_date'] ),
            'form_type'            => sanitize_text_field( $_POST['form_type'] ?? 'manual' ),
            'maintenance_type'     => $mtype, 'technician' => sanitize_text_field( $_POST['technician'] ),
            'hourmeter'            => $hourmeter, 'worked_hours' => floatval( $_POST['worked_hours'] ),
            'downtime_hours'       => floatval( $_POST['downtime_hours'] ), 'cost' => floatval( $_POST['cost'] ),
            'payment_status'       => $pay_status,
            'paid_amount'          => $pay_paid,
            'affects_availability' => CMH_Metrics::auto_affects_availability( $mtype, $manual_av ),
            'failure_system'       => CMH_Taxonomy::systems_to_string( (array) ( $_POST['failure_system'] ?? [] ) ),
            'parts'                => sanitize_textarea_field( $_POST['parts'] ),
            'services'             => sanitize_textarea_field( $_POST['services'] ),
            'observations'         => sanitize_textarea_field( $_POST['observations'] ),
        ] );

        // Actualizar horómetro si es mayor o igual al previo
        if ( $hourmeter > 0 && $hourmeter >= $prev_hm )
            $wpdb->update( $t['machines'], [ 'current_hourmeter' => $hourmeter, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $machine_id ] );

        // V0.8 — Estado automático
        $new_status = sanitize_key( $_POST['new_machine_status'] ?? '' );
        if ( $new_status ) {
            $allowed = [ 'activa', 'mantenimiento', 'inactiva', 'fuera_servicio' ];
            if ( in_array( $new_status, $allowed, true ) )
                $wpdb->update( $t['machines'], [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $machine_id ] );
        }

        $next_maint = sanitize_text_field( $_POST['next_maintenance_date'] ?? '' );
        if ( $next_maint ) {
            $wpdb->update( $t['machines'],
                [ 'next_maintenance_date' => $next_maint, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $machine_id ]
            );
        }

        // V0.11 — Recurrencia: si es preventivo, la máquina tiene intervalo y no se
        // escribió una fecha a mano, se reprograma el próximo mantenimiento solo.
        $msg  = 'Intervención guardada.';
        $auto = CMH_Schedule::recalc_next_maintenance(
            $machine_id, sanitize_text_field( $_POST['intervention_date'] ), $mtype, $next_maint
        );
        if ( $auto ) $msg .= ' Próximo mantenimiento reprogramado para el ' . $auto . '.';

        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), $msg, $hm_warn );
    }

    public static function update_machine() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $new_hm     = floatval( $_POST['current_hourmeter'] );
        $prev_hm    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT current_hourmeter FROM {$t['machines']} WHERE id=%d", $machine_id ) );
        $hm_warn    = ( $new_hm > 0 && $prev_hm > 0 && $new_hm < $prev_hm )
            ? sprintf( 'Horómetro actualizado a %.2f h (anterior: %.2f h). Verifica que sea correcto.', $new_hm, $prev_hm ) : '';
        $brand      = strtoupper( sanitize_text_field( $_POST['brand'] ) );

        $data = [
            'brand'      => $brand,
            'brand_code' => self::brand_code( $brand ),
            'model'      => strtoupper( sanitize_text_field( $_POST['model'] ) ),
            'serial'     => strtoupper( sanitize_text_field( $_POST['serial'] ) ),
            'contact'    => sanitize_text_field( $_POST['contact'] ),
            'current_hourmeter'       => $new_hm,
            'scheduled_hours_monthly' => max( 1, floatval( $_POST['scheduled_hours_monthly'] ) ) ?: 480,
            'status'               => sanitize_text_field( $_POST['status'] ),
            'next_maintenance_date' => sanitize_text_field( $_POST['next_maintenance_date'] ?? '' ) ?: null,
            'maintenance_interval_days' => CMH_Schedule::interval_from_post(),
            'notes'                => sanitize_textarea_field( $_POST['notes'] ),
            'updated_at'           => current_time( 'mysql' ),
        ];

        // Actualizar código si se proporcionó uno diferente y no existe ya
        $new_code = strtoupper( sanitize_text_field( $_POST['machine_code'] ?? '' ) );
        $old_code = $wpdb->get_var( $wpdb->prepare( "SELECT machine_code FROM {$t['machines']} WHERE id=%d", $machine_id ) );
        if ( $new_code && $new_code !== $old_code ) {
            $dupe = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$t['machines']} WHERE machine_code=%s AND id!=%d", $new_code, $machine_id
            ) );
            if ( ! $dupe ) $data['machine_code'] = $new_code;
            else $hm_warn .= ( $hm_warn ? ' | ' : '' ) . 'Código duplicado — se mantuvo el original.';
        }

        $wpdb->update( $t['machines'], $data, [ 'id' => $machine_id ] );

        // v2.2 — La tarea del mantenimiento programado sigue a la fecha.
        CMH_Schedule::sync_machine_task( $machine_id );

        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Máquina actualizada.', $hm_warn );
    }

    public static function upload_file() {
        self::check();
        if ( empty( $_FILES['format_file']['name'] ) ) wp_die( 'Sin archivo.' );
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $m = $wpdb->get_row( $wpdb->prepare( "SELECT machine_code FROM {$t['machines']} WHERE id=%d", $machine_id ) );
        if ( ! $m ) wp_die( 'Máquina no encontrada.' );

        // v2.8 (seguridad) — El código de máquina se puede editar a mano y hasta
        // ahora entraba TAL CUAL en la ruta: un código con «../» escribía fuera
        // de la carpeta de subidas. Se reduce a un nombre de carpeta seguro.
        $carpeta = self::safe_folder( $m->machine_code ) ?: ( 'maquina-' . $machine_id );

        $dir_filter = static function ( $dirs ) use ( $carpeta ) {
            $dirs['subdir'] = '/cm-machine-history/' . $carpeta;
            $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        };
        add_filter( 'upload_dir', $dir_filter );
        $file = wp_handle_upload( $_FILES['format_file'], [ 'test_form' => false ] );
        remove_filter( 'upload_dir', $dir_filter );
        if ( isset( $file['error'] ) ) wp_die( esc_html( $file['error'] ) );

        $wpdb->insert( $t['files'], [
            'machine_id' => $machine_id, 'intervention_id' => intval( $_POST['intervention_id'] ) ?: null,
            'file_url' => esc_url_raw( set_url_scheme( $file['url'] ) ), 'file_path' => $file['file'],
            'file_name' => basename( $file['file'] ), 'file_type' => $file['type'],
            'uploaded_by' => get_current_user_id(),
        ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Archivo anexado.' );
    }

    // =========================================================================
    // V0.8 — Exportar CSV
    // =========================================================================

    public static function export_csv() {
        if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'cmh_action' );

        $type = sanitize_key( $_GET['type'] ?? 'machines' );

        switch ( $type ) {
            case 'interventions': self::export_interventions_csv(); break;
            case 'availability':  self::export_availability_csv();  break;
            case 'logs':          self::export_logs_csv();           break;
            default:              self::export_machines_csv();       break;
        }
    }

    public static function csv_headers( $filename ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Pragma: no-cache' );
        echo "\xEF\xBB\xBF"; // BOM para Excel
    }

    public static function csv_row( $row ) {
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $row, ';' );
        fclose( $out );
    }

    private static function export_machines_csv() {
        global $wpdb; $t = CMH_Core::tables();
        $company_id = intval( $_GET['company_id'] ?? 0 );
        $city_id    = intval( $_GET['city_id']    ?? 0 );
        $branch_id  = intval( $_GET['branch_id']  ?? 0 );
        $status     = sanitize_key( $_GET['status'] ?? '' );
        $q          = sanitize_text_field( $_GET['q'] ?? '' );

        $where = []; $params = [];
        if ( $company_id ) { $where[] = 'm.company_id=%d'; $params[] = $company_id; }
        if ( $city_id    ) { $where[] = 'm.city_id=%d';    $params[] = $city_id; }
        if ( $branch_id  ) { $where[] = 'm.branch_id=%d';  $params[] = $branch_id; }
        if ( $status     ) { $where[] = 'm.status=%s';     $params[] = $status; }
        if ( $q ) {
            $like = '%' . $wpdb->esc_like( $q ) . '%';
            $where[] = '(m.machine_code LIKE %s OR m.serial LIKE %s OR m.brand LIKE %s)';
            array_push( $params, $like, $like, $like );
        }
        $w = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql = "SELECT m.machine_code, m.brand, m.model, m.serial, m.contact, c.name empresa, ci.name ciudad, COALESCE(b.name,'') sucursal, m.status, m.current_hourmeter, m.scheduled_hours_monthly, COALESCE(m.next_maintenance_date,'') proximo_mantenimiento, COALESCE(m.maintenance_interval_days,0) recurrencia_dias, (SELECT COUNT(*) FROM {$t['interventions']} i WHERE i.machine_id=m.id) intervenciones, (SELECT MAX(i.intervention_date) FROM {$t['interventions']} i WHERE i.machine_id=m.id) ultima_intervencion, m.notes FROM {$t['machines']} m JOIN {$t['companies']} c ON c.id=m.company_id JOIN {$t['cities']} ci ON ci.id=m.city_id LEFT JOIN {$t['branches']} b ON b.id=m.branch_id $w ORDER BY m.machine_code";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

        self::csv_headers( 'maquinas-' . date( 'Y-m-d' ) . '.csv' );
        self::csv_row( [ 'Código', 'Marca', 'Modelo', 'Serial', 'Contacto', 'Empresa', 'Ciudad', 'Sucursal', 'Estado', 'Horómetro', 'H.Prog/Mes', 'Próximo mantenimiento', 'Recurrencia (días)', 'Intervenciones', 'Última intervención', 'Notas' ] );
        foreach ( $rows as $r ) self::csv_row( array_values( $r ) );
        exit;
    }

    private static function export_interventions_csv() {
        global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_GET['machine_id'] ?? 0 );
        $where  = $machine_id ? $wpdb->prepare( 'WHERE i.machine_id=%d', $machine_id ) : '';
        $rows   = $wpdb->get_results(
            "SELECT i.intervention_date, m.machine_code, i.maintenance_type, i.form_type, i.technician, i.hourmeter, i.worked_hours, i.downtime_hours, i.affects_availability, i.failure_system, i.cost, i.payment_status, i.paid_amount, (i.cost - i.paid_amount) saldo, i.parts, i.services, i.observations FROM {$t['interventions']} i LEFT JOIN {$t['machines']} m ON m.id=i.machine_id $where ORDER BY i.intervention_date DESC, i.id DESC",
            ARRAY_A
        );
        self::csv_headers( 'intervenciones-' . date( 'Y-m-d' ) . '.csv' );
        self::csv_row( [ 'Fecha', 'Máquina', 'Tipo', 'Formato', 'Técnico', 'Horómetro', 'H.Trabajadas', 'H.Parada', 'Afecta Disp.', 'Sistema/Falla', 'Costo', 'Estado pago', 'Abonado', 'Saldo', 'Repuestos', 'Servicios', 'Observaciones' ] );
        foreach ( $rows as $r ) {
            // Los sistemas salen con su nombre y no con la clave interna, que es
            // lo que se abre en Excel.
            $r['failure_system'] = CMH_Taxonomy::systems_label( $r['failure_system'] );
            self::csv_row( array_values( $r ) );
        }
        exit;
    }

    private static function export_availability_csv() {
        $machine_id = intval( $_GET['machine_id'] ?? 0 );
        self::csv_headers( 'disponibilidad-' . date( 'Y-m-d' ) . '.csv' );
        self::csv_row( [ 'Mes', 'H. Programadas', 'H. Parada Averías', 'H. Mantenimiento', 'H. Operación Real', 'Disponibilidad %', 'Averías', 'MTTR (h)' ] );
        if ( $machine_id ) {
            foreach ( CMH_Metrics::monthly_breakdown( $machine_id, 24 ) as $r )
                self::csv_row( [ $r['label'], $r['scheduled'], $r['downtime_averia'], $r['downtime_maintenance'], $r['real_operation'], number_format( $r['availability'], 2, '.', '' ), $r['averia_count'], $r['mttr'] ?? '' ] );
        }
        exit;
    }

    private static function export_logs_csv() {
        global $wpdb; $t = CMH_Core::tables();
        $rows = $wpdb->get_results( "SELECT created_at, level, form_id, machine_code, intervention_id, message FROM {$t['logs']} ORDER BY id DESC LIMIT 1000", ARRAY_A );
        self::csv_headers( 'logs-integracion-' . date( 'Y-m-d' ) . '.csv' );
        self::csv_row( [ 'Fecha', 'Nivel', 'Form ID', 'Máquina', 'Intervención ID', 'Mensaje' ] );
        foreach ( $rows as $r ) self::csv_row( array_values( $r ) );
        exit;
    }

    // =========================================================================
    // Editar intervención
    // =========================================================================

    public static function edit_intervention() {
        self::check();
        global $wpdb; $t = CMH_Core::tables();
        $id = intval( $_POST['intervention_id'] );

        // Se lee ANTES de tocar nada: sin la fila previa no hay con qué comparar
        // y el registro del cambio quedaría en «se editó algo».
        $antes = $wpdb->get_row( $wpdb->prepare(
            "SELECT i.*, m.machine_code FROM {$t['interventions']} i
             LEFT JOIN {$t['machines']} m ON m.id=i.machine_id WHERE i.id=%d", $id ) );
        if ( ! $antes ) wp_die( 'Intervención no encontrada.' );
        $machine_id = (int) $antes->machine_id;

        $mtype     = sanitize_text_field( $_POST['maintenance_type'] );
        $manual_av = isset( $_POST['affects_availability'] ) ? 1 : 0;

        list( $pay_status, $pay_paid ) = self::normalize_payment( $_POST['payment_status'] ?? '', $_POST['cost'] ?? 0, $_POST['paid_amount'] ?? 0 );

        $despues = [
            'intervention_date'    => sanitize_text_field( $_POST['intervention_date'] ),
            'maintenance_type'     => $mtype,
            'technician'           => sanitize_text_field( $_POST['technician'] ),
            'downtime_hours'       => floatval( $_POST['downtime_hours'] ),
            'cost'                 => floatval( $_POST['cost'] ),
            'payment_status'       => $pay_status,
            'paid_amount'          => $pay_paid,
            'affects_availability' => CMH_Metrics::auto_affects_availability( $mtype, $manual_av ),
            'failure_system'       => CMH_Taxonomy::systems_to_string( (array) ( $_POST['failure_system'] ?? [] ) ),
            'observations'         => sanitize_textarea_field( $_POST['observations'] ),
        ];

        $wpdb->update( $t['interventions'], $despues, [ 'id' => $id ] );

        // v2.7 — El cambio se anota en la línea de tiempo de la máquina, con quién
        // y qué. Editar el historial sin dejar rastro es justo lo que no debe
        // pasar en una hoja de vida.
        $cambios = self::intervention_diff( $antes, $despues );
        if ( $cambios ) {
            $quien = wp_get_current_user();
            CMH_Core::log( 'edit', 0, (string) $antes->machine_code, $id,
                'Editada por ' . ( $quien && $quien->display_name ? $quien->display_name : 'un administrador' )
                . ': ' . implode( ' · ', $cambios ), null );
        }

        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ),
            $cambios ? 'Intervención actualizada.' : 'No había nada que cambiar.' );
    }

    /**
     * Qué cambió entre la fila anterior y lo que se acaba de guardar, en texto
     * legible. Los números se comparan como números: «450000» y «450000.00» son
     * el mismo costo y no deben salir como una modificación.
     */
    private static function intervention_diff( $antes, $despues ) {
        $etiquetas = [
            'intervention_date'    => 'Fecha',
            'maintenance_type'     => 'Tipo',
            'technician'           => 'Técnico',
            'downtime_hours'       => 'Horas de parada',
            'cost'                 => 'Costo',
            'payment_status'       => 'Estado de pago',
            'paid_amount'          => 'Abonado',
            'affects_availability' => 'Afecta disponibilidad',
            'failure_system'       => 'Sistema / falla',
            'observations'         => 'Observaciones',
        ];
        $numericos = [ 'downtime_hours', 'cost', 'paid_amount', 'affects_availability' ];

        $out = [];
        foreach ( $etiquetas as $campo => $etiqueta ) {
            $a = $antes->$campo ?? '';
            $d = $despues[ $campo ];

            $igual = in_array( $campo, $numericos, true )
                ? ( abs( (float) $a - (float) $d ) < 0.001 )
                : ( (string) $a === (string) $d );
            if ( $igual ) continue;

            // El texto largo no se vuelca entero en el registro: se dice que cambió.
            if ( $campo === 'observations' ) { $out[] = 'Observaciones actualizadas'; continue; }

            $out[] = $etiqueta . ': ' . self::diff_value( $campo, $a ) . ' → ' . self::diff_value( $campo, $d );
        }
        return $out;
    }

    /** Un valor del historial, tal como lo lee una persona. */
    private static function diff_value( $campo, $valor ) {
        if ( $valor === '' || $valor === null ) return '(vacío)';
        switch ( $campo ) {
            case 'maintenance_type':     return CMH_Taxonomy::mtype_label( $valor );
            case 'payment_status':       return CMH_Taxonomy::pstate_label( $valor );
            case 'failure_system':       return CMH_Taxonomy::systems_label( $valor ) ?: '(vacío)';
            case 'affects_availability': return ( (int) $valor === 1 ) ? 'Sí' : 'No';
            case 'cost':
            case 'paid_amount':          return '$' . number_format( (float) $valor, 0, ',', '.' );
            case 'downtime_hours':       return ( 0 + $valor ) . ' h';
        }
        return (string) $valor;
    }

    // =========================================================================
    // Programar mantenimiento (rápido, sin intervención)
    // =========================================================================

    public static function schedule_maintenance() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['machines']} WHERE id=%d", $machine_id ) ) )
            wp_die( 'Máquina no encontrada.' );

        // El intervalo se guarda siempre (también al quitar la fecha): es una propiedad
        // de la máquina, no de la fecha puntual.
        $interval = CMH_Schedule::interval_from_post();

        if ( ! empty( $_POST['clear_date'] ) ) {
            $wpdb->update( $t['machines'], [ 'next_maintenance_date' => null, 'maintenance_interval_days' => $interval, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $machine_id ] );
            // v2.2 — Sin fecha, la tarea automática pendiente ya no aplica.
            CMH_Schedule::sync_machine_task( $machine_id );
            self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Fecha de mantenimiento eliminada.' );
        }

        $date = sanitize_text_field( $_POST['next_maintenance_date'] ?? '' );
        if ( ! $date ) self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), '', 'Indica una fecha para programar el mantenimiento.' );

        $wpdb->update( $t['machines'], [ 'next_maintenance_date' => $date, 'maintenance_interval_days' => $interval, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $machine_id ] );

        // v2.2 — La tarea del mantenimiento programado sigue a la fecha: se crea
        // si no existía y se mueve si la fecha cambió.
        $synced = CMH_Schedule::sync_machine_task( $machine_id );

        $msg = 'Mantenimiento programado para el ' . $date . '.';
        if ( $interval ) $msg .= ' Se repetirá automáticamente: ' . CMH_Schedule::interval_label( $interval ) . '.';
        if ( $synced === 'created' )    $msg .= ' Se creó la tarea para el técnico principal.';
        elseif ( $synced === 'moved' )  $msg .= ' La tarea existente se movió a esa fecha.';
        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), $msg );
    }
    // =========================================================================
    // Buscar/asociar PDF de E2PDF manualmente para una intervención
    // =========================================================================

    public static function find_pdf_now() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $id = intval( $_POST['intervention_id'] );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT i.machine_id, m.machine_code FROM {$t['interventions']} i JOIN {$t['machines']} m ON m.id=i.machine_id WHERE i.id=%d", $id
        ) );
        if ( ! $row ) wp_die( 'Intervención no encontrada.' );

        CMH_Integration::find_pdf( $id, (int) $row->machine_id, $row->machine_code );

        $has = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['files']} WHERE intervention_id=%d LIMIT 1", $id ) );
        $back = self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => (int) $row->machine_id ] );
        if ( $has ) self::redirect_to( $back, 'PDF asociado a la intervención #' . $id . '.' );
        else        self::redirect_to( $back, '', 'No se encontró un PDF de E2PDF para asociar. Puedes subirlo manualmente con "Anexar PDF / archivo".' );
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    /**
     * Consulta de máquina para el prellenado de formularios.
     *
     * v2.8 (seguridad) — Antes devolvía la fila COMPLETA de cualquier máquina a
     * cualquier usuario con `read`: un cliente de una empresa podía leer las
     * máquinas de otra escribiendo su código, saltándose el aislamiento que sí
     * respeta el portal. Ahora los datos internos —notas, horómetro, fechas—
     * solo salen si el usuario tiene acceso a ESA máquina; el resto recibe lo
     * mismo que un visitante, que es lo justo para rellenar el formato.
     */
    public static function ajax_get_machine() {
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        global $wpdb; $t = CMH_Core::tables();

        $code = sanitize_text_field( $_GET['code'] ?? '' );
        if ( $code === '' ) wp_send_json_error( [ 'message' => 'Código requerido.' ] );

        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             WHERE m.machine_code=%s OR m.serial=%s",
            $code, $code
        ) );
        if ( ! $m ) wp_send_json_error( [ 'message' => 'Máquina no encontrada.' ] );

        wp_send_json_success( self::can_see_machine( (int) $m->id ) ? $m : self::machine_prefill_payload( $m ) );
    }

    /**
     * ¿El usuario actual tiene algo que ver con esta máquina?
     *
     * Es la misma regla que aplican el panel del técnico y el portal del
     * cliente; aquí se reúne para que la consulta AJAX no pueda quedarse atrás.
     */
    private static function can_see_machine( $machine_id ) {
        if ( current_user_can( 'edit_others_posts' ) ) return true;
        if ( class_exists( 'CMH_Tech' ) && current_user_can( 'cmh_tech' )
             && CMH_Tech::can_access_machine( $machine_id ) ) return true;
        if ( class_exists( 'CMH_Client' ) && current_user_can( 'cmh_client' )
             && CMH_Client::can_access_machine( $machine_id ) ) return true;
        return false;
    }

    /** Lo mínimo que el formulario necesita para prellenarse. Nada interno. */
    private static function machine_prefill_payload( $m ) {
        return (object) [
            'machine_code' => $m->machine_code,
            'brand'        => $m->brand,
            'model'        => $m->model,
            'serial'       => $m->serial,
            'contact'      => $m->contact,
            'company_name' => $m->company_name,
            'city_name'    => $m->city_name,
        ];
    }

    /**
     * Tope de consultas por IP. El formato es público por diseño, así que no hay
     * nonce que valga: lo que se evita aquí es que alguien recorra los códigos
     * —que son predecibles: APC BOG TY No. 001, 002…— y se lleve la flota entera
     * con sus contactos.
     */
    private static function rate_limit( $clave, $maximo, $ventana ) {
        $ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'sin-ip';
        $llave = 'cmh_rl_' . $clave . '_' . md5( $ip );
        $n     = (int) get_transient( $llave );
        if ( $n >= $maximo ) return false;
        set_transient( $llave, $n + 1, $ventana );
        return true;
    }

    /**
     * La misma consulta, para quien rellena el formato sin haber iniciado sesión.
     * Devuelve solo lo que el formulario necesita, y con tope por IP: v2.8.
     */
    public static function ajax_get_machine_public() {
        if ( ! self::rate_limit( 'maq', 40, 10 * MINUTE_IN_SECONDS ) ) {
            wp_send_json_error( [ 'message' => 'Demasiadas consultas seguidas. Espera un momento.' ], 429 );
        }

        global $wpdb; $t = CMH_Core::tables();
        $code = sanitize_text_field( $_GET['code'] ?? '' );
        if ( ! $code ) wp_send_json_error( [ 'message' => 'Código requerido.' ] );
        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.machine_code, m.brand, m.model, m.serial, m.contact,
                    c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             WHERE m.machine_code=%s OR m.serial=%s",
            $code, $code
        ) );
        if ( ! $m ) wp_send_json_error( [ 'message' => 'Máquina no encontrada.' ] );
        wp_send_json_success( $m );
    }

    // =========================================================================
    // Eliminar intervención individual
    // =========================================================================

    public static function delete_intervention() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $id = intval( $_POST['intervention_id'] );
        $iv = $wpdb->get_row( $wpdb->prepare( "SELECT machine_id FROM {$t['interventions']} WHERE id=%d", $id ) );
        if ( ! $iv ) wp_die( 'Intervención no encontrada.' );
        $files = $wpdb->get_results( $wpdb->prepare( "SELECT file_path FROM {$t['files']} WHERE intervention_id=%d", $id ) );
        foreach ( $files as $f ) {
            if ( $f->file_path && file_exists( $f->file_path ) ) @unlink( $f->file_path );
        }
        $wpdb->delete( $t['files'],         [ 'intervention_id' => $id ] );
        $wpdb->delete( $t['interventions'], [ 'id'              => $id ] );
        self::redirect_to(
            self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => (int) $iv->machine_id ] ),
            'Intervención eliminada.'
        );
    }

    // =========================================================================
    // Editar empresa / ciudad
    // =========================================================================

    public static function update_company() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $id = intval( $_POST['company_id'] );
        // v2.2 — Junto al nombre y el código viajan los datos de contacto,
        // ubicación y facturación, que ahora pueden llegar prellenados al formato.
        $wpdb->update( $t['companies'], array_merge( [
            'name' => strtoupper( sanitize_text_field( $_POST['name'] ) ),
            'code' => self::clean_code( $_POST['code'] ),
        ], self::contact_fields_from_post( true ) ), [ 'id' => $id ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $id ] ), 'Empresa actualizada.' );
    }

    public static function update_city() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $id   = intval( $_POST['city_id'] );
        // v2.2 — Sin facturación: eso se le factura a la empresa, no a la sede.
        $wpdb->update( $t['cities'], array_merge( [
            'name' => strtoupper( sanitize_text_field( $_POST['name'] ) ),
            'code' => self::clean_code( $_POST['code'] ),
        ], self::contact_fields_from_post( false ) ), [ 'id' => $id ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $id ] ), 'Ciudad/Sucursal actualizada.' );
    }

    // =========================================================================
    // Eliminar máquina / ciudad / empresa
    // =========================================================================

    private static function do_delete_machine( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $files = $wpdb->get_results( $wpdb->prepare(
            "SELECT file_path FROM {$t['files']} WHERE machine_id=%d", $machine_id
        ) );
        foreach ( $files as $f ) {
            if ( $f->file_path && file_exists( $f->file_path ) ) @unlink( $f->file_path );
        }
        $wpdb->delete( $t['interventions'], [ 'machine_id' => $machine_id ] );
        $wpdb->delete( $t['files'],         [ 'machine_id' => $machine_id ] );
        $wpdb->delete( $t['machines'],      [ 'id'         => $machine_id ] );
    }

    public static function delete_machine() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $m = $wpdb->get_row( $wpdb->prepare( "SELECT city_id FROM {$t['machines']} WHERE id=%d", $machine_id ) );
        if ( ! $m ) wp_die( 'Máquina no encontrada.' );
        $city_id = (int) $m->city_id;
        self::do_delete_machine( $machine_id );
        self::redirect_to(
            self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] ),
            'Máquina eliminada.'
        );
    }

    public static function delete_city() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $city_id = intval( $_POST['city_id'] );
        $city = $wpdb->get_row( $wpdb->prepare( "SELECT company_id FROM {$t['cities']} WHERE id=%d", $city_id ) );
        if ( ! $city ) wp_die( 'Ciudad no encontrada.' );
        $company_id = (int) $city->company_id;
        $machines = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$t['machines']} WHERE city_id=%d", $city_id
        ) );
        foreach ( $machines as $mid ) self::do_delete_machine( (int) $mid );
        $wpdb->delete( $t['cities'], [ 'id' => $city_id ] );
        self::redirect_to(
            self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ),
            'Ciudad/Sucursal eliminada.'
        );
    }

    public static function delete_company() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $company_id = intval( $_POST['company_id'] );
        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['companies']} WHERE id=%d", $company_id ) ) )
            wp_die( 'Empresa no encontrada.' );
        $cities = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$t['cities']} WHERE company_id=%d", $company_id
        ) );
        foreach ( $cities as $cid ) {
            $machines = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$t['machines']} WHERE city_id=%d", (int) $cid
            ) );
            foreach ( $machines as $mid ) self::do_delete_machine( (int) $mid );
            $wpdb->delete( $t['cities'], [ 'id' => (int) $cid ] );
        }
        $wpdb->delete( $t['companies'], [ 'id' => $company_id ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies' ), 'Empresa eliminada.' );
    }

    // =========================================================================
    // v0.9 — Técnicos: asignaciones y tareas (lado admin)
    // =========================================================================

    /** Render del tab "Técnicos" en la hoja de vida de la máquina. */
    private static function machine_techs_tab( $machine_id ) {
        $technicians = CMH_Tech::technicians();
        $assigned    = CMH_Tech::machine_techs( $machine_id );
        $assigned_ids = wp_list_pluck( $assigned, 'ID' );
        $back        = self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] );

        // ── Asignaciones ──────────────────────────────────────────────────────
        echo '<h3 style="margin:0 0 10px;font-size:14px">Técnicos asignados</h3>';
        if ( ! $technicians ) {
            echo '<div class="cmh-note" style="margin:0 0 16px">No hay usuarios con rol <strong>Técnico (CM)</strong>. '
                . 'Crea usuarios en <a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">Usuarios → Añadir nuevo</a> y asígnales el rol «Técnico (CM)».</div>';
        }

        if ( $assigned ) {
            // v2.2 — El técnico principal es a quien se le asignan las tareas
            // automáticas del mantenimiento programado.
            $primary_id = CMH_Tech::primary_user_id( $machine_id );
            echo '<table class="widefat cmh" style="margin-bottom:14px"><thead><tr><th style="width:90px">Principal</th><th>Técnico</th><th>Email</th><th></th></tr></thead><tbody>';
            foreach ( $assigned as $u ) {
                $is_primary = (int) $u->ID === $primary_id;
                echo '<tr><td>'
                    . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
                    . '<input type="hidden" name="action" value="cm_set_primary_tech">'
                    . '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
                    . '<input type="hidden" name="user_id" value="' . intval( $u->ID ) . '">'
                    . '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">'
                    . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                    . ( $is_primary
                        ? '<span class="cmh-badge" style="background:#e6f4ea;color:#1a6630">Principal</span>'
                        : '<button class="button button-small" title="Hacer principal a este técnico">Marcar</button>' )
                    . '</form></td>'
                    . '<td><strong>' . esc_html( $u->display_name ) . '</strong></td>'
                    . '<td style="font-size:12px;color:#646970">' . esc_html( $u->user_email ) . '</td>'
                    . '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Quitar la asignación de este técnico?\')">'
                    . '<input type="hidden" name="action" value="cm_unassign_tech">'
                    . '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
                    . '<input type="hidden" name="user_id" value="' . intval( $u->ID ) . '">'
                    . '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">'
                    . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                    . '<button class="button button-small" style="color:#d63638;border-color:#d63638">Quitar</button>'
                    . '</form></td></tr>';
            }
            echo '</tbody></table>';
            echo '<p style="font-size:12px;color:#646970;margin:-6px 0 14px">El técnico <strong>principal</strong> recibe las tareas que se crean solas al programar un mantenimiento. Si no marcas ninguno, se usa el primero de la lista.</p>';
        } else {
            echo '<p style="color:#646970;font-size:13px;margin:0 0 14px">Ningún técnico asignado todavía.</p>';
        }

        // Asignar un técnico (los que aún no están asignados).
        $available = array_filter( $technicians, function ( $u ) use ( $assigned_ids ) {
            return ! in_array( $u->ID, $assigned_ids, true );
        } );
        if ( $available ) {
            self::form_start( 'cm_assign_tech' );
            echo '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
                . '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">'
                . '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:6px">'
                . '<label style="margin:0">Asignar técnico<select name="user_id" required style="min-width:220px">'
                . '<option value="">— Seleccionar —</option>';
            foreach ( $available as $u )
                echo '<option value="' . intval( $u->ID ) . '">' . esc_html( $u->display_name ) . '</option>';
            echo '</select></label><button class="button button-primary">Asignar</button></div></form>';
        }

        // ── Tareas ────────────────────────────────────────────────────────────
        echo '<hr style="margin:22px 0;border:none;border-top:1px solid #e0e0e0">';
        echo '<h3 style="margin:0 0 10px;font-size:14px">Tareas de mantenimiento</h3>';

        $tasks = CMH_Tech::tasks_for_machine( $machine_id );
        if ( $tasks ) {
            // v2.1 — Horas trabajadas por tarea (solo visibles para el admin).
            $task_secs   = CMH_Time::seconds_by_task( wp_list_pluck( $tasks, 'id' ) );
            $running_ids = CMH_Time::running_task_ids();
            echo '<div class="cmh-table-scroll"><table class="widefat cmh"><thead><tr><th>Tarea</th><th>Técnico</th><th>Vence</th><th>Estado</th><th>Horas</th><th>Formato</th><th></th></tr></thead><tbody>';
            foreach ( $tasks as $ta ) {
                $tech_name = $ta->assigned_to ? get_the_author_meta( 'display_name', $ta->assigned_to ) : '—';
                echo '<tr>'
                    . '<td><strong>' . esc_html( $ta->title ) . '</strong>'
                    . ( ( $ta->source ?? '' ) === 'auto' ? ' <span class="cmh-badge" style="background:#e7f0f7;color:#2271b1">Auto</span>' : '' )
                    . ( $ta->notes ? '<br><span style="font-size:12px;color:#646970">' . esc_html( wp_trim_words( $ta->notes, 24 ) ) . '</span>' : '' ) . '</td>'
                    . '<td>' . esc_html( $tech_name ?: '—' ) . '</td>'
                    . '<td>' . esc_html( $ta->due_date ?: '—' ) . '</td>'
                    . '<td>' . CMH_Tech::task_status_badge( $ta->status ) . '</td>'
                    . '<td>' . ( in_array( (int) $ta->id, $running_ids, true )
                        ? '<span class="cmh-badge" style="background:#e7f0fb;color:#1c4d80">En curso</span> '
                        : '' )
                        . esc_html( isset( $task_secs[ (int) $ta->id ] ) ? CMH_Time::format( $task_secs[ (int) $ta->id ] ) : '—' ) . '</td>'
                    . '<td>' . CMH_Tech::open_form_control( $ta, $back ) . '</td>'
                    . '<td class="cmh-row-actions">'
                    . CMH_Tech::complete_button( $ta, $back )
                    . '<button type="button" class="button button-small cmh-btn-toggle-edit" data-target="cmh-task-' . intval( $ta->id ) . '">Editar</button>'
                    . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'¿Eliminar esta tarea?\')">'
                    . '<input type="hidden" name="action" value="cm_delete_task">'
                    . '<input type="hidden" name="task_id" value="' . intval( $ta->id ) . '">'
                    . '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
                    . '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">'
                    . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                    . '<button class="button button-small" style="color:#d63638;border-color:#d63638">Eliminar</button>'
                    . '</form></td></tr>';
                // Fila de edición inline
                echo '<tr id="cmh-task-' . intval( $ta->id ) . '" style="display:none"><td colspan="8" style="background:#f6f7f7">';
                self::task_form( $machine_id, $back, $ta );
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p style="color:#646970;font-size:13px;margin:0 0 14px">No hay tareas para esta máquina.</p>';
        }

        // Crear tarea nueva
        echo '<div style="margin-top:16px;padding:16px;border:1px solid #e0e0e0;border-radius:8px">'
            . '<h4 style="margin:0 0 10px;font-size:13px">Nueva tarea</h4>';
        self::task_form( $machine_id, $back );
        echo '</div>';
    }

    /**
     * Formulario de tarea reutilizable: crea (sin $task) o edita (con $task).
     */
    private static function task_form( $machine_id, $back, $task = null ) {
        $is_edit = (bool) $task;
        self::form_start( $is_edit ? 'cm_update_task' : 'cm_save_task' );
        echo '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">';
        if ( $is_edit ) echo '<input type="hidden" name="task_id" value="' . intval( $task->id ) . '">';

        echo '<div class="cmh-form-grid">'
            . '<label>Título <em>*</em><input name="title" value="' . esc_attr( $is_edit ? $task->title : '' ) . '" required></label>'
            . '<label>Asignar a<select name="assigned_to"><option value="">— Sin asignar —</option>';
        foreach ( CMH_Tech::technicians() as $u )
            echo '<option value="' . intval( $u->ID ) . '" ' . selected( $is_edit ? $task->assigned_to : 0, $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
        echo '</select></label>'
            . '<label>Vence<input type="date" name="due_date" value="' . esc_attr( $is_edit ? ( $task->due_date ?: '' ) : '' ) . '"></label>';

        // v2.0 — Formato que el técnico debe diligenciar. Opcional: si se deja en
        // «El técnico elige», él escoge cuál al abrir la tarea desde su panel.
        echo '<label>Formato a diligenciar<select name="form_id"><option value="0">— El técnico elige —</option>';
        foreach ( CMH_Integration::forms_for_select() as $fid => $flabel )
            echo '<option value="' . intval( $fid ) . '" ' . selected( $is_edit ? (int) ( $task->form_id ?? 0 ) : 0, $fid, false ) . '>' . esc_html( $flabel ) . '</option>';
        echo '</select></label>';

        if ( $is_edit ) {
            echo '<label>Estado<select name="status">';
            foreach ( CMH_Tech::TASK_STATUSES as $k => $v )
                echo '<option value="' . esc_attr( $k ) . '" ' . selected( $task->status, $k, false ) . '>' . esc_html( $v ) . '</option>';
            echo '</select></label>';
        }
        echo '</div>'
            . '<label>Notas<textarea name="notes">' . ( $is_edit ? esc_textarea( (string) $task->notes ) : '' ) . '</textarea></label>'
            . '<button class="button button-primary">' . ( $is_edit ? 'Guardar cambios' : 'Crear tarea' ) . '</button></form>';
    }

    public static function assign_tech() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $user_id    = intval( $_POST['user_id'] );
        if ( ! $machine_id || ! $user_id ) wp_die( 'Datos incompletos.' );
        if ( ! user_can( $user_id, 'cmh_tech' ) ) wp_die( 'El usuario no es un técnico válido.' );

        // UNIQUE (machine_id, user_id) evita duplicados; INSERT IGNORE por si acaso.
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$t['assignments']} (machine_id, user_id, created_at) VALUES (%d, %d, %s)",
            $machine_id, $user_id, current_time( 'mysql' )
        ) );
        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Técnico asignado.' );
    }

    public static function unassign_tech() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $user_id    = intval( $_POST['user_id'] );
        $wpdb->delete( $t['assignments'], [ 'machine_id' => $machine_id, 'user_id' => $user_id ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Asignación eliminada.' );
    }

    /**
     * v2.2 — Marca al técnico principal de una máquina. Solo puede haber uno, así
     * que primero se limpia la marca de los demás.
     */
    public static function set_primary_tech() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $user_id    = intval( $_POST['user_id'] );
        if ( ! $machine_id || ! $user_id ) wp_die( 'Datos incompletos.' );

        $wpdb->update( $t['assignments'], [ 'is_primary' => 0 ], [ 'machine_id' => $machine_id ] );
        $done = $wpdb->update( $t['assignments'], [ 'is_primary' => 1 ], [ 'machine_id' => $machine_id, 'user_id' => $user_id ] );

        self::redirect_to(
            self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ),
            $done ? 'Técnico principal actualizado.' : '',
            $done ? '' : 'Ese técnico no está asignado a la máquina.'
        );
    }

    /** v2.0 — Formato elegido para la tarea; null si se deja «el técnico elige». */
    private static function form_id_from_post() {
        $id = intval( $_POST['form_id'] ?? 0 );
        return CMH_Integration::is_valid_form( $id ) ? $id : null;
    }

    public static function save_task() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $title      = sanitize_text_field( $_POST['title'] ?? '' );
        if ( ! $machine_id || ! $title ) {
            self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), '', 'El título de la tarea es obligatorio.' );
        }
        $wpdb->insert( $t['tasks'], [
            'machine_id'  => $machine_id,
            'assigned_to' => intval( $_POST['assigned_to'] ) ?: null,
            'title'       => $title,
            'notes'       => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'due_date'    => sanitize_text_field( $_POST['due_date'] ?? '' ) ?: null,
            'status'      => 'pendiente',
            'form_id'     => self::form_id_from_post(),
            'created_by'  => get_current_user_id(),
        ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Tarea creada.' );
    }

    public static function update_task() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $task_id    = intval( $_POST['task_id'] );
        $machine_id = intval( $_POST['machine_id'] );
        $task       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['tasks']} WHERE id=%d", $task_id ) );
        if ( ! $task ) wp_die( 'Tarea no encontrada.' );
        $status = sanitize_key( $_POST['status'] ?? 'pendiente' );
        if ( ! isset( CMH_Tech::TASK_STATUSES[ $status ] ) ) $status = 'pendiente';

        $assigned_to = intval( $_POST['assigned_to'] ) ?: null;

        $wpdb->update( $t['tasks'], [
            'title'       => sanitize_text_field( $_POST['title'] ),
            'assigned_to' => $assigned_to,
            'notes'       => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'due_date'    => sanitize_text_field( $_POST['due_date'] ?? '' ) ?: null,
            'status'      => $status,
            'form_id'     => self::form_id_from_post(),
            'updated_at'  => current_time( 'mysql' ),
        ], [ 'id' => $task_id ] );

        // v2.1 — El reloj de horas sigue al estado de la tarea. Las horas se le
        // cargan al técnico asignado tras este guardado, nunca al administrador.
        if ( $task->status !== $status ) {
            $task->assigned_to = $assigned_to;
            CMH_Time::on_status_change( $task, $status, get_current_user_id() );
        }

        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Tarea actualizada.' );
    }

    public static function delete_task() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $task_id    = intval( $_POST['task_id'] );
        $machine_id = intval( $_POST['machine_id'] );
        $wpdb->delete( $t['tasks'], [ 'id' => $task_id ] );
        // v2.1 — Sin la tarea, sus tramos de horas quedarían huérfanos.
        $wpdb->delete( $t['task_time'], [ 'task_id' => $task_id ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Tarea eliminada.' );
    }
}
