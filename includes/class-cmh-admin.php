<?php
/**
 * CMH_Admin — menú, páginas de administración y handlers de formularios.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Admin {

    // =========================================================================
    // Inicialización
    // =========================================================================

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );

        foreach ( [ 'company', 'city', 'branch', 'machine', 'intervention' ] as $type ) {
            add_action( 'admin_post_cm_save_' . $type,   [ __CLASS__, 'save_' . $type ] );
        }
        add_action( 'admin_post_cm_upload_file',   [ __CLASS__, 'upload_file' ] );
        add_action( 'admin_post_cm_update_machine',[ __CLASS__, 'update_machine' ] );
        add_action( 'wp_ajax_cmh_get_machine',     [ __CLASS__, 'ajax_get_machine' ] );
    }

    public static function admin_menu() {
        $slug = CMH_SLUG;
        add_menu_page(
            'Historial de Máquinas', 'Máquinas', 'manage_options',
            $slug, [ __CLASS__, 'page_dashboard' ], 'dashicons-hammer', 26
        );
        add_submenu_page( $slug, 'Dashboard',       'Dashboard',       'manage_options', $slug,                   [ __CLASS__, 'page_dashboard' ] );
        add_submenu_page( $slug, 'Empresas',        'Empresas',        'manage_options', $slug . '-companies',    [ __CLASS__, 'page_companies' ] );
        add_submenu_page( $slug, 'Buscar máquinas', 'Buscar máquinas', 'manage_options', $slug . '-machines',     [ __CLASS__, 'page_machines' ] );
        add_submenu_page( $slug, 'Integración',     'Integración',     'manage_options', $slug . '-integration',  [ __CLASS__, 'page_integration' ] );
    }

    public static function assets( $hook ) {
        if ( strpos( $hook, CMH_SLUG ) === false ) return;
        wp_enqueue_style(
            'cmh-admin', CMH_URL . 'assets/admin.css', [], CMH_VERSION
        );
        wp_enqueue_script(
            'cmh-admin', CMH_URL . 'assets/admin.js', [ 'jquery' ], CMH_VERSION, true
        );
    }

    // =========================================================================
    // Helpers globales
    // =========================================================================

    public static function admin_url( $page, $args = [] ) {
        return admin_url( 'admin.php?page=' . $page . ( $args ? '&' . http_build_query( $args ) : '' ) );
    }

    public static function check() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'cmh_action' );
    }

    public static function notice() {
        if ( ! empty( $_GET['cmh_msg'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $_GET['cmh_msg'] ) . '</p></div>';
        }
        if ( ! empty( $_GET['cmh_warn'] ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $_GET['cmh_warn'] ) . '</p></div>';
        }
    }

    public static function clean_code( $v ) {
        return strtoupper( preg_replace( '/[^A-Z0-9]/', '', remove_accents( $v ) ) );
    }

    public static function brand_code( $brand ) {
        static $map = [
            'TOYOTA'   => 'TY', 'CROWN'    => 'CR', 'HYSTER'   => 'HY',
            'HANGCHA'  => 'HC', 'YALE'     => 'YA', 'LINDE'    => 'LD',
            'KOMATSU'  => 'KM', 'NISSAN'   => 'NS', 'CATERPILLAR' => 'CAT',
            'MITSUBISHI' => 'MI', 'STILL'  => 'ST', 'JUNGHEINRICH' => 'JH',
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
                if ( $i ) echo '<span aria-hidden="true">›</span>';
                echo ! empty( $c['url'] )
                    ? '<a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['label'] ) . '</a>'
                    : '<span aria-current="page">' . esc_html( $c['label'] ) . '</span>';
            }
            echo '</nav>';
        }
    }

    public static function page_footer() {
        echo '</div>';
    }

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
        wp_safe_redirect( $to );
        exit;
    }

    public static function status_badge( $status ) {
        $status = sanitize_key( $status ?: 'activa' );
        $labels = [
            'activa'         => 'Activa',
            'mantenimiento'  => 'En mantenimiento',
            'inactiva'       => 'Inactiva',
            'fuera_servicio' => 'Fuera de servicio',
        ];
        $label = $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
        return '<span class="cmh-badge cmh-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
    }

    public static function metric_card( $label, $value, $hint = '' ) {
        echo '<div class="cmh-card">'
            . '<span>' . esc_html( $label ) . '</span>'
            . '<strong>' . esc_html( (string) $value ) . '</strong>'
            . ( $hint !== '' ? '<small>' . esc_html( $hint ) . '</small>' : '' )
            . '</div>';
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public static function page_dashboard() {
        global $wpdb;
        $t     = CMH_Core::tables();
        $month = (int) current_time( 'n' );
        $year  = (int) current_time( 'Y' );

        // Totales generales
        $machines     = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$t['machines']}" );
        $interventions= (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$t['interventions']}" );
        $preventivos  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$t['interventions']} WHERE maintenance_type='preventivo'" );
        $correctivos  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$t['interventions']} WHERE maintenance_type IN('correctivo','averia')" );
        $cost_total   = (float) $wpdb->get_var( "SELECT COALESCE(SUM(cost),0) FROM {$t['interventions']}" );

        // KPIs mes actual
        $fleet_avail = CMH_Metrics::fleet_availability( $month, $year );
        $fleet_mttr  = CMH_Metrics::mttr( 0, $month, $year );
        $month_dt    = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(downtime_hours),0) FROM {$t['interventions']}
             WHERE affects_availability=1 AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d",
            $month, $year
        ) );
        $month_label = CMH_Metrics::month_label( $month, $year );

        // Máquinas críticas
        $critical = CMH_Metrics::critical_machines();

        self::page_header( 'Dashboard de máquinas' );

        // Hero
        echo '<div class="cmh-dashboard-hero">';
        echo '<div><h2>Resumen operativo</h2><p>Vista general de máquinas, mantenimientos, disponibilidad y costos registrados.</p></div>';
        echo '<a class="button button-primary" href="' . esc_url( self::admin_url( CMH_SLUG . '-companies' ) ) . '">Gestionar empresas</a>';
        echo '</div>';

        // KPI cards
        echo '<div class="cmh-grid">';
        self::metric_card( 'Máquinas', $machines, 'registradas' );
        self::metric_card( 'Intervenciones', $interventions, 'historial total' );
        self::metric_card( 'Preventivos', $preventivos, 'historial total' );
        self::metric_card( 'Correctivos / Averías', $correctivos, 'historial total' );
        self::metric_card( 'Disponibilidad ' . $month_label, CMH_Metrics::fmt_pct( $fleet_avail ), 'flota completa' );
        self::metric_card( 'MTTR ' . $month_label, CMH_Metrics::fmt_mttr( $fleet_mttr ), 'solo averías' );
        self::metric_card( 'Horas parada ' . $month_label, number_format( $month_dt, 2, ',', '.' ) . ' h', 'por averías' );
        self::metric_card( 'Costo total', '$' . number_format( $cost_total, 0, ',', '.' ), 'historial' );
        echo '</div>';

        // Máquinas críticas
        if ( $critical ) {
            echo '<div class="cmh-panel cmh-panel-critical"><h2>Atención — Máquinas críticas este mes</h2>';
            echo '<p class="description">Máquinas con disponibilidad &lt; 70% o 3+ averías en ' . esc_html( $month_label ) . '.</p>';
            echo '<table class="widefat striped"><thead><tr>'
                . '<th>Código</th><th>Equipo</th><th>Ubicación</th>'
                . '<th>Disponibilidad</th><th>Averías</th><th>Motivo</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ( $critical as $cr ) {
                $avail_class = $cr['availability'] < 50 ? 'cmh-avail-danger' : 'cmh-avail-warn';
                echo '<tr>'
                    . '<td><strong>' . esc_html( $cr['machine_code'] ) . '</strong></td>'
                    . '<td>' . esc_html( $cr['brand_model'] ) . '</td>'
                    . '<td>' . esc_html( $cr['company_city'] ) . '</td>'
                    . '<td><span class="cmh-avail-badge ' . $avail_class . '">' . esc_html( CMH_Metrics::fmt_pct( $cr['availability'] ) ) . '</span></td>'
                    . '<td>' . intval( $cr['averia_count'] ) . '</td>'
                    . '<td>' . esc_html( $cr['reason'] ) . '</td>'
                    . '<td><a class="button button-small" href="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $cr['id'] ] ) ) . '">Ver</a></td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
        }

        // Layout principal
        echo '<div class="cmh-layout">';
        echo '<div class="cmh-main"><div class="cmh-panel"><h2>Últimas intervenciones</h2>';
        self::interventions_table( 10 );
        echo '</div></div>';
        echo '<div class="cmh-side"><div class="cmh-panel"><h2>Máquinas recientes</h2>';
        self::machines_mini_table();
        echo '</div><div class="cmh-panel"><h2>Integración</h2>'
            . '<p>Forminator crea intervenciones y E2PDF asocia PDFs automáticamente.</p>'
            . '<a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-integration' ) ) . '">Ver logs</a>'
            . '</div></div>';
        echo '</div>';

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

        global $wpdb;
        $t = CMH_Core::tables();
        self::page_header( 'Empresas', [ [ 'label' => 'Empresas' ] ] );

        echo '<div class="cmh-layout"><div class="cmh-main"><div class="cmh-panel"><h2>Empresas registradas</h2>'
            . '<table class="widefat striped"><thead><tr>'
            . '<th>Empresa</th><th>Código</th><th>Ciudades</th><th>Máquinas</th><th></th>'
            . '</tr></thead><tbody>';

        $rows = $wpdb->get_results(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM {$t['cities']}   ci WHERE ci.company_id=c.id) cities,
                    (SELECT COUNT(*) FROM {$t['machines']} m  WHERE m.company_id=c.id)  machines
             FROM {$t['companies']} c ORDER BY c.name"
        );
        foreach ( $rows as $r ) {
            echo '<tr><td><strong>' . esc_html( $r->name ) . '</strong></td>'
                . '<td><code>' . esc_html( $r->code ) . '</code></td>'
                . '<td>' . intval( $r->cities ) . '</td>'
                . '<td>' . intval( $r->machines ) . '</td>'
                . '<td><a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $r->id ] ) ) . '">Entrar</a></td></tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="5">Aún no hay empresas.</td></tr>';
        echo '</tbody></table></div></div>';

        echo '<div class="cmh-side"><div class="cmh-panel"><h2>Nueva empresa</h2>';
        self::form_start( 'cm_save_company' );
        echo '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies' ) ) . '">'
            . '<label>Nombre <em>*</em></label><input name="name" required>'
            . '<label>Código corto <em>*</em></label><input name="code" placeholder="APC" maxlength="10" required>'
            . '<p class="description">Se usará en el código de máquina: APC-BOG-TY-001.</p>'
            . '<button class="button button-primary">Guardar empresa</button></form>';
        echo '</div></div></div>';
        self::page_footer();
    }

    public static function page_company( $company_id ) {
        global $wpdb;
        $t = CMH_Core::tables();
        $c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['companies']} WHERE id=%d", $company_id ) );
        if ( ! $c ) wp_die( 'Empresa no encontrada.' );

        self::page_header( $c->name, [
            [ 'label' => 'Empresas', 'url' => self::admin_url( CMH_SLUG . '-companies' ) ],
            [ 'label' => $c->name ],
        ] );
        echo '<div class="cmh-panel cmh-hero"><h2>' . esc_html( $c->name ) . '</h2>'
            . '<p>Código: <strong>' . esc_html( $c->code ) . '</strong></p></div>';

        echo '<div class="cmh-layout"><div class="cmh-main"><div class="cmh-panel"><h2>Ciudades</h2>'
            . '<table class="widefat striped"><thead><tr>'
            . '<th>Ciudad</th><th>Código</th><th>Sucursales</th><th>Máquinas</th><th></th>'
            . '</tr></thead><tbody>';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ci.*,
                    (SELECT COUNT(*) FROM {$t['branches']} b WHERE b.city_id=ci.id)  branches,
                    (SELECT COUNT(*) FROM {$t['machines']} m WHERE m.city_id=ci.id)   machines
             FROM {$t['cities']} ci WHERE ci.company_id=%d ORDER BY ci.name", $company_id
        ) );
        foreach ( $rows as $r ) {
            echo '<tr><td><strong>' . esc_html( $r->name ) . '</strong></td>'
                . '<td><code>' . esc_html( $r->code ) . '</code></td>'
                . '<td>' . intval( $r->branches ) . '</td>'
                . '<td>' . intval( $r->machines ) . '</td>'
                . '<td><a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $r->id ] ) ) . '">Entrar</a></td></tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="5">Esta empresa aún no tiene ciudades.</td></tr>';
        echo '</tbody></table></div></div>';

        echo '<div class="cmh-side"><div class="cmh-panel"><h2>Nueva ciudad</h2>';
        self::form_start( 'cm_save_city' );
        echo '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ) ) . '">'
            . '<label>Ciudad <em>*</em></label><input name="name" placeholder="Bogotá" required>'
            . '<label>Código <em>*</em></label><input name="code" placeholder="BOG" maxlength="10" required>'
            . '<button class="button button-primary">Guardar ciudad</button></form>';
        echo '</div></div></div>';
        self::page_footer();
    }

    public static function page_city( $city_id ) {
        global $wpdb;
        $t    = CMH_Core::tables();
        $city = $wpdb->get_row( $wpdb->prepare(
            "SELECT ci.*, c.name company_name, c.code company_code, c.id company_id
             FROM {$t['cities']} ci JOIN {$t['companies']} c ON c.id=ci.company_id
             WHERE ci.id=%d", $city_id
        ) );
        if ( ! $city ) wp_die( 'Ciudad no encontrada.' );

        self::page_header( $city->name, [
            [ 'label' => 'Empresas',          'url' => self::admin_url( CMH_SLUG . '-companies' ) ],
            [ 'label' => $city->company_name, 'url' => self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $city->company_id ] ) ],
            [ 'label' => $city->name ],
        ] );

        echo '<div class="cmh-panel cmh-hero"><h2>' . esc_html( $city->company_name . ' / ' . $city->name ) . '</h2>'
            . '<p>Código ciudad: <strong>' . esc_html( $city->code ) . '</strong></p></div>';

        echo '<div class="cmh-layout"><div class="cmh-main">';

        // Sucursales
        $branches = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, (SELECT COUNT(*) FROM {$t['machines']} m WHERE m.branch_id=b.id) machines
             FROM {$t['branches']} b WHERE b.city_id=%d ORDER BY b.name", $city_id
        ) );
        if ( $branches ) {
            echo '<div class="cmh-panel"><h2>Sucursales</h2>'
                . '<table class="widefat striped"><thead><tr>'
                . '<th>Sucursal</th><th>Código</th><th>Dirección</th><th>Máquinas</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ( $branches as $b ) {
                echo '<tr><td><strong>' . esc_html( $b->name ) . '</strong></td>'
                    . '<td><code>' . esc_html( $b->code ) . '</code></td>'
                    . '<td>' . esc_html( $b->address ) . '</td>'
                    . '<td>' . intval( $b->machines ) . '</td>'
                    . '<td><a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'branch_id' => $b->id ] ) ) . '">Entrar</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Máquinas de esta ciudad (todas, con o sin sucursal)
        echo '<div class="cmh-panel"><h2>Máquinas en esta ciudad</h2>';
        self::machines_table( $city_id, 0 );
        echo '</div></div>';

        // Sidebar: formularios
        echo '<div class="cmh-side">';
        echo '<div class="cmh-panel"><h2>Agregar máquina</h2>';
        self::machine_form( $city->company_id, $city_id );
        echo '</div>';
        echo '<div class="cmh-panel"><h2>Nueva sucursal</h2>';
        self::form_start( 'cm_save_branch' );
        echo '<input type="hidden" name="company_id" value="' . intval( $city->company_id ) . '">'
            . '<input type="hidden" name="city_id" value="' . intval( $city_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] ) ) . '">'
            . '<label>Nombre <em>*</em></label><input name="name" required>'
            . '<label>Código <em>*</em></label><input name="code" placeholder="FAC" maxlength="10" required>'
            . '<label>Dirección</label><textarea name="address"></textarea>'
            . '<button class="button button-primary">Guardar sucursal</button></form>';
        echo '</div></div></div>';
        self::page_footer();
    }

    public static function page_branch( $branch_id ) {
        global $wpdb;
        $t = CMH_Core::tables();
        $b = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, ci.name city_name, ci.id city_id, c.name company_name, c.id company_id
             FROM {$t['branches']} b
             JOIN {$t['cities']} ci ON ci.id=b.city_id
             JOIN {$t['companies']} c ON c.id=b.company_id
             WHERE b.id=%d", $branch_id
        ) );
        if ( ! $b ) wp_die( 'Sucursal no encontrada.' );

        self::page_header( $b->name, [
            [ 'label' => 'Empresas',        'url' => self::admin_url( CMH_SLUG . '-companies' ) ],
            [ 'label' => $b->company_name,  'url' => self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $b->company_id ] ) ],
            [ 'label' => $b->city_name,     'url' => self::admin_url( CMH_SLUG . '-companies', [ 'city_id'    => $b->city_id    ] ) ],
            [ 'label' => $b->name ],
        ] );

        echo '<div class="cmh-panel cmh-hero"><h2>' . esc_html( $b->company_name . ' / ' . $b->city_name . ' / ' . $b->name ) . '</h2>'
            . '<p>Código sucursal: <strong>' . esc_html( $b->code ) . '</strong></p></div>';

        echo '<div class="cmh-layout"><div class="cmh-main"><div class="cmh-panel"><h2>Máquinas en esta sucursal</h2>';
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

        echo '<div class="cmh-panel"><h2>Filtros</h2>'
            . '<form method="get" class="cmh-filterbar">'
            . '<input type="hidden" name="page" value="' . esc_attr( CMH_SLUG . '-machines' ) . '">'
            . '<label>Buscar<input name="q" value="' . esc_attr( $q ) . '" placeholder="Código, serial, marca, modelo o contacto"></label>'
            . '<label>Estado<select name="status"><option value="">Todos</option>';
        foreach ( [ 'activa' => 'Activa', 'mantenimiento' => 'En mantenimiento', 'inactiva' => 'Inactiva', 'fuera_servicio' => 'Fuera de servicio' ] as $k => $v ) {
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $status, $k, false ) . '>' . esc_html( $v ) . '</option>';
        }
        echo '</select></label>'
            . '<button class="button button-primary">Filtrar</button>'
            . '<a class="button" href="' . esc_url( self::admin_url( CMH_SLUG . '-machines' ) ) . '">Limpiar</a>'
            . '</form></div>';

        echo '<div class="cmh-panel"><h2>Resultados</h2>';
        self::machines_table( 0, 0, [ 'q' => $q, 'status' => $status ] );
        echo '</div>';
        self::page_footer();
    }

    public static function page_machine( $machine_id ) {
        global $wpdb;
        $t = CMH_Core::tables();
        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, c.name company_name, c.id company_id,
                    ci.name city_name, ci.id city_id,
                    b.name branch_name, b.id branch_id_val
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id  = m.company_id
             JOIN {$t['cities']}   ci ON ci.id  = m.city_id
             LEFT JOIN {$t['branches']} b ON b.id = m.branch_id
             WHERE m.id=%d", $machine_id
        ) );
        if ( ! $m ) wp_die( 'Máquina no encontrada.' );

        // Breadcrumbs dinámicos según si tiene sucursal
        $crumbs = [
            [ 'label' => 'Empresas',       'url' => self::admin_url( CMH_SLUG . '-companies' ) ],
            [ 'label' => $m->company_name, 'url' => self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $m->company_id ] ) ],
            [ 'label' => $m->city_name,    'url' => self::admin_url( CMH_SLUG . '-companies', [ 'city_id'    => $m->city_id    ] ) ],
        ];
        if ( $m->branch_id ) {
            $crumbs[] = [ 'label' => $m->branch_name, 'url' => self::admin_url( CMH_SLUG . '-companies', [ 'branch_id' => $m->branch_id ] ) ];
        }
        $crumbs[] = [ 'label' => $m->machine_code ];

        self::page_header( 'Hoja de vida — ' . $m->machine_code, $crumbs );

        // Stats globales de la máquina
        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(CASE WHEN affects_availability=1 THEN downtime_hours ELSE 0 END),0) downtime_averia,
                    COALESCE(SUM(CASE WHEN affects_availability=0 THEN downtime_hours ELSE 0 END),0) downtime_maintenance,
                    COALESCE(SUM(cost),0) cost,
                    COALESCE(SUM(worked_hours),0) worked,
                    SUM(CASE WHEN maintenance_type='preventivo' THEN 1 ELSE 0 END) preventivos,
                    SUM(CASE WHEN maintenance_type IN('correctivo','averia') THEN 1 ELSE 0 END) correctivos
             FROM {$t['interventions']} WHERE machine_id=%d", $machine_id
        ) );
        $last = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['interventions']} WHERE machine_id=%d ORDER BY intervention_date DESC, id DESC LIMIT 1",
            $machine_id
        ) );

        $month      = (int) current_time( 'n' );
        $year       = (int) current_time( 'Y' );
        $avail_now  = CMH_Metrics::availability( $machine_id, $month, $year );
        $mttr_now   = CMH_Metrics::mttr( $machine_id, $month, $year );
        $averia_now = CMH_Metrics::averia_count( $machine_id, $month, $year );
        $is_crit    = CMH_Metrics::is_critical( $machine_id );

        // Hero
        echo '<div class="cmh-machine-hero">'
            . '<div>'
            . '<div class="cmh-kicker">Hoja de vida técnica</div>'
            . '<h2>' . esc_html( $m->machine_code ) . ' ' . self::status_badge( $m->status )
            . ( $is_crit ? ' <span class="cmh-badge cmh-badge-critical">Crítica</span>' : '' ) . '</h2>'
            . '<p>' . esc_html( $m->company_name . ' / ' . $m->city_name . ( $m->branch_id ? ' / ' . $m->branch_name : '' ) )
            . ' &nbsp;·&nbsp; ' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</p>'
            . '</div>'
            . '<div class="cmh-hero-actions">'
            . '<a class="button" href="' . esc_url( $m->branch_id
                ? self::admin_url( CMH_SLUG . '-companies', [ 'branch_id' => $m->branch_id ] )
                : self::admin_url( CMH_SLUG . '-companies', [ 'city_id'   => $m->city_id   ] ) ) . '">Volver</a>'
            . '</div></div>';

        // KPI cards
        $averia_count_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['interventions']} WHERE machine_id=%d AND affects_availability=1", $machine_id
        ) );
        $mttr_all = CMH_Metrics::mttr( $machine_id );

        echo '<div class="cmh-grid">';
        self::metric_card( 'Intervenciones',     $stats->total );
        self::metric_card( 'Preventivos',        (int) $stats->preventivos );
        self::metric_card( 'Correctivos/Averías',(int) $stats->correctivos );
        self::metric_card( 'H. parada averías',  number_format( (float)$stats->downtime_averia, 2, ',', '.' ) . ' h', 'historial' );
        self::metric_card( 'Disponibilidad ' . CMH_Metrics::month_label( $month, $year ), CMH_Metrics::fmt_pct( $avail_now ), 'mes actual' );
        self::metric_card( 'MTTR',               CMH_Metrics::fmt_mttr( $mttr_all ), 'historial' );
        self::metric_card( 'Costo total',        '$' . number_format( (float)$stats->cost, 0, ',', '.' ) );
        self::metric_card( 'Horómetro actual',   number_format( (float)$m->current_hourmeter, 2, ',', '.' ) . ' h' );
        echo '</div>';

        // Tabs
        echo '<nav class="cmh-tabs" role="tablist">'
            . '<a href="#tab-resumen"       class="cmh-tab" data-tab="resumen">Resumen</a>'
            . '<a href="#tab-interv"        class="cmh-tab" data-tab="interv">Intervenciones</a>'
            . '<a href="#tab-disponib"      class="cmh-tab" data-tab="disponib">Disponibilidad</a>'
            . '<a href="#tab-pdfs"          class="cmh-tab" data-tab="pdfs">PDFs</a>'
            . '<a href="#tab-editar"        class="cmh-tab" data-tab="editar">Editar</a>'
            . '</nav>';

        // Pasar horómetro actual a JS para validación
        wp_localize_script( 'cmh-admin', 'CMH', [
            'lastHourmeter' => (float) $m->current_hourmeter,
        ] );

        echo '<div class="cmh-layout"><div class="cmh-main">';

        // Panel: Resumen
        echo '<div id="tab-resumen" class="cmh-tab-panel cmh-panel"><h2>Resumen de la máquina</h2>'
            . '<div class="cmh-info-grid">'
            . '<div><span>Código</span><strong>' . esc_html( $m->machine_code ) . '</strong></div>'
            . '<div><span>Marca / Modelo</span><strong>' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</strong></div>'
            . '<div><span>Serial</span><strong>' . esc_html( $m->serial ?: '—' ) . '</strong></div>'
            . '<div><span>Contacto</span><strong>' . esc_html( $m->contact ?: '—' ) . '</strong></div>'
            . '<div><span>H. programadas/mes</span><strong>' . esc_html( number_format( (float)$m->scheduled_hours_monthly, 0, ',', '.' ) ) . ' h</strong></div>'
            . '<div><span>Última intervención</span><strong>' . esc_html( $last ? $last->intervention_date : '—' ) . '</strong></div>'
            . '<div><span>Último técnico</span><strong>' . esc_html( $last && $last->technician ? $last->technician : '—' ) . '</strong></div>'
            . '<div><span>Averías este mes</span><strong>' . intval( $averia_now ) . '</strong></div>'
            . '</div>'
            . ( $m->notes ? '<div class="cmh-note"><strong>Notas:</strong> ' . esc_html( $m->notes ) . '</div>' : '' )
            . '</div>';

        // Panel: Intervenciones
        echo '<div id="tab-interv" class="cmh-tab-panel cmh-panel"><h2>Timeline de intervenciones</h2>';
        self::intervention_cards( $machine_id );
        echo '</div>';

        // Panel: Disponibilidad mensual
        echo '<div id="tab-disponib" class="cmh-tab-panel cmh-panel"><h2>Disponibilidad mensual</h2>';
        self::availability_table( $machine_id );
        echo '</div>';

        // Panel: PDFs
        echo '<div id="tab-pdfs" class="cmh-tab-panel cmh-panel"><h2>Archivos y formatos PDF</h2>';
        self::files_table( $machine_id );
        echo '</div>';

        // Panel: Editar
        echo '<div id="tab-editar" class="cmh-tab-panel cmh-panel"><h2>Editar datos de la máquina</h2>';
        self::edit_machine_form( $m );
        echo '</div>';

        echo '</div><div class="cmh-side">';
        echo '<div class="cmh-panel"><h2>Registrar intervención</h2>';
        self::intervention_form( $machine_id, (float) $m->current_hourmeter );
        echo '</div>';
        echo '<div class="cmh-panel"><h2>Anexar PDF / archivo</h2>';
        self::upload_form( $machine_id );
        echo '</div></div></div>';

        self::page_footer();
    }

    // =========================================================================
    // Componentes de UI — Tablas y formularios
    // =========================================================================

    public static function machines_table( $city_id = 0, $branch_id = 0, $filters = [] ) {
        global $wpdb;
        $t      = CMH_Core::tables();
        $where  = [];
        $params = [];

        if ( $city_id )   { $where[] = 'm.city_id=%d';   $params[] = $city_id; }
        if ( $branch_id ) { $where[] = 'm.branch_id=%d'; $params[] = $branch_id; }

        if ( ! empty( $filters['q'] ) ) {
            $like    = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
            $where[] = '(m.machine_code LIKE %s OR m.serial LIKE %s OR m.brand LIKE %s OR m.model LIKE %s OR m.contact LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like );
        }
        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'm.status=%s';
            $params[] = $filters['status'];
        }

        $w   = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql = "SELECT m.*, c.name company_name, ci.name city_name,
                       COALESCE(b.name,'—') branch_name,
                       (SELECT COUNT(*) FROM {$t['interventions']} i WHERE i.machine_id=m.id) interventions,
                       (SELECT MAX(i.intervention_date) FROM {$t['interventions']} i WHERE i.machine_id=m.id) last_intervention
                FROM {$t['machines']} m
                JOIN {$t['companies']} c  ON c.id  = m.company_id
                JOIN {$t['cities']}   ci ON ci.id  = m.city_id
                LEFT JOIN {$t['branches']} b ON b.id = m.branch_id
                $w ORDER BY m.machine_code";

        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

        echo '<table class="widefat striped cmh-machine-table"><thead><tr>'
            . '<th>Código</th><th>Marca / Modelo</th><th>Serial</th><th>Ubicación</th>'
            . '<th>Horómetro</th><th>Estado</th><th>Interv.</th><th>Última</th><th></th>'
            . '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $location = $r->company_name . ' / ' . $r->city_name;
            if ( $r->branch_name !== '—' ) $location .= ' / ' . $r->branch_name;
            echo '<tr>'
                . '<td><strong>' . esc_html( $r->machine_code ) . '</strong></td>'
                . '<td>' . esc_html( trim( $r->brand . ' ' . $r->model ) ) . '</td>'
                . '<td>' . esc_html( $r->serial ?: '—' ) . '</td>'
                . '<td>' . esc_html( $location ) . '</td>'
                . '<td>' . esc_html( $r->current_hourmeter ) . ' h</td>'
                . '<td>' . self::status_badge( $r->status ) . '</td>'
                . '<td>' . intval( $r->interventions ) . '</td>'
                . '<td>' . esc_html( $r->last_intervention ?: '—' ) . '</td>'
                . '<td><a class="button button-small" href="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $r->id ] ) ) . '">Hoja de vida</a></td>'
                . '</tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="9">No hay máquinas con esos filtros.</td></tr>';
        echo '</tbody></table>';
    }

    public static function machines_mini_table() {
        global $wpdb;
        $t    = CMH_Core::tables();
        $rows = $wpdb->get_results( "SELECT id, machine_code, brand, model, current_hourmeter, status FROM {$t['machines']} ORDER BY id DESC LIMIT 8" );
        echo '<div class="cmh-mini-list">';
        foreach ( $rows as $r ) {
            echo '<a href="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $r->id ] ) ) . '">'
                . '<strong>' . esc_html( $r->machine_code ) . '</strong>'
                . '<span>' . esc_html( trim( $r->brand . ' ' . $r->model ) ) . ' · H: ' . esc_html( $r->current_hourmeter ) . '</span>'
                . '</a>';
        }
        if ( ! $rows ) echo '<p>Aún no hay máquinas.</p>';
        echo '</div>';
    }

    /** Formulario de nueva máquina. $branch_id=0 significa que la sucursal es opcional. */
    public static function machine_form( $company_id, $city_id, $branch_id = 0 ) {
        global $wpdb;
        $t        = CMH_Core::tables();
        $branches = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$t['branches']} WHERE city_id=%d ORDER BY name", $city_id
        ) );
        $redirect = $branch_id
            ? self::admin_url( CMH_SLUG . '-companies', [ 'branch_id' => $branch_id ] )
            : self::admin_url( CMH_SLUG . '-companies', [ 'city_id'   => $city_id   ] );

        self::form_start( 'cm_save_machine' );
        echo '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
            . '<input type="hidden" name="city_id"   value="' . intval( $city_id )    . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect ) . '">';

        if ( $branch_id ) {
            echo '<input type="hidden" name="branch_id" value="' . intval( $branch_id ) . '">';
        } elseif ( $branches ) {
            echo '<label>Sucursal <small class="cmh-optional">(opcional)</small></label>'
                . '<select name="branch_id"><option value="">— Sin sucursal —</option>';
            foreach ( $branches as $b ) {
                echo '<option value="' . intval( $b->id ) . '">' . esc_html( $b->name ) . '</option>';
            }
            echo '</select>';
        }

        echo '<div class="cmh-form-grid">'
            . '<label>Marca <em>*</em><input name="brand" placeholder="Toyota" required></label>'
            . '<label>Modelo<input name="model" placeholder="8FGU25"></label>'
            . '<label>Serial<input name="serial"></label>'
            . '<label>Contacto<input name="contact"></label>'
            . '<label>Horómetro actual<input type="number" step="0.01" name="current_hourmeter" value="0" min="0"></label>'
            . '<label>H. programadas / mes'
            . ' <span class="cmh-tooltip" title="Horas de turno mensual de la máquina. Afecta el cálculo de disponibilidad.">[?]</span>'
            . '<input type="number" step="1" name="scheduled_hours_monthly" value="480" min="1" required></label>'
            . '</div>'
            . '<label>Estado<select name="status">'
            . '<option value="activa">Activa</option>'
            . '<option value="mantenimiento">En mantenimiento</option>'
            . '<option value="inactiva">Inactiva</option>'
            . '</select></label>'
            . '<label>Notas<textarea name="notes"></textarea></label>'
            . '<button class="button button-primary">Guardar máquina</button></form>';
    }

    public static function edit_machine_form( $m ) {
        global $wpdb;
        $t        = CMH_Core::tables();
        $branches = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$t['branches']} WHERE city_id=%d ORDER BY name", $m->city_id
        ) );

        self::form_start( 'cm_update_machine' );
        echo '<input type="hidden" name="machine_id" value="' . intval( $m->id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $m->id ] ) ) . '">';

        echo '<div class="cmh-form-grid">'
            . '<label>Marca <em>*</em><input name="brand" value="' . esc_attr( $m->brand ) . '" required></label>'
            . '<label>Modelo<input name="model" value="' . esc_attr( $m->model ) . '"></label>'
            . '<label>Serial<input name="serial" value="' . esc_attr( $m->serial ) . '"></label>'
            . '<label>Contacto<input name="contact" value="' . esc_attr( $m->contact ) . '"></label>'
            . '<label>Horómetro actual<input type="number" step="0.01" name="current_hourmeter" value="' . esc_attr( $m->current_hourmeter ) . '" data-prev-hourmeter="' . esc_attr( $m->current_hourmeter ) . '"></label>'
            . '<label>H. programadas / mes<input type="number" step="1" name="scheduled_hours_monthly" value="' . esc_attr( $m->scheduled_hours_monthly ) . '" min="1" required></label>'
            . '</div>'
            . '<label>Estado<select name="status">';
        foreach ( [ 'activa' => 'Activa', 'mantenimiento' => 'En mantenimiento', 'inactiva' => 'Inactiva', 'fuera_servicio' => 'Fuera de servicio' ] as $k => $v ) {
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $m->status, $k, false ) . '>' . esc_html( $v ) . '</option>';
        }
        echo '</select></label>';

        if ( $branches ) {
            echo '<label>Sucursal <small class="cmh-optional">(opcional)</small><select name="branch_id">'
                . '<option value="">— Sin sucursal —</option>';
            foreach ( $branches as $b ) {
                echo '<option value="' . intval( $b->id ) . '" ' . selected( $m->branch_id, $b->id, false ) . '>' . esc_html( $b->name ) . '</option>';
            }
            echo '</select></label>';
        }

        echo '<label>Notas<textarea name="notes">' . esc_textarea( $m->notes ) . '</textarea></label>'
            . '<button class="button button-primary">Guardar cambios</button></form>';
    }

    public static function interventions_table( $limit = 20, $machine_id = 0 ) {
        global $wpdb;
        $t     = CMH_Core::tables();
        $where = $machine_id ? $wpdb->prepare( 'WHERE i.machine_id=%d', $machine_id ) : '';
        $rows  = $wpdb->get_results(
            "SELECT i.*, m.machine_code, f.file_url
             FROM {$t['interventions']} i
             LEFT JOIN {$t['machines']} m ON m.id=i.machine_id
             LEFT JOIN {$t['files']}   f ON f.intervention_id=i.id
             $where GROUP BY i.id ORDER BY i.intervention_date DESC, i.id DESC LIMIT " . intval( $limit )
        );

        echo '<table class="widefat striped cmh-table"><thead><tr>'
            . '<th>Fecha</th><th>Máquina</th><th>Tipo</th><th>Técnico</th>'
            . '<th>Formato</th><th>H. parada</th><th>Costo</th><th>PDF</th>'
            . '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr>'
                . '<td>' . esc_html( $r->intervention_date ) . '</td>'
                . '<td>' . esc_html( $r->machine_code ) . '</td>'
                . '<td>' . esc_html( ucfirst( $r->maintenance_type ?: $r->form_type ) ) . '</td>'
                . '<td>' . esc_html( $r->technician ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->form_type ) . '</td>'
                . '<td>' . esc_html( $r->downtime_hours ) . ' h</td>'
                . '<td>$' . number_format( (float) $r->cost, 0, ',', '.' ) . '</td>'
                . '<td>' . ( $r->file_url ? '<a target="_blank" href="' . esc_url( $r->file_url ) . '">Ver</a>' : '—' ) . '</td>'
                . '</tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="8">No hay intervenciones.</td></tr>';
        echo '</tbody></table>';
    }

    public static function intervention_cards( $machine_id ) {
        global $wpdb;
        $t    = CMH_Core::tables();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, f.file_url, f.file_name
             FROM {$t['interventions']} i
             LEFT JOIN {$t['files']} f ON f.intervention_id=i.id
             WHERE i.machine_id=%d ORDER BY i.intervention_date DESC, i.id DESC LIMIT 150",
            $machine_id
        ) );

        echo '<div class="cmh-timeline">';
        foreach ( $rows as $r ) {
            $type_label = ucfirst( $r->maintenance_type ?: $r->form_type );
            $av_label   = $r->affects_availability ? '<span class="cmh-badge cmh-badge-averia">Descuenta disponibilidad</span>' : '';
            echo '<div class="cmh-timeline-item">'
                . '<div class="cmh-dot"></div>'
                . '<div class="cmh-timeline-card">'
                . '<div class="cmh-timeline-head"><strong>' . esc_html( $type_label ) . ' ' . $av_label . '</strong><span>' . esc_html( $r->intervention_date ) . '</span></div>'
                . '<div class="cmh-meta">'
                . '<span>Formato: ' . esc_html( $r->form_type ) . '</span>'
                . '<span>Técnico: ' . esc_html( $r->technician ?: '—' ) . '</span>'
                . '<span>Horómetro: ' . esc_html( $r->hourmeter ) . ' h</span>'
                . '<span>H. parada: ' . esc_html( $r->downtime_hours ) . ' h</span>'
                . ( $r->failure_system ? '<span>Sistema: ' . esc_html( $r->failure_system ) . '</span>' : '' )
                . '</div>';
            if ( $r->services )     echo '<p><strong>Servicios:</strong> '    . esc_html( wp_trim_words( $r->services,     32 ) ) . '</p>';
            if ( $r->observations ) echo '<p><strong>Observaciones:</strong> ' . esc_html( wp_trim_words( $r->observations, 32 ) ) . '</p>';
            echo '<div class="cmh-card-actions">';
            if ( $r->file_url ) echo '<a class="button button-small" target="_blank" href="' . esc_url( $r->file_url ) . '">Ver PDF</a>';
            echo '<span class="cmh-id-hint">ID: ' . intval( $r->id ) . '</span></div>'
                . '</div></div>';
        }
        if ( ! $rows ) echo '<p>No hay intervenciones todavía.</p>';
        echo '</div>';
    }

    public static function availability_table( $machine_id ) {
        $breakdown = CMH_Metrics::monthly_breakdown( $machine_id, 13 );

        if ( empty( $breakdown ) ) {
            echo '<p>No hay intervenciones registradas para mostrar el historial de disponibilidad.</p>';
            return;
        }

        echo '<table class="widefat striped cmh-avail-table">'
            . '<thead><tr>'
            . '<th>Mes</th><th>H. programadas</th><th>H. varada averías</th>'
            . '<th>H. mantenimiento</th><th>H. operación real</th>'
            . '<th>Disponibilidad</th><th>Averías</th><th>MTTR</th>'
            . '</tr></thead><tbody>';

        foreach ( $breakdown as $row ) {
            $avail_class = '';
            if ( $row['availability'] !== null ) {
                $avail_class = $row['availability'] >= 90
                    ? 'cmh-avail-ok'
                    : ( $row['availability'] >= 70 ? 'cmh-avail-warn' : 'cmh-avail-danger' );
            }
            echo '<tr>'
                . '<td><strong>' . esc_html( $row['label'] ) . '</strong></td>'
                . '<td>' . esc_html( number_format( $row['scheduled'],           2, ',', '.' ) ) . ' h</td>'
                . '<td>' . esc_html( number_format( $row['downtime_averia'],     2, ',', '.' ) ) . ' h</td>'
                . '<td>' . esc_html( number_format( $row['downtime_maintenance'],2, ',', '.' ) ) . ' h</td>'
                . '<td>' . esc_html( number_format( $row['real_operation'],      2, ',', '.' ) ) . ' h</td>'
                . '<td><span class="cmh-avail-badge ' . $avail_class . '">' . esc_html( CMH_Metrics::fmt_pct( $row['availability'] ) ) . '</span></td>'
                . '<td>' . intval( $row['averia_count'] ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $row['mttr'] ) ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';

        echo '<p class="description" style="margin-top:10px">'
            . 'Solo las <strong>averías</strong> (intervenciones que afectan disponibilidad) descuentan del tiempo programado. '
            . 'El mantenimiento preventivo y correctivo sin parada no impacta la disponibilidad.'
            . '</p>';
    }

    public static function intervention_form( $machine_id, $last_hourmeter = 0 ) {
        static $systems = [
            'frenos'         => 'Frenos',
            'potencia'       => 'Potencia',
            'traccion'       => 'Tracción',
            'seguridad'      => 'Seguridad',
            'encendido'      => 'Encendido',
            'refrigeracion'  => 'Refrigeración',
            'mastil'         => 'Mástil',
            'direccion'      => 'Dirección',
            'combustible'    => 'Combustible',
            'hidraulico'     => 'Sist. Hidráulico',
            'electronico'    => 'Electrónico',
            'otro'           => 'Otro',
        ];

        self::form_start( 'cm_save_intervention' );
        echo '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ) ) . '">';

        echo '<label>Fecha <em>*</em></label><input type="date" name="intervention_date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required>'
            . '<label>Tipo de mantenimiento <em>*</em></label>'
            . '<select name="maintenance_type" id="cmh-mtype">'
            . '<option value="preventivo">Preventivo</option>'
            . '<option value="correctivo">Correctivo</option>'
            . '<option value="evaluacion">Evaluación</option>'
            . '<option value="averia">Avería (descuenta disponibilidad)</option>'
            . '</select>'
            . '<label>Formato</label>'
            . '<select name="form_type">'
            . '<option value="combustion">Combustión</option>'
            . '<option value="electricos">Eléctricos</option>'
            . '<option value="correctivo">Evaluación/Correctivo</option>'
            . '<option value="manual">Registro manual</option>'
            . '</select>'
            . '<label>Técnico</label><input name="technician">'
            . '<label>Horómetro</label>'
            . '<input type="number" step="0.01" name="hourmeter" min="0" data-last-hourmeter="' . esc_attr( $last_hourmeter ) . '" id="cmh-hourmeter-input">'
            . '<div id="cmh-hourmeter-warn" class="cmh-field-warning" style="display:none"></div>';

        // Campos que aplican a averías/correctivos
        echo '<div id="cmh-downtime-fields">'
            . '<label>Sistema / Falla</label>'
            . '<select name="failure_system"><option value="">— Seleccionar —</option>';
        foreach ( $systems as $k => $v ) {
            echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
        }
        echo '</select>'
            . '<label>Horas parada <small>(solo averías)</small></label><input type="number" step="0.01" name="downtime_hours" value="0" min="0">'
            . '</div>';

        echo '<label>Horas trabajadas</label><input type="number" step="0.01" name="worked_hours" value="0" min="0">'
            . '<label>Costo</label><input type="number" step="100" name="cost" value="0" min="0">'
            . '<label id="cmh-av-label"><input type="checkbox" name="affects_availability" value="1"> Afecta disponibilidad'
            . ' <small>(se marca automáticamente para averías)</small></label>'
            . '<label>Repuestos / insumos</label><textarea name="parts"></textarea>'
            . '<label>Servicios prestados</label><textarea name="services"></textarea>'
            . '<label>Observaciones</label><textarea name="observations"></textarea>'
            . '<button class="button button-primary">Guardar intervención</button></form>';
    }

    public static function files_table( $machine_id = 0 ) {
        global $wpdb;
        $t     = CMH_Core::tables();
        $where = $machine_id ? $wpdb->prepare( 'WHERE machine_id=%d', $machine_id ) : '';
        $rows  = $wpdb->get_results( "SELECT * FROM {$t['files']} $where ORDER BY id DESC LIMIT 100" );

        echo '<table class="widefat striped"><thead><tr><th>Archivo</th><th>Intervención</th><th>Fecha</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr>'
                . '<td><a target="_blank" href="' . esc_url( $r->file_url ) . '">' . esc_html( $r->file_name ) . '</a></td>'
                . '<td>' . ( $r->intervention_id ? '#' . esc_html( $r->intervention_id ) : '—' ) . '</td>'
                . '<td>' . esc_html( $r->created_at ) . '</td>'
                . '</tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="3">No hay archivos anexados.</td></tr>';
        echo '</tbody></table>';
    }

    public static function upload_form( $machine_id ) {
        self::form_start( 'cm_upload_file', true );
        echo '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_url( self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ) ) . '">'
            . '<label>ID de intervención <small>(opcional)</small></label><input type="number" name="intervention_id" min="1">'
            . '<label>Archivo <em>*</em></label><input type="file" name="format_file" accept="application/pdf,image/*" required>'
            . '<button class="button button-primary" style="margin-top:10px">Subir archivo</button></form>';
    }

    // =========================================================================
    // Integración
    // =========================================================================

    public static function page_integration() {
        global $wpdb;
        $t = CMH_Core::tables();
        self::page_header( 'Integración Forminator / E2PDF', [ [ 'label' => 'Integración' ] ] );

        echo '<div class="cmh-panel"><h2>Formularios conectados</h2>'
            . '<table class="widefat striped"><thead><tr>'
            . '<th>Form ID</th><th>Tipo</th><th>Campo máquina</th><th>Mantenimiento</th><th>Estado</th>'
            . '</tr></thead><tbody>';
        foreach ( CMH_Integration::config() as $fid => $cfg ) {
            echo '<tr>'
                . '<td><strong>' . intval( $fid ) . '</strong></td>'
                . '<td>' . esc_html( $cfg['form_type'] ) . '</td>'
                . '<td><code>' . esc_html( $cfg['machine_field'] ) . '</code></td>'
                . '<td>' . esc_html( $cfg['maintenance_type'] ) . '</td>'
                . '<td><span class="cmh-badge cmh-status-activa">Activo</span></td>'
                . '</tr>';
        }
        echo '</tbody></table>'
            . '<p class="description">Forminator captura los envíos, crea la intervención y E2PDF asocia el PDF generado. '
            . 'Si el PDF no aparece de inmediato, WP-Cron lo reintenta 90 s después.</p></div>';

        echo '<div class="cmh-panel"><h2>Últimos logs de integración</h2>';
        $rows = $wpdb->get_results( "SELECT * FROM {$t['logs']} ORDER BY id DESC LIMIT 100" );
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Fecha</th><th>Nivel</th><th>Form</th><th>Máquina</th><th>Intervención</th><th>Mensaje</th>'
            . '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $level_class = $r->level === 'error' ? 'cmh-log-error' : ( $r->level === 'success' ? 'cmh-log-ok' : '' );
            echo '<tr class="' . $level_class . '">'
                . '<td>' . esc_html( $r->created_at ) . '</td>'
                . '<td>' . esc_html( $r->level ) . '</td>'
                . '<td>' . esc_html( $r->form_id ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->machine_code ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->intervention_id ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->message ) . '</td>'
                . '</tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="6">Aún no hay logs.</td></tr>';
        echo '</tbody></table></div>';
        self::page_footer();
    }

    // =========================================================================
    // CRUD — Handlers de formularios
    // =========================================================================

    public static function save_company() {
        self::check();
        global $wpdb; $t = CMH_Core::tables();
        $wpdb->insert( $t['companies'], [
            'name' => sanitize_text_field( $_POST['name'] ),
            'code' => self::clean_code( $_POST['code'] ),
        ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies' ), 'Empresa guardada.' );
    }

    public static function save_city() {
        self::check();
        global $wpdb; $t = CMH_Core::tables();
        $company_id = intval( $_POST['company_id'] );
        $wpdb->insert( $t['cities'], [
            'company_id' => $company_id,
            'name'       => sanitize_text_field( $_POST['name'] ),
            'code'       => self::clean_code( $_POST['code'] ),
        ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ), 'Ciudad guardada.' );
    }

    public static function save_branch() {
        self::check();
        global $wpdb; $t = CMH_Core::tables();
        $city_id = intval( $_POST['city_id'] );
        $wpdb->insert( $t['branches'], [
            'company_id' => intval( $_POST['company_id'] ),
            'city_id'    => $city_id,
            'name'       => sanitize_text_field( $_POST['name'] ),
            'code'       => self::clean_code( $_POST['code'] ),
            'address'    => sanitize_textarea_field( $_POST['address'] ),
        ] );
        self::redirect_to( self::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] ), 'Sucursal guardada.' );
    }

    public static function save_machine() {
        self::check();
        global $wpdb; $t = CMH_Core::tables();

        $company = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['companies']} WHERE id=%d", $_POST['company_id'] ) );
        $city    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['cities']}    WHERE id=%d", $_POST['city_id']    ) );
        if ( ! $company || ! $city ) wp_die( 'Empresa o ciudad no encontrada.' );

        $brand_code = self::brand_code( $_POST['brand'] );
        $prefix     = $company->code . '-' . $city->code . '-' . $brand_code;
        $count      = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['machines']} WHERE machine_code LIKE %s",
            $wpdb->esc_like( $prefix ) . '-%'
        ) );
        $machine_code = $prefix . '-' . str_pad( $count + 1, 3, '0', STR_PAD_LEFT );
        $branch_id    = ! empty( $_POST['branch_id'] ) ? intval( $_POST['branch_id'] ) : null;

        $wpdb->insert( $t['machines'], [
            'company_id'              => intval( $_POST['company_id'] ),
            'city_id'                 => intval( $_POST['city_id'] ),
            'branch_id'               => $branch_id,
            'machine_code'            => $machine_code,
            'brand'                   => sanitize_text_field( $_POST['brand'] ),
            'brand_code'              => $brand_code,
            'model'                   => sanitize_text_field( $_POST['model'] ),
            'serial'                  => sanitize_text_field( $_POST['serial'] ),
            'contact'                 => sanitize_text_field( $_POST['contact'] ),
            'current_hourmeter'       => floatval( $_POST['current_hourmeter'] ),
            'scheduled_hours_monthly' => max( 1, floatval( $_POST['scheduled_hours_monthly'] ) ) ?: 480,
            'status'                  => sanitize_text_field( $_POST['status'] ),
            'notes'                   => sanitize_textarea_field( $_POST['notes'] ),
            'updated_at'              => current_time( 'mysql' ),
        ] );

        $redirect = $branch_id
            ? self::admin_url( CMH_SLUG . '-companies', [ 'branch_id' => $branch_id ] )
            : self::admin_url( CMH_SLUG . '-companies', [ 'city_id'   => intval( $_POST['city_id'] ) ] );

        self::redirect_to( $redirect, 'Máquina guardada. Código: ' . $machine_code );
    }

    public static function save_intervention() {
        self::check();
        global $wpdb; $t = CMH_Core::tables();

        $machine_id = intval( $_POST['machine_id'] );
        $mtype      = sanitize_text_field( $_POST['maintenance_type'] );
        $manual_av  = isset( $_POST['affects_availability'] ) ? 1 : 0;

        // Horómetro: verificar inconsistencia
        $hourmeter   = floatval( $_POST['hourmeter'] );
        $prev_hm     = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT current_hourmeter FROM {$t['machines']} WHERE id=%d", $machine_id
        ) );
        $hm_warn = '';
        if ( $hourmeter > 0 && $prev_hm > 0 && $hourmeter < $prev_hm ) {
            $hm_warn = sprintf(
                'Horómetro inconsistente: se ingresó %.2f h, pero el último registrado era %.2f h.',
                $hourmeter, $prev_hm
            );
        }

        $wpdb->insert( $t['interventions'], [
            'machine_id'          => $machine_id,
            'forminator_form_id'  => null,
            'intervention_date'   => sanitize_text_field( $_POST['intervention_date'] ),
            'form_type'           => sanitize_text_field( $_POST['form_type'] ),
            'maintenance_type'    => $mtype,
            'technician'          => sanitize_text_field( $_POST['technician'] ),
            'hourmeter'           => $hourmeter,
            'worked_hours'        => floatval( $_POST['worked_hours'] ),
            'downtime_hours'      => floatval( $_POST['downtime_hours'] ),
            'cost'                => floatval( $_POST['cost'] ),
            'affects_availability'=> CMH_Metrics::auto_affects_availability( $mtype, $manual_av ),
            'failure_system'      => sanitize_text_field( $_POST['failure_system'] ),
            'parts'               => sanitize_textarea_field( $_POST['parts'] ),
            'services'            => sanitize_textarea_field( $_POST['services'] ),
            'observations'        => sanitize_textarea_field( $_POST['observations'] ),
        ] );

        if ( $hourmeter > 0 && $hourmeter >= $prev_hm ) {
            $wpdb->update( $t['machines'],
                [ 'current_hourmeter' => $hourmeter, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $machine_id ]
            );
        }

        self::redirect_to(
            self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ),
            'Intervención guardada.',
            $hm_warn
        );
    }

    public static function update_machine() {
        self::check();
        global $wpdb; $t = CMH_Core::tables();

        $machine_id = intval( $_POST['machine_id'] );
        $new_hm     = floatval( $_POST['current_hourmeter'] );
        $prev_hm    = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT current_hourmeter FROM {$t['machines']} WHERE id=%d", $machine_id
        ) );
        $hm_warn = '';
        if ( $new_hm > 0 && $prev_hm > 0 && $new_hm < $prev_hm ) {
            $hm_warn = sprintf(
                'Horómetro actualizado a %.2f h (anterior: %.2f h). Verifica que sea correcto.',
                $new_hm, $prev_hm
            );
        }

        $branch_id = isset( $_POST['branch_id'] ) && $_POST['branch_id'] !== ''
            ? intval( $_POST['branch_id'] )
            : null;

        $wpdb->update( $t['machines'], [
            'brand'                   => sanitize_text_field( $_POST['brand'] ),
            'brand_code'              => self::brand_code( $_POST['brand'] ),
            'model'                   => sanitize_text_field( $_POST['model'] ),
            'serial'                  => sanitize_text_field( $_POST['serial'] ),
            'contact'                 => sanitize_text_field( $_POST['contact'] ),
            'current_hourmeter'       => $new_hm,
            'scheduled_hours_monthly' => max( 1, floatval( $_POST['scheduled_hours_monthly'] ) ) ?: 480,
            'branch_id'               => $branch_id,
            'status'                  => sanitize_text_field( $_POST['status'] ),
            'notes'                   => sanitize_textarea_field( $_POST['notes'] ),
            'updated_at'              => current_time( 'mysql' ),
        ], [ 'id' => $machine_id ] );

        self::redirect_to(
            self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ),
            'Máquina actualizada.',
            $hm_warn
        );
    }

    public static function upload_file() {
        self::check();
        if ( empty( $_FILES['format_file']['name'] ) ) wp_die( 'Sin archivo.' );
        require_once ABSPATH . 'wp-admin/includes/file.php';

        global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        $m          = $wpdb->get_row( $wpdb->prepare(
            "SELECT machine_code FROM {$t['machines']} WHERE id=%d", $machine_id
        ) );
        if ( ! $m ) wp_die( 'Máquina no encontrada.' );

        add_filter( 'upload_dir', function ( $dirs ) use ( $m ) {
            $dirs['subdir'] = '/cm-machine-history/' . $m->machine_code;
            $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        } );

        $file = wp_handle_upload( $_FILES['format_file'], [ 'test_form' => false ] );
        if ( isset( $file['error'] ) ) wp_die( $file['error'] );

        $wpdb->insert( $t['files'], [
            'machine_id'      => $machine_id,
            'intervention_id' => intval( $_POST['intervention_id'] ) ?: null,
            'file_url'        => esc_url_raw( $file['url'] ),
            'file_path'       => $file['file'],
            'file_name'       => basename( $file['file'] ),
            'file_type'       => $file['type'],
            'uploaded_by'     => get_current_user_id(),
        ] );

        self::redirect_to(
            self::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] ),
            'Archivo anexado correctamente.'
        );
    }

    public static function ajax_get_machine() {
        if ( ! current_user_can( 'read' ) ) wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        global $wpdb; $t = CMH_Core::tables();
        $code = sanitize_text_field( $_GET['code'] ?? '' );
        $m    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['machines']} WHERE machine_code=%s OR serial=%s", $code, $code
        ) );
        if ( ! $m ) wp_send_json_error( [ 'message' => 'Máquina no encontrada.' ] );
        wp_send_json_success( $m );
    }
}
