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

        foreach ( [ 'company', 'city', 'branch', 'machine', 'intervention' ] as $type ) {
            add_action( 'admin_post_cm_save_' . $type, [ __CLASS__, 'save_' . $type ] );
        }
        add_action( 'admin_post_cm_upload_file',       [ __CLASS__, 'upload_file' ] );
        add_action( 'admin_post_cm_update_machine',    [ __CLASS__, 'update_machine' ] );
        add_action( 'admin_post_cm_export_csv',        [ __CLASS__, 'export_csv' ] );
        add_action( 'admin_post_cm_edit_intervention', [ __CLASS__, 'edit_intervention' ] );
        add_action( 'admin_post_cm_update_company',    [ __CLASS__, 'update_company' ] );
        add_action( 'admin_post_cm_update_city',       [ __CLASS__, 'update_city' ] );
        add_action( 'admin_post_cm_delete_intervention', [ __CLASS__, 'delete_intervention' ] );
        add_action( 'admin_post_cm_delete_machine',    [ __CLASS__, 'delete_machine' ] );
        add_action( 'admin_post_cm_delete_city',       [ __CLASS__, 'delete_city' ] );
        add_action( 'admin_post_cm_delete_company',    [ __CLASS__, 'delete_company' ] );
        add_action( 'wp_ajax_cmh_get_machine',         [ __CLASS__, 'ajax_get_machine' ] );
        add_action( 'wp_ajax_nopriv_cmh_get_machine',  [ __CLASS__, 'ajax_get_machine_public' ] );
    }

    public static function admin_menu() {
        $slug = CMH_SLUG;
        add_menu_page( 'Historial de Máquinas', 'Máquinas', 'edit_others_posts', $slug, [ __CLASS__, 'page_dashboard' ], 'dashicons-hammer', 26 );
        add_submenu_page( $slug, 'Dashboard',       'Dashboard',       'edit_others_posts', $slug,                  [ __CLASS__, 'page_dashboard' ] );
        add_submenu_page( $slug, 'Empresas',        'Empresas',        'edit_others_posts', $slug . '-companies',   [ __CLASS__, 'page_companies' ] );
        add_submenu_page( $slug, 'Buscar máquinas', 'Buscar máquinas', 'edit_others_posts', $slug . '-machines',    [ __CLASS__, 'page_machines' ] );
        add_submenu_page( $slug, 'Integración',     'Integración',     'edit_others_posts', $slug . '-integration', [ __CLASS__, 'page_integration' ] );
    }

    public static function assets( $hook ) {
        if ( strpos( $hook, CMH_SLUG ) === false ) return;
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

    public static function brand_code( $brand ) {
        static $map = [
            'TOYOTA' => 'TY', 'CROWN' => 'CR', 'HYSTER' => 'HY', 'HANGCHA' => 'HC',
            'YALE' => 'YA', 'LINDE' => 'LD', 'KOMATSU' => 'KM', 'NISSAN' => 'NS',
            'CATERPILLAR' => 'CAT', 'MITSUBISHI' => 'MI', 'STILL' => 'ST', 'JUNGHEINRICH' => 'JH',
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

    public static function form_start( $action, $multipart = false ) {
        $enc = $multipart ? ' enctype="multipart/form-data"' : '';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $enc . '>';
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

    public static function metric_card( $label, $value, $hint = '', $accent = '' ) {
        $acc = $accent ? '<div class="cmh-card-accent cmh-card-accent-' . esc_attr( $accent ) . '"></div>' : '';
        echo '<div class="cmh-card">' . $acc
            . '<span>' . esc_html( $label ) . '</span>'
            . '<strong>' . esc_html( (string) $value ) . '</strong>'
            . ( $hint !== '' ? '<small>' . esc_html( $hint ) . '</small>' : '' )
            . '</div>';
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
        $cost_total    = (float) $wpdb->get_var( "SELECT COALESCE(SUM(cost),0) FROM {$t['interventions']}" );
        $fleet_avail   = CMH_Metrics::fleet_availability( $month, $year );
        $fleet_mttr    = CMH_Metrics::mttr( 0, $month, $year );
        $month_dt      = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(downtime_hours),0) FROM {$t['interventions']} WHERE affects_availability=1 AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d",
            $month, $year
        ) );
        $month_label   = CMH_Metrics::month_label( $month, $year );
        $critical      = CMH_Metrics::critical_machines();

        $avail_accent = $fleet_avail === null ? 'blue' : ( $fleet_avail >= 90 ? 'ok' : ( $fleet_avail >= 70 ? 'warn' : 'danger' ) );

        self::page_header( 'Dashboard' );

        echo '<div class="cmh-hero-block">'
            . '<div><h2>Resumen operativo</h2><p>Vista general de la flota — ' . esc_html( $month_label ) . '</p></div>'
            . '<a class="button button-primary" href="' . esc_url( self::admin_url( CMH_SLUG . '-companies' ) ) . '">Gestionar empresas</a>'
            . '</div>';

        echo '<div class="cmh-grid">';
        self::metric_card( 'Máquinas',              $machines,                                                  'registradas',      'blue' );
        self::metric_card( 'Intervenciones',         $interventions,                                             'historial total',  'blue' );
        self::metric_card( 'Preventivos',            $preventivos,                                               'historial total',  'ok' );
        self::metric_card( 'Correctivos / Averías',  $correctivos,                                               'historial total',  'warn' );
        self::metric_card( 'Disponibilidad '  . $month_label, CMH_Metrics::fmt_pct( $fleet_avail ),             'flota ' . $month_label, $avail_accent );
        self::metric_card( 'MTTR ' . $month_label,   CMH_Metrics::fmt_mttr( $fleet_mttr ),                      'solo averías',     'warn' );
        self::metric_card( 'Horas parada '    . $month_label, number_format( $month_dt, 2, ',', '.' ) . ' h',  'por averías',      'danger' );
        self::metric_card( 'Costo total',            '$' . number_format( $cost_total, 0, ',', '.' ),            'historial',        'blue' );
        echo '</div>';

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
            "SELECT m.id, m.machine_code, m.brand, m.model, m.next_maintenance_date,
                    c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}   ci ON ci.id=m.city_id
             WHERE m.next_maintenance_date IS NOT NULL AND m.next_maintenance_date <= %s
             ORDER BY m.next_maintenance_date ASC",
            $in30
        ) );
        if ( $upcoming ) {
            echo '<div class="cmh-panel">'
                . '<h2>Mantenimientos próximos <small style="font-weight:400;font-size:13px;color:#646970">— próximos 30 días o vencidos</small></h2>'
                . '<table class="widefat cmh"><thead><tr>'
                . '<th>Máquina</th><th>Equipo</th><th>Ubicación</th><th>Fecha programada</th><th>Estado</th><th></th>'
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

        echo '<div class="cmh-layout"><div class="cmh-main"><div class="cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Empresas registradas</h2>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'machines' ) ) . '">Exportar todas las máquinas (CSV)</a></div>'
            . '<table class="widefat cmh"><thead><tr><th>Empresa</th><th>Código</th><th>Ciudades</th><th>Máquinas</th><th></th></tr></thead><tbody>';

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
            self::empty_state( 'dashicons-building', 'Sin empresas', 'Crea la primera empresa para comenzar.' );
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';

        echo '<div class="cmh-side"><div class="cmh-panel"><h2>Nueva empresa</h2>';
        self::form_start( 'cm_save_company' );
        echo '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies' ) ) . '">'
            . '<label>Nombre <em>*</em></label><input name="name" required class="cmh-uppercase">'
            . '<label>Código corto <em>*</em></label><input name="code" placeholder="APC" maxlength="10" required class="cmh-uppercase">'
            . '<p style="font-size:12px;color:#646970;margin:6px 0">Se usará en el código: <strong>APC BOG TY No.001</strong></p>'
            . '<button class="button button-primary">Guardar empresa</button></form>';
        echo '</div></div></div>';
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
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'machines', [ 'company_id' => $company_id ] ) ) . '">Exportar máquinas (CSV)</a>'
            . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Eliminar empresa \'+' . json_encode( $c->name ) . '+\' con ' . intval( $stats->cities ) . ' ciudades y ' . intval( $stats->machines ) . ' máquinas? Esta acción es irreversible.\')">'
            . '<input type="hidden" name="action" value="cm_delete_company">'
            . '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
            . '<button type="submit" class="button" style="color:#d63638;border-color:#d63638">Eliminar empresa</button>'
            . '</form>'
            . '</div></div>';

        echo '<div class="cmh-layout"><div class="cmh-main"><div class="cmh-panel"><h2>Ciudades / Sucursales</h2>'
            . '<table class="widefat cmh"><thead><tr><th>Ciudad/Sucursal</th><th>Código</th><th>Máquinas</th><th></th></tr></thead><tbody>';

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

        echo '<div class="cmh-side"><div class="cmh-panel"><h2>Nueva ciudad/sucursal</h2>';
        self::form_start( 'cm_save_city' );
        echo '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ) ) . '">'
            . '<label>Nombre <em>*</em></label><input name="name" placeholder="BOGOTÁ" required class="cmh-uppercase">'
            . '<label>Código <em>*</em></label><input name="code" placeholder="BOG" maxlength="10" required class="cmh-uppercase">'
            . '<button class="button button-primary">Guardar</button></form>';
        echo '</div><div class="cmh-panel"><h2>Editar empresa</h2>';
        self::form_start( 'cm_update_company' );
        echo '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ) ) . '">'
            . '<label>Nombre <em>*</em></label><input name="name" value="' . esc_attr( $c->name ) . '" required class="cmh-uppercase">'
            . '<label>Código <em>*</em></label><input name="code" value="' . esc_attr( $c->code ) . '" maxlength="10" required class="cmh-uppercase">'
            . '<p style="font-size:12px;color:#646970;margin:4px 0 12px">Cambiar el código <strong>no</strong> actualiza los códigos de máquinas existentes.</p>'
            . '<button class="button button-primary">Guardar cambios</button></form>';
        echo '</div></div></div>';
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
        echo '<div class="cmh-layout"><div class="cmh-main">';
        echo '<div class="cmh-panel"><div class="cmh-toolbar"><h2>Máquinas en ' . esc_html( $city->name ) . '</h2><div style="display:flex;gap:8px;align-items:center">'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'machines', [ 'city_id' => $city_id ] ) ) . '">Exportar CSV</a>'
            . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Eliminar ciudad/sucursal \'+' . json_encode( $city->name ) . '+\' con ' . $city_machine_count . ' máquinas? Esta acción es irreversible.\')">'
            . '<input type="hidden" name="action" value="cm_delete_city">'
            . '<input type="hidden" name="city_id" value="' . intval( $city_id ) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
            . '<button type="submit" class="button" style="color:#d63638;border-color:#d63638">Eliminar ciudad</button>'
            . '</form></div></div>';
        self::machines_table( $city_id, 0 );
        echo '</div></div>';

        echo '<div class="cmh-side"><div class="cmh-panel"><h2>Agregar máquina</h2>';
        self::machine_form( $city->company_id, $city_id );
        echo '</div><div class="cmh-panel"><h2>Editar ciudad/sucursal</h2>';
        self::form_start( 'cm_update_city' );
        echo '<input type="hidden" name="city_id" value="' . intval( $city_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] ) ) . '">'
            . '<label>Nombre <em>*</em></label><input name="name" value="' . esc_attr( $city->name ) . '" required class="cmh-uppercase">'
            . '<label>Código <em>*</em></label><input name="code" value="' . esc_attr( $city->code ) . '" maxlength="10" required class="cmh-uppercase">'
            . '<p style="font-size:12px;color:#646970;margin:4px 0 12px">Cambiar el código <strong>no</strong> actualiza los códigos de máquinas existentes.</p>'
            . '<button class="button button-primary">Guardar cambios</button></form>';
        echo '</div></div></div>';
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

        echo '<div class="cmh-layout"><div class="cmh-main"><div class="cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Máquinas en ' . esc_html( $b->name ) . '</h2>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'machines', [ 'branch_id' => $branch_id ] ) ) . '">Exportar CSV</a></div>';
        self::machines_table( 0, $branch_id );
        echo '</div></div><div class="cmh-side"><div class="cmh-panel"><h2>Agregar máquina</h2>';
        self::machine_form( $b->company_id, $b->city_id, $branch_id );
        echo '</div></div></div>';
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
            "SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN affects_availability=1 THEN downtime_hours ELSE 0 END),0) downtime_averia, COALESCE(SUM(CASE WHEN affects_availability=0 THEN downtime_hours ELSE 0 END),0) downtime_maintenance, COALESCE(SUM(cost),0) cost, SUM(CASE WHEN maintenance_type='preventivo' THEN 1 ELSE 0 END) preventivos, SUM(CASE WHEN maintenance_type IN('correctivo','averia') THEN 1 ELSE 0 END) correctivos FROM {$t['interventions']} WHERE machine_id=%d",
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

        // Hero
        $loc = $m->company_name . ' / ' . $m->city_name . ( $m->branch_id ? ' / ' . $m->branch_name : '' );
        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Hoja de vida técnica</div>'
            . '<h2>' . esc_html( $m->machine_code ) . ' ' . self::status_badge( $m->status )
            . ( $is_crit ? ' <span class="cmh-badge cmh-badge-critical">Crítica</span>' : '' )
            . $maint_badge . '</h2>'
            . '<p>' . esc_html( $loc ) . ' &nbsp;·&nbsp; ' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</p>'
            . '</div><div class="cmh-hero-actions">'
            . '<a class="button cmh-btn-print" href="#">Imprimir hoja de vida</a>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'interventions', [ 'machine_id' => $machine_id ] ) ) . '">Exportar intervenciones (CSV)</a>'
            . '<a class="button" href="' . esc_url( $m->branch_id ? self::admin_url( CMH_SLUG . '-companies', [ 'branch_id' => $m->branch_id ] ) : self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $m->city_id ] ) ) . '">Volver</a>'
            . '</div></div>';

        // KPIs
        echo '<div class="cmh-grid">';
        self::metric_card( 'Intervenciones',    $stats->total,                                                         'historial',         'blue' );
        self::metric_card( 'Preventivos',       (int) $stats->preventivos,                                             'historial',         'ok' );
        self::metric_card( 'Correctivos/Averías',(int) $stats->correctivos,                                            'historial',         'warn' );
        self::metric_card( 'H. parada averías', number_format( (float)$stats->downtime_averia, 2, ',', '.' ) . ' h', 'historial',         'danger' );
        self::metric_card( 'Disponibilidad ' . CMH_Metrics::month_label( $month, $year ), CMH_Metrics::fmt_pct( $avail_now ), 'mes actual', $avail_acc );
        self::metric_card( 'MTTR',              CMH_Metrics::fmt_mttr( $mttr_all ),                                    'historial',         'warn' );
        self::metric_card( 'Costo total',       '$' . number_format( (float)$stats->cost, 0, ',', '.' ),              'historial',         'blue' );
        self::metric_card( 'Horómetro',         number_format( (float)$m->current_hourmeter, 2, ',', '.' ) . ' h',   'actual',            'blue' );
        echo '</div>';

        // Tabs
        echo '<div class="cmh-tabs-wrapper">'
            . '<div class="cmh-tabs">'
            . '<a href="#tab-resumen"  class="cmh-tab" data-tab="resumen">Resumen</a>'
            . '<a href="#tab-interv"   class="cmh-tab" data-tab="interv">Intervenciones (' . intval( $stats->total ) . ')</a>'
            . '<a href="#tab-disponib" class="cmh-tab" data-tab="disponib">Disponibilidad</a>'
            . '<a href="#tab-pdfs"     class="cmh-tab" data-tab="pdfs">PDFs</a>'
            . '<a href="#tab-editar"   class="cmh-tab" data-tab="editar">Editar</a>'
            . '</div>';

        echo '<div class="cmh-layout"><div class="cmh-main">';

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
            . '</div>'
            . ( $m->notes ? '<div class="cmh-note"><strong>Notas:</strong> ' . esc_html( $m->notes ) . '</div>' : '' )
            . '</div>';

        // Tab: Intervenciones
        echo '<div id="tab-interv" class="cmh-tab-panel cmh-panel"><h2>Timeline de intervenciones</h2>';
        self::intervention_cards( $machine_id );
        echo '</div>';

        // Tab: Disponibilidad
        echo '<div id="tab-disponib" class="cmh-tab-panel cmh-panel">'
            . '<div class="cmh-toolbar"><h2>Disponibilidad mensual</h2>'
            . '<a class="button" href="' . esc_url( self::export_nonce_url( 'availability', [ 'machine_id' => $machine_id ] ) ) . '">Exportar CSV</a></div>';
        self::availability_table( $machine_id );
        echo '</div>';

        // Tab: PDFs
        echo '<div id="tab-pdfs" class="cmh-tab-panel cmh-panel"><h2>Archivos y PDFs</h2>';
        self::files_table( $machine_id );
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

        echo '</div><div class="cmh-side">';
        echo '<div class="cmh-panel"><h2>Registrar intervención</h2>';
        self::intervention_form( $machine_id, (float) $m->current_hourmeter, $m->status );
        echo '</div><div class="cmh-panel"><h2>Anexar PDF / archivo</h2>';
        self::upload_form( $machine_id );
        echo '</div></div></div></div>'; // close tabs-wrapper

        self::page_footer();
    }

    // =========================================================================
    // Componentes UI
    // =========================================================================

    private static function empty_state( $icon, $title, $desc = '' ) {
        echo '<div class="cmh-empty">'
            . '<div class="cmh-empty-icon"><span class="dashicons ' . esc_attr( $icon ) . '"></span></div>'
            . '<strong>' . esc_html( $title ) . '</strong>'
            . ( $desc ? '<p>' . esc_html( $desc ) . '</p>' : '' )
            . '</div>';
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

        if ( ! $rows ) { self::empty_state( 'dashicons-hammer', 'Sin máquinas', 'No hay máquinas con esos filtros.' ); return; }

        echo '<table class="widefat cmh cmh-machine-table"><thead><tr>'
            . '<th>Código</th><th>Marca / Modelo</th><th>Serial</th><th>Ubicación</th>'
            . '<th>Horómetro</th><th>Estado</th><th>Interv.</th><th>Última</th><th></th>'
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
        echo '</tbody></table>';
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
        $example  = ( $company && $city ) ? $company->code . ' ' . $city->code . ' TY No.001' : 'EMP CIU TY No.001';

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
            . '<label>Notas<textarea name="notes"></textarea></label>'
            . '<button class="button button-primary">Guardar máquina</button></form>';
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
            . '<label>Notas<textarea name="notes">' . esc_textarea( $m->notes ) . '</textarea></label>'
            . '<button class="button button-primary">Guardar cambios</button></form>';
    }

    public static function interventions_table( $limit = 20, $machine_id = 0 ) {
        global $wpdb; $t = CMH_Core::tables();
        $where = $machine_id ? $wpdb->prepare( 'WHERE i.machine_id=%d', $machine_id ) : '';
        $rows  = $wpdb->get_results(
            "SELECT i.*, m.machine_code, f.file_url FROM {$t['interventions']} i LEFT JOIN {$t['machines']} m ON m.id=i.machine_id LEFT JOIN {$t['files']} f ON f.intervention_id=i.id $where GROUP BY i.id ORDER BY i.intervention_date DESC, i.id DESC LIMIT " . intval( $limit )
        );
        if ( ! $rows ) { self::empty_state( 'dashicons-calendar-alt', 'Sin intervenciones', 'Aún no hay registros.' ); return; }

        echo '<table class="widefat cmh"><thead><tr><th>Fecha</th><th>Máquina</th><th>Tipo</th><th>Técnico</th><th>H. parada</th><th>Costo</th><th>PDF</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr>'
                . '<td>' . esc_html( $r->intervention_date ) . '</td>'
                . '<td>' . esc_html( $r->machine_code ) . '</td>'
                . '<td>' . self::mtype_badge( $r->maintenance_type ?: $r->form_type ) . '</td>'
                . '<td>' . esc_html( $r->technician ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->downtime_hours ) . ' h</td>'
                . '<td>$' . number_format( (float) $r->cost, 0, ',', '.' ) . '</td>'
                . '<td>' . ( $r->file_url ? '<a target="_blank" href="' . esc_url( $r->file_url ) . '">Ver PDF</a>' : '—' ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function mtype_badge( $type ) {
        $colors = [ 'preventivo' => 'ok', 'correctivo' => 'warn', 'averia' => 'danger', 'evaluacion' => '' ];
        $labels = [ 'preventivo' => 'Preventivo', 'correctivo' => 'Correctivo', 'averia' => 'Avería', 'evaluacion' => 'Evaluación' ];
        $key    = strtolower( $type );
        $color  = $colors[ $key ] ?? '';
        $label  = $labels[ $key ] ?? ucfirst( $type );
        $style  = $color ? "background:var(--cmh-{$color}-light);color:#1d2327;" : '';
        return '<span class="cmh-badge" style="' . $style . '">' . esc_html( $label ) . '</span>';
    }

    public static function intervention_cards( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, f.file_url FROM {$t['interventions']} i LEFT JOIN {$t['files']} f ON f.intervention_id=i.id WHERE i.machine_id=%d ORDER BY i.intervention_date DESC, i.id DESC LIMIT 150",
            $machine_id
        ) );
        if ( ! $rows ) { self::empty_state( 'dashicons-calendar-alt', 'Sin intervenciones', 'Registra la primera intervención en el formulario de la derecha.' ); return; }

        $dot_class = [ 'preventivo' => 'cmh-dot-preventivo', 'correctivo' => 'cmh-dot-correctivo', 'averia' => 'cmh-dot-averia', 'evaluacion' => 'cmh-dot-evaluacion' ];
        $card_class = [ 'preventivo' => 'cmh-card-preventivo', 'correctivo' => 'cmh-card-correctivo', 'averia' => 'cmh-card-averia' ];

        echo '<div class="cmh-filter-bar" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">'
            . '<span style="font-size:12px;font-weight:500;color:#646970">Filtrar:</span>'
            . '<button type="button" class="button button-small cmh-tl-filter active" data-filter="">Todas</button>'
            . '<button type="button" class="button button-small cmh-tl-filter" data-filter="preventivo">Preventivo</button>'
            . '<button type="button" class="button button-small cmh-tl-filter" data-filter="averia">Avería</button>'
            . '<button type="button" class="button button-small cmh-tl-filter" data-filter="correctivo">Correctivo</button>'
            . '<button type="button" class="button button-small cmh-tl-filter" data-filter="evaluacion">Evaluación</button>'
            . '</div>';
        echo '<div class="cmh-timeline">';
        foreach ( $rows as $r ) {
            $mt   = strtolower( $r->maintenance_type ?: '' );
            $dc   = $dot_class[ $mt ] ?? '';
            $cc   = $card_class[ $mt ] ?? '';
            echo '<div class="cmh-timeline-item" data-mtype="' . esc_attr( $mt ) . '">'
                . '<div class="cmh-dot ' . $dc . '"></div>'
                . '<div class="cmh-timeline-card ' . $cc . '">'
                . '<div class="cmh-timeline-head">'
                . '<strong>' . self::mtype_badge( $r->maintenance_type ?: $r->form_type )
                . ( $r->affects_availability ? ' <span class="cmh-badge cmh-badge-averia">Descuenta disponibilidad</span>' : '' ) . '</strong>'
                . '<time>' . esc_html( $r->intervention_date ) . '</time></div>'
                . '<div class="cmh-meta">'
                . '<span>Técnico: ' . esc_html( $r->technician ?: '—' ) . '</span>'
                . '<span>H: ' . esc_html( $r->hourmeter ) . '</span>'
                . '<span>Parada: ' . esc_html( $r->downtime_hours ) . ' h</span>'
                . ( $r->failure_system ? '<span>' . esc_html( ucfirst( $r->failure_system ) ) . '</span>' : '' )
                . '</div>';
            if ( $r->services )     echo '<p><strong>Servicios:</strong> '     . esc_html( wp_trim_words( $r->services,     32 ) ) . '</p>';
            if ( $r->observations ) echo '<p><strong>Observaciones:</strong> ' . esc_html( wp_trim_words( $r->observations, 32 ) ) . '</p>';
            echo '<div class="cmh-card-actions">';
            if ( $r->file_url ) echo '<a class="button button-small" target="_blank" href="' . esc_url( $r->file_url ) . '">Ver PDF</a>';
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
            self::form_start( 'cm_edit_intervention' );
            echo '<input type="hidden" name="intervention_id" value="' . intval( $r->id ) . '">';
            echo '<div class="cmh-form-grid">'
                . '<label>Fecha<input type="date" name="intervention_date" value="' . esc_attr( $r->intervention_date ) . '"></label>'
                . '<label>Tipo<select name="maintenance_type">';
            foreach ( [ 'preventivo' => 'Preventivo', 'correctivo' => 'Correctivo', 'averia' => 'Avería', 'evaluacion' => 'Evaluación' ] as $k => $v )
                echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r->maintenance_type, $k, false ) . '>' . esc_html( $v ) . '</option>';
            echo '</select></label>'
                . '<label>Técnico<input name="technician" value="' . esc_attr( $r->technician ) . '"></label>'
                . '<label>Horas parada' . ( $r->worked_hours > 0 ? ' <small style="color:#646970">(H. trabajadas: ' . esc_html( $r->worked_hours ) . ' h)</small>' : '' ) . '<input type="number" step="0.01" name="downtime_hours" value="' . esc_attr( $r->downtime_hours ) . '" min="0" placeholder="' . esc_attr( $r->worked_hours > 0 ? $r->worked_hours : '0' ) . '"></label>'
                . '<label>Costo<input type="number" step="100" name="cost" value="' . esc_attr( $r->cost ) . '" min="0"></label>'
                . '</div>'
                . '<label><input type="checkbox" name="affects_availability" value="1" ' . checked( $r->affects_availability, 1, false ) . '> Afecta disponibilidad</label>'
                . '<label style="display:block;margin-top:8px">Observaciones<textarea name="observations">' . esc_textarea( $r->observations ) . '</textarea></label>'
                . '<div style="margin-top:8px;display:flex;gap:8px">'
                . '<button class="button button-primary button-small">Guardar</button>'
                . '<button type="button" class="button button-small cmh-btn-toggle-edit" data-target="cmh-edit-' . intval( $r->id ) . '">Cancelar</button>'
                . '</div></form></div>';

            echo '</div></div>'; // close .cmh-timeline-card, .cmh-timeline-item
        }
        echo '</div>';
    }

    public static function availability_table( $machine_id ) {
        $breakdown = CMH_Metrics::monthly_breakdown( $machine_id, 13 );
        if ( empty( $breakdown ) ) { self::empty_state( 'dashicons-chart-area', 'Sin datos', 'Registra intervenciones para ver el historial de disponibilidad mensual.' ); return; }

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

    public static function intervention_form( $machine_id, $last_hourmeter = 0, $current_status = 'activa' ) {
        static $systems = [ 'frenos' => 'Frenos', 'potencia' => 'Potencia', 'traccion' => 'Tracción', 'seguridad' => 'Seguridad', 'encendido' => 'Encendido', 'refrigeracion' => 'Refrigeración', 'mastil' => 'Mástil', 'direccion' => 'Dirección', 'combustible' => 'Combustible', 'hidraulico' => 'Sist. Hidráulico', 'electronico' => 'Electrónico', 'otro' => 'Otro' ];

        self::form_start( 'cm_save_intervention' );
        echo '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ) ) . '">';

        echo '<label>Fecha <em>*</em></label><input type="date" name="intervention_date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required>'
            . '<label>Tipo <em>*</em></label>'
            . '<select name="maintenance_type" id="cmh-mtype">'
            . '<option value="preventivo">Preventivo</option>'
            . '<option value="correctivo">Correctivo</option>'
            . '<option value="averia">Avería</option>'
            . '<option value="evaluacion">Evaluación</option>'
            . '</select>'
            . '<label>Técnico</label><input name="technician">'
            . '<label>Horómetro</label>'
            . '<input type="number" step="0.01" name="hourmeter" min="0" id="cmh-hourmeter-input" data-last-hourmeter="' . esc_attr( $last_hourmeter ) . '">'
            . '<div id="cmh-hourmeter-warn" class="cmh-field-warning" style="display:none"></div>';

        echo '<div id="cmh-downtime-fields" class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Falla / Parada</p>'
            . '<label>Sistema / Falla</label><select name="failure_system"><option value="">— Seleccionar —</option>';
        foreach ( $systems as $k => $v ) echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
        echo '</select>'
            . '<label>Horas parada <span class="cmh-optional">(averías)</span></label>'
            . '<input type="number" step="0.01" name="downtime_hours" value="0" min="0">'
            . '</div>';

        echo '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Datos adicionales</p>'
            . '<label>Horas trabajadas</label><input type="number" step="0.01" name="worked_hours" value="0" min="0">'
            . '<label>Costo</label><input type="number" step="100" name="cost" value="0" min="0">'
            . '<div id="cmh-av-row">'
            . '<label><input type="checkbox" name="affects_availability" value="1"> Afecta disponibilidad'
            . ' <span class="cmh-auto-note" style="display:none;color:#2271b1;font-size:11px">(automático según tipo)</span></label>'
            . '</div></div>';

        // Estado automático (V0.8)
        echo '<div class="cmh-form-section" id="cmh-status-row">'
            . '<p class="cmh-form-section-title">Actualizar estado de la máquina</p>'
            . '<label>Estado actual: ' . self::status_badge( $current_status ) . '<br>Nuevo estado <span class="cmh-optional">(opcional)</span></label>'
            . '<select name="new_machine_status"><option value="">— Mantener estado actual —</option>'
            . '<option value="activa">Activa</option>'
            . '<option value="mantenimiento">En mantenimiento</option>'
            . '<option value="inactiva">Inactiva</option>'
            . '<option value="fuera_servicio">Fuera de servicio</option>'
            . '</select></div>';

        echo '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Detalle del servicio</p>'
            . '<label>Repuestos / insumos</label><textarea name="parts"></textarea>'
            . '<label>Servicios prestados</label><textarea name="services"></textarea>'
            . '<label>Observaciones</label><textarea name="observations"></textarea>'
            . '</div>'
            . '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Programar próximo mantenimiento</p>'
            . '<label>Fecha <span class="cmh-optional">(opcional)</span><input type="date" name="next_maintenance_date" min="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></label>'
            . '<p style="font-size:12px;color:#646970;margin:2px 0 0">Actualiza la fecha en la hoja de vida de la máquina.</p>'
            . '</div>'
            . '<button class="button button-primary">Guardar intervención</button></form>';
    }

    public static function files_table( $machine_id = 0 ) {
        global $wpdb; $t = CMH_Core::tables();
        $where = $machine_id ? $wpdb->prepare( 'WHERE machine_id=%d', $machine_id ) : '';
        $rows  = $wpdb->get_results( "SELECT * FROM {$t['files']} $where ORDER BY id DESC LIMIT 100" );
        if ( ! $rows ) { self::empty_state( 'dashicons-media-document', 'Sin archivos', 'Sube el primer PDF usando el formulario de la derecha.' ); return; }
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

        echo '<div class="cmh-panel"><h2>Formularios conectados</h2>'
            . '<table class="widefat cmh"><thead><tr><th>Form ID</th><th>Tipo</th><th>Campo máquina</th><th>Mantenimiento</th><th>Estado</th></tr></thead><tbody>';
        foreach ( CMH_Integration::config() as $fid => $cfg ) {
            echo '<tr><td><strong>' . intval( $fid ) . '</strong></td><td>' . esc_html( $cfg['form_type'] ) . '</td>'
                . '<td><code>' . esc_html( $cfg['machine_field'] ) . '</code></td><td>' . esc_html( $cfg['maintenance_type'] ) . '</td>'
                . '<td><span class="cmh-badge cmh-status-activa">Activo</span></td></tr>';
        }
        echo '</tbody></table><p style="font-size:12px;color:#646970;margin-top:10px">Forminator captura los envíos, crea la intervención y E2PDF asocia el PDF generado. Si el PDF no aparece de inmediato, WP-Cron lo reintenta 90 s después.</p></div>';

        $rows = $wpdb->get_results( "SELECT * FROM {$t['logs']} ORDER BY id DESC LIMIT 100" );
        echo '<div class="cmh-panel"><div class="cmh-toolbar"><h2>Logs de integración</h2>'
            . '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cm_export_csv&type=logs' ), 'cmh_action' ) ) . '">Exportar CSV</a></div>'
            . '<table class="widefat cmh"><thead><tr><th>Fecha</th><th>Nivel</th><th>Form</th><th>Máquina</th><th>Mensaje</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $cls = $r->level === 'error' ? 'cmh-log-error' : ( $r->level === 'success' ? 'cmh-log-ok' : '' );
            echo '<tr class="' . $cls . '"><td>' . esc_html( $r->created_at ) . '</td><td>' . esc_html( $r->level ) . '</td>'
                . '<td>' . esc_html( $r->form_id ?: '—' ) . '</td><td>' . esc_html( $r->machine_code ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->message ) . '</td></tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="5">' . self::empty_state_inline( 'Sin logs todavía.' ) . '</td></tr>';
        echo '</tbody></table></div>';
        self::page_footer();
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    public static function save_company() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $wpdb->insert( $t['companies'], [ 'name' => strtoupper( sanitize_text_field( $_POST['name'] ) ), 'code' => self::clean_code( $_POST['code'] ) ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies' ), 'Empresa guardada.' );
    }

    public static function save_city() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $cid = intval( $_POST['company_id'] );
        $wpdb->insert( $t['cities'], [ 'company_id' => $cid, 'name' => strtoupper( sanitize_text_field( $_POST['name'] ) ), 'code' => self::clean_code( $_POST['code'] ) ] );
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
        $machine_code = $company->code . ' ' . $city->code . ' ' . $brand_code . ' No.' . $machine_num;

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
            'notes'   => sanitize_textarea_field( $_POST['notes'] ),
            'updated_at' => current_time( 'mysql' ),
        ] );

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

        $wpdb->insert( $t['interventions'], [
            'machine_id'           => $machine_id, 'forminator_form_id' => null,
            'intervention_date'    => sanitize_text_field( $_POST['intervention_date'] ),
            'form_type'            => sanitize_text_field( $_POST['form_type'] ?? 'manual' ),
            'maintenance_type'     => $mtype, 'technician' => sanitize_text_field( $_POST['technician'] ),
            'hourmeter'            => $hourmeter, 'worked_hours' => floatval( $_POST['worked_hours'] ),
            'downtime_hours'       => floatval( $_POST['downtime_hours'] ), 'cost' => floatval( $_POST['cost'] ),
            'affects_availability' => CMH_Metrics::auto_affects_availability( $mtype, $manual_av ),
            'failure_system'       => sanitize_text_field( $_POST['failure_system'] ),
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

        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Intervención guardada.', $hm_warn );
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

        $dir_filter = static function ( $dirs ) use ( $m ) {
            $dirs['subdir'] = '/cm-machine-history/' . $m->machine_code;
            $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        };
        add_filter( 'upload_dir', $dir_filter );
        $file = wp_handle_upload( $_FILES['format_file'], [ 'test_form' => false ] );
        remove_filter( 'upload_dir', $dir_filter );
        if ( isset( $file['error'] ) ) wp_die( $file['error'] );

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

    private static function csv_headers( $filename ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Pragma: no-cache' );
        echo "\xEF\xBB\xBF"; // BOM para Excel
    }

    private static function csv_row( $row ) {
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
        $sql = "SELECT m.machine_code, m.brand, m.model, m.serial, m.contact, c.name empresa, ci.name ciudad, COALESCE(b.name,'') sucursal, m.status, m.current_hourmeter, m.scheduled_hours_monthly, (SELECT COUNT(*) FROM {$t['interventions']} i WHERE i.machine_id=m.id) intervenciones, (SELECT MAX(i.intervention_date) FROM {$t['interventions']} i WHERE i.machine_id=m.id) ultima_intervencion, m.notes FROM {$t['machines']} m JOIN {$t['companies']} c ON c.id=m.company_id JOIN {$t['cities']} ci ON ci.id=m.city_id LEFT JOIN {$t['branches']} b ON b.id=m.branch_id $w ORDER BY m.machine_code";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

        self::csv_headers( 'maquinas-' . date( 'Y-m-d' ) . '.csv' );
        self::csv_row( [ 'Código', 'Marca', 'Modelo', 'Serial', 'Contacto', 'Empresa', 'Ciudad', 'Sucursal', 'Estado', 'Horómetro', 'H.Prog/Mes', 'Intervenciones', 'Última intervención', 'Notas' ] );
        foreach ( $rows as $r ) self::csv_row( array_values( $r ) );
        exit;
    }

    private static function export_interventions_csv() {
        global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_GET['machine_id'] ?? 0 );
        $where  = $machine_id ? $wpdb->prepare( 'WHERE i.machine_id=%d', $machine_id ) : '';
        $rows   = $wpdb->get_results(
            "SELECT i.intervention_date, m.machine_code, i.maintenance_type, i.form_type, i.technician, i.hourmeter, i.worked_hours, i.downtime_hours, i.affects_availability, i.failure_system, i.cost, i.parts, i.services, i.observations FROM {$t['interventions']} i LEFT JOIN {$t['machines']} m ON m.id=i.machine_id $where ORDER BY i.intervention_date DESC, i.id DESC",
            ARRAY_A
        );
        self::csv_headers( 'intervenciones-' . date( 'Y-m-d' ) . '.csv' );
        self::csv_row( [ 'Fecha', 'Máquina', 'Tipo', 'Formato', 'Técnico', 'Horómetro', 'H.Trabajadas', 'H.Parada', 'Afecta Disp.', 'Sistema/Falla', 'Costo', 'Repuestos', 'Servicios', 'Observaciones' ] );
        foreach ( $rows as $r ) self::csv_row( array_values( $r ) );
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
        $id         = intval( $_POST['intervention_id'] );
        $machine_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT machine_id FROM {$t['interventions']} WHERE id=%d", $id ) );
        if ( ! $machine_id ) wp_die( 'Intervención no encontrada.' );

        $mtype     = sanitize_text_field( $_POST['maintenance_type'] );
        $manual_av = isset( $_POST['affects_availability'] ) ? 1 : 0;

        $wpdb->update( $t['interventions'], [
            'intervention_date'    => sanitize_text_field( $_POST['intervention_date'] ),
            'maintenance_type'     => $mtype,
            'technician'           => sanitize_text_field( $_POST['technician'] ),
            'downtime_hours'       => floatval( $_POST['downtime_hours'] ),
            'cost'                 => floatval( $_POST['cost'] ),
            'affects_availability' => CMH_Metrics::auto_affects_availability( $mtype, $manual_av ),
            'observations'         => sanitize_textarea_field( $_POST['observations'] ),
        ], [ 'id' => $id ] );

        self::redirect_to( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ), 'Intervención actualizada.' );
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public static function ajax_get_machine() {
        if ( ! current_user_can( 'read' ) ) wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        global $wpdb; $t = CMH_Core::tables();
        $code = sanitize_text_field( $_GET['code'] ?? '' );
        $m    = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             WHERE m.machine_code=%s OR m.serial=%s",
            $code, $code
        ) );
        if ( ! $m ) wp_send_json_error( [ 'message' => 'Máquina no encontrada.' ] );
        wp_send_json_success( $m );
    }

    public static function ajax_get_machine_public() {
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
        $wpdb->update( $t['companies'], [
            'name' => strtoupper( sanitize_text_field( $_POST['name'] ) ),
            'code' => self::clean_code( $_POST['code'] ),
        ], [ 'id' => $id ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $id ] ), 'Empresa actualizada.' );
    }

    public static function update_city() {
        self::check(); global $wpdb; $t = CMH_Core::tables();
        $id   = intval( $_POST['city_id'] );
        $wpdb->update( $t['cities'], [
            'name' => strtoupper( sanitize_text_field( $_POST['name'] ) ),
            'code' => self::clean_code( $_POST['code'] ),
        ], [ 'id' => $id ] );
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
}
