<?php
/**
 * CMH_Client — Portal de clientes (solo lectura).
 *
 * v0.10 — menú propio «Mis Equipos» con acceso POR EMPRESA (tabla
 *         client_companies): el cliente ve todas las máquinas de su empresa.
 * v2.0  — el acceso también puede acotarse a una o varias CIUDADES/SUCURSALES
 *         (tabla client_cities) sin abrir la empresa completa, y el portal gana
 *         reportería propia: indicadores, gráficas y costos por equipo, por
 *         sucursal y del total, con los mismos números del administrador pero
 *         redactados para el cliente.
 *
 * MODELO DE ACCESO. El alcance efectivo de un usuario cliente es la UNIÓN de:
 *   - las empresas asignadas en client_companies (toda la flota de esa empresa), y
 *   - las ciudades/sucursales asignadas en client_cities (solo esas sedes).
 * Así se puede dar acceso a «BOGOTÁ» sin entregar el resto de la empresa. Toda
 * consulta del portal aplica ese alcance; en la reportería se inyecta como ACL
 * en CMH_Reports, que lo suma con AND a cualquier filtro de la URL.
 *
 * Lado admin: panel «Clientes con acceso» en la ficha de empresa (acceso total)
 * y en la ficha de ciudad/sucursal (acceso acotado), ambos gated por
 * edit_others_posts.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Client {

    // =========================================================================
    // Init
    // =========================================================================

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_post_cm_assign_client',        [ __CLASS__, 'assign_client' ] );
        add_action( 'admin_post_cm_unassign_client',      [ __CLASS__, 'unassign_client' ] );
        add_action( 'admin_post_cm_assign_client_city',   [ __CLASS__, 'assign_client_city' ] );
        add_action( 'admin_post_cm_unassign_client_city', [ __CLASS__, 'unassign_client_city' ] );
    }

    public static function admin_menu() {
        // «Mis Equipos» es el portal de solo lectura para clientes. Los administradores/
        // editores gestionan todo desde el menú «Máquinas» y no deben ver este menú.
        if ( current_user_can( 'edit_others_posts' ) ) return;

        add_menu_page(
            'Portal Cliente', 'Mis Equipos', 'cmh_client', 'cmh-client',
            [ __CLASS__, 'page_panel' ], 'dashicons-portfolio', 28
        );
        add_submenu_page( 'cmh-client', 'Mis Equipos', 'Mis Equipos', 'cmh_client', 'cmh-client', [ __CLASS__, 'page_panel' ] );
        add_submenu_page( 'cmh-client', 'Reportes',    'Reportes',    'cmh_client', 'cmh-client-reports', [ __CLASS__, 'page_reports' ] );
    }

    // =========================================================================
    // Helpers de datos y alcance
    // =========================================================================

    /** Usuarios con rol de cliente, ordenados por nombre. */
    public static function clients() {
        return get_users( [ 'role' => 'cmh_client', 'orderby' => 'display_name', 'order' => 'ASC' ] );
    }

    /** IDs de empresa asignadas a un usuario cliente (acceso total a la empresa). */
    public static function client_company_ids( $user_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT company_id FROM {$t['clients']} WHERE user_id=%d", $user_id
        ) ) );
    }

    /** v2.0 — IDs de ciudad/sucursal asignadas a un usuario cliente. */
    public static function client_city_ids( $user_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT city_id FROM {$t['client_cities']} WHERE user_id=%d", $user_id
        ) ) );
    }

    /** IDs de usuario cliente con acceso total a una empresa. */
    public static function company_client_ids( $company_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$t['clients']} WHERE company_id=%d", $company_id
        ) ) );
    }

    /** v2.0 — IDs de usuario cliente con acceso acotado a una ciudad/sucursal. */
    public static function city_client_ids( $city_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$t['client_cities']} WHERE city_id=%d", $city_id
        ) ) );
    }

    /** Objetos WP_User a partir de una lista de IDs. */
    private static function users_by_ids( $ids ) {
        if ( ! $ids ) return [];
        return get_users( [ 'include' => $ids, 'orderby' => 'display_name', 'order' => 'ASC' ] );
    }

    /** Objetos WP_User con acceso total a una empresa. */
    public static function company_clients( $company_id ) {
        return self::users_by_ids( self::company_client_ids( $company_id ) );
    }

    /** Objetos WP_User con acceso acotado a una ciudad/sucursal. */
    public static function city_clients( $city_id ) {
        return self::users_by_ids( self::city_client_ids( $city_id ) );
    }

    /** Alcance de un usuario: [ 'companies' => int[], 'cities' => int[] ]. */
    public static function acl( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        return [
            'companies' => self::client_company_ids( $user_id ),
            'cities'    => self::client_city_ids( $user_id ),
        ];
    }

    /** ¿El usuario tiene algún acceso asignado? */
    public static function has_scope( $user_id = null ) {
        $acl = self::acl( $user_id );
        return (bool) ( $acl['companies'] || $acl['cities'] );
    }

    /**
     * Fragmento WHERE que acota una consulta sobre machines al alcance del usuario.
     * Devuelve '' para quien puede verlo todo y ' AND 1=0' para quien no tiene nada.
     * Los IDs vienen de la BD y se fuerzan a entero, así que se interpolan seguros.
     */
    public static function scope_where( $user_id = null, $alias = 'm' ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( user_can( $user_id, 'edit_others_posts' ) ) return '';

        $acl = self::acl( $user_id );
        $or  = [];
        if ( $acl['companies'] ) $or[] = "$alias.company_id IN (" . implode( ',', array_map( 'intval', $acl['companies'] ) ) . ")";
        if ( $acl['cities']    ) $or[] = "$alias.city_id IN ("    . implode( ',', array_map( 'intval', $acl['cities']    ) ) . ")";
        return $or ? ' AND (' . implode( ' OR ', $or ) . ')' : ' AND 1=0';
    }

    /**
     * ¿El usuario actual puede ver esta máquina en el portal?
     * Admins/editores pueden todo; el cliente solo lo que esté en su alcance.
     */
    public static function can_access_machine( $machine_id, $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( user_can( $user_id, 'edit_others_posts' ) ) return true;

        global $wpdb; $t = CMH_Core::tables();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT company_id, city_id FROM {$t['machines']} WHERE id=%d", $machine_id
        ) );
        if ( ! $row ) return false;

        $acl = self::acl( $user_id );
        return in_array( (int) $row->company_id, $acl['companies'], true )
            || in_array( (int) $row->city_id,    $acl['cities'],    true );
    }

    /** ¿El usuario puede ver esta ciudad/sucursal? */
    public static function can_access_city( $city_id, $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( user_can( $user_id, 'edit_others_posts' ) ) return true;

        $acl = self::acl( $user_id );
        if ( in_array( (int) $city_id, $acl['cities'], true ) ) return true;
        if ( ! $acl['companies'] ) return false;

        global $wpdb; $t = CMH_Core::tables();
        $company_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT company_id FROM {$t['cities']} WHERE id=%d", $city_id
        ) );
        return $company_id && in_array( $company_id, $acl['companies'], true );
    }

    /**
     * Prepara CMH_Reports para renderizar con la voz y el alcance del cliente.
     * Un administrador previsualizando conserva el alcance completo (ACL nula).
     */
    public static function apply_report_context() {
        $uid = get_current_user_id();
        $acl = current_user_can( 'edit_others_posts' ) ? null : self::acl( $uid );
        CMH_Reports::set_context( 'client', $acl, 'cmh-client-reports' );
    }

    // =========================================================================
    // Lado admin — paneles de asignación
    // =========================================================================

    /**
     * Panel de asignación reutilizable: lista los clientes ya asignados y ofrece
     * el desplegable con los que faltan.
     *
     * @param string $level 'company' | 'city'
     */
    private static function assign_panel( $level, $object_id, $assigned, $back, $note = '' ) {
        $clients      = self::clients();
        $assigned_ids = wp_list_pluck( $assigned, 'ID' );
        $is_city      = ( $level === 'city' );
        $add_action   = $is_city ? 'cm_assign_client_city'   : 'cm_assign_client';
        $del_action   = $is_city ? 'cm_unassign_client_city' : 'cm_unassign_client';
        $id_field     = $is_city ? 'city_id' : 'company_id';

        if ( ! $clients ) {
            echo '<div class="cmh-note" style="margin:0 0 12px">No hay usuarios con rol <strong>Cliente (CM)</strong>. '
                . 'Créalos en <a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">Usuarios → Añadir nuevo</a> con el rol «Cliente (CM)».</div>';
        }

        if ( $note ) echo '<p style="font-size:12px;color:#646970;margin:0 0 10px">' . esc_html( $note ) . '</p>';

        if ( $assigned ) {
            echo '<table class="widefat cmh" style="margin-bottom:12px"><thead><tr><th>Cliente</th><th>Email</th><th></th></tr></thead><tbody>';
            foreach ( $assigned as $u ) {
                echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong></td>'
                    . '<td style="font-size:12px;color:#646970">' . esc_html( $u->user_email ) . '</td>'
                    . '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Quitar el acceso de este cliente?\')">'
                    . '<input type="hidden" name="action" value="' . esc_attr( $del_action ) . '">'
                    . '<input type="hidden" name="' . esc_attr( $id_field ) . '" value="' . intval( $object_id ) . '">'
                    . '<input type="hidden" name="user_id" value="' . intval( $u->ID ) . '">'
                    . '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">'
                    . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                    . '<button class="button button-small" style="color:#d63638;border-color:#d63638">Quitar</button>'
                    . '</form></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#646970;font-size:13px;margin:0 0 12px">Ningún cliente con acceso todavía.</p>';
        }

        $available = array_filter( $clients, function ( $u ) use ( $assigned_ids ) {
            return ! in_array( $u->ID, $assigned_ids, true );
        } );
        if ( $available ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
                . '<input type="hidden" name="action" value="' . esc_attr( $add_action ) . '">'
                . '<input type="hidden" name="' . esc_attr( $id_field ) . '" value="' . intval( $object_id ) . '">'
                . '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                . '<label>Dar acceso a<select name="user_id" required><option value="">— Seleccionar —</option>';
            foreach ( $available as $u )
                echo '<option value="' . intval( $u->ID ) . '">' . esc_html( $u->display_name ) . '</option>';
            echo '</select></label><button class="button button-primary" style="margin-top:6px">Dar acceso</button></form>';
        }
    }

    /** Panel de la ficha de EMPRESA: acceso a toda la flota de la empresa. */
    public static function company_clients_panel( $company_id ) {
        $back = CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] );
        self::assign_panel( 'company', $company_id, self::company_clients( $company_id ), $back,
            'Estos clientes ven TODAS las máquinas de la empresa. Para dar acceso a una sola sede, entra a la ciudad/sucursal.' );

        // Resumen de accesos acotados dentro de esta empresa, para que no queden invisibles.
        global $wpdb; $t = CMH_Core::tables();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ci.id, ci.name, COUNT(cc.user_id) n
             FROM {$t['cities']} ci
             JOIN {$t['client_cities']} cc ON cc.city_id=ci.id
             WHERE ci.company_id=%d
             GROUP BY ci.id, ci.name ORDER BY ci.name",
            $company_id
        ) );
        if ( $rows ) {
            echo '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #f0f0f1">'
                . '<p style="font-size:12px;color:#646970;margin:0 0 6px"><strong>Accesos por sucursal</strong> dentro de esta empresa:</p><ul style="margin:0;font-size:12px">';
            foreach ( $rows as $r ) {
                echo '<li><a href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $r->id ] ) ) . '">'
                    . esc_html( $r->name ) . '</a> — ' . intval( $r->n ) . ' cliente(s)</li>';
            }
            echo '</ul></div>';
        }
    }

    /** v2.0 — Panel de la ficha de CIUDAD/SUCURSAL: acceso acotado a esa sede. */
    public static function city_clients_panel( $city_id ) {
        $back = CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] );
        self::assign_panel( 'city', $city_id, self::city_clients( $city_id ), $back,
            'Estos clientes ven ÚNICAMENTE las máquinas de esta ciudad/sucursal, no el resto de la empresa.' );

        // Quien ya entra por la empresa completa no necesita el acceso acotado: se avisa
        // para que no parezca que «no quedó asignado» cuando en realidad ya lo ve todo.
        global $wpdb; $t = CMH_Core::tables();
        $company_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT company_id FROM {$t['cities']} WHERE id=%d", $city_id ) );
        $full = $company_id ? self::company_clients( $company_id ) : [];
        if ( $full ) {
            $names = implode( ', ', wp_list_pluck( $full, 'display_name' ) );
            echo '<p style="font-size:12px;color:#646970;margin:12px 0 0">Además, con acceso a toda la empresa: <strong>'
                . esc_html( $names ) . '</strong>.</p>';
        }
    }

    // =========================================================================
    // Handlers de asignación
    // =========================================================================

    public static function assign_client() {
        CMH_Admin::check(); global $wpdb; $t = CMH_Core::tables();
        $company_id = intval( $_POST['company_id'] );
        $user_id    = intval( $_POST['user_id'] );
        if ( ! $company_id || ! $user_id ) wp_die( 'Datos incompletos.' );
        if ( ! user_can( $user_id, 'cmh_client' ) ) wp_die( 'El usuario no es un cliente válido.' );

        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$t['clients']} (user_id, company_id, created_at) VALUES (%d, %d, %s)",
            $user_id, $company_id, current_time( 'mysql' )
        ) );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ), 'Cliente con acceso a toda la empresa.' );
    }

    public static function unassign_client() {
        CMH_Admin::check(); global $wpdb; $t = CMH_Core::tables();
        $company_id = intval( $_POST['company_id'] );
        $user_id    = intval( $_POST['user_id'] );
        $wpdb->delete( $t['clients'], [ 'company_id' => $company_id, 'user_id' => $user_id ] );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ), 'Acceso eliminado.' );
    }

    public static function assign_client_city() {
        CMH_Admin::check(); global $wpdb; $t = CMH_Core::tables();
        $city_id = intval( $_POST['city_id'] );
        $user_id = intval( $_POST['user_id'] );
        if ( ! $city_id || ! $user_id ) wp_die( 'Datos incompletos.' );
        if ( ! user_can( $user_id, 'cmh_client' ) ) wp_die( 'El usuario no es un cliente válido.' );
        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['cities']} WHERE id=%d", $city_id ) ) )
            wp_die( 'Ciudad/Sucursal no encontrada.' );

        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$t['client_cities']} (user_id, city_id, created_at) VALUES (%d, %d, %s)",
            $user_id, $city_id, current_time( 'mysql' )
        ) );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] ), 'Cliente con acceso a esta sucursal.' );
    }

    public static function unassign_client_city() {
        CMH_Admin::check(); global $wpdb; $t = CMH_Core::tables();
        $city_id = intval( $_POST['city_id'] );
        $user_id = intval( $_POST['user_id'] );
        $wpdb->delete( $t['client_cities'], [ 'city_id' => $city_id, 'user_id' => $user_id ] );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'city_id' => $city_id ] ), 'Acceso eliminado.' );
    }

    // =========================================================================
    // Portal — «Mis Equipos»
    // =========================================================================

    public static function page_panel() {
        if ( ! current_user_can( 'cmh_client' ) ) wp_die( 'Sin permisos.' );
        $machine_id = intval( $_GET['machine_id'] ?? 0 );
        if ( $machine_id ) return self::page_machine_client( $machine_id );
        self::page_my_equipment();
    }

    /** v2.0 — Reportería del cliente: mismo motor, su alcance y su vocabulario. */
    public static function page_reports() {
        if ( ! current_user_can( 'cmh_client' ) ) wp_die( 'Sin permisos.' );
        self::apply_report_context();
        CMH_Reports::page_reports();
    }

    private static function page_my_equipment() {
        global $wpdb; $t = CMH_Core::tables();
        $uid     = get_current_user_id();
        $is_mgr  = current_user_can( 'edit_others_posts' );
        $city_id = intval( $_GET['city_id'] ?? 0 );

        CMH_Admin::page_header( 'Mis Equipos', [ [ 'label' => 'Mis Equipos' ] ] );

        if ( $is_mgr ) {
            echo '<div class="notice notice-info inline" style="margin:0 0 16px"><p>Estás viendo este portal como administrador (previsualización). '
                . 'Los clientes solo ven las máquinas de las empresas o sucursales que les asignes.</p></div>';
        }

        // Filtro por sucursal, dentro de lo que el usuario tiene permitido ver.
        $scope_where = self::scope_where( $uid );
        if ( $city_id && ! self::can_access_city( $city_id ) ) $city_id = 0;
        $city_where = $city_id ? $wpdb->prepare( ' AND m.city_id=%d', $city_id ) : '';

        $machines = $wpdb->get_results(
            "SELECT m.*, c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             WHERE 1=1 $scope_where $city_where
             ORDER BY ci.name, m.machine_code"
        );

        $cities = $wpdb->get_results(
            "SELECT DISTINCT ci.id, ci.name, c.name company_name
             FROM {$t['cities']} ci
             JOIN {$t['machines']}  m ON m.city_id=ci.id
             JOIN {$t['companies']} c ON c.id=ci.company_id
             WHERE 1=1 $scope_where
             ORDER BY c.name, ci.name"
        );

        // Resumen del alcance completo (no del filtro), para que el encabezado no cambie de sentido.
        self::apply_report_context();
        $f     = CMH_Reports::make_filters( $city_id ? [ 'city_id' => $city_id ] : [] );
        $scope = CMH_Reports::scope_totals( $f );

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Portal del cliente</div>'
            . '<h2>Tus equipos</h2>'
            . '<p>' . intval( $scope['machines'] ) . ' equipo(s) · ' . count( $cities ) . ' sucursal(es) con acceso</p>'
            . '</div><div class="cmh-hero-actions">'
            . '<a class="button button-primary" href="' . esc_url( CMH_Admin::admin_url( 'cmh-client-reports' ) ) . '">Ver indicadores y reportes</a>'
            . '</div></div>';

        if ( $scope['machines'] ) {
            $totals = CMH_Reports::period_totals( $f );
            $n      = max( 1, count( CMH_Reports::months( $f ) ) );
            $base   = $scope['sched'] * $n;
            $dt     = (float) $totals->dt_averia;
            $avail  = $base > 0 ? min( 100.0, max( 0.0, ( $base - $dt ) / $base * 100 ) ) : null;
            $acc    = $avail === null ? 'blue' : ( $avail >= 90 ? 'ok' : ( $avail >= 70 ? 'warn' : 'danger' ) );

            echo '<div class="cmh-grid">';
            CMH_Admin::metric_card( 'Disponibilidad', CMH_Metrics::fmt_pct( $avail ), 'últimos 12 meses', $acc );
            CMH_Admin::metric_card( 'Intervenciones', (int) $totals->total,   'últimos 12 meses', 'blue' );
            CMH_Admin::metric_card( 'Averías',        (int) $totals->averias, 'últimos 12 meses', 'danger' );
            CMH_Admin::metric_card( 'Total facturado', CMH_Reports::money( $totals->costo ), 'últimos 12 meses', 'blue' );
            CMH_Admin::metric_card( 'Pendiente por pagar', CMH_Reports::money( $totals->por_cobrar ),
                'saldo a tu cargo', (float) $totals->por_cobrar > 0 ? 'warn' : 'ok' );
            echo '</div>';
        }

        // Selector de sucursal.
        if ( count( $cities ) > 1 ) {
            echo '<div class="cmh-panel"><form method="get" class="cmh-report-filters">'
                . '<input type="hidden" name="page" value="cmh-client">'
                . '<label>Sucursal<select name="city_id"><option value="0">Todas mis sucursales</option>';
            foreach ( $cities as $c )
                echo '<option value="' . intval( $c->id ) . '" ' . selected( $city_id, $c->id, false ) . '>'
                    . esc_html( $c->company_name . ' / ' . $c->name ) . '</option>';
            echo '</select></label>'
                . '<button class="button button-primary">Filtrar</button>'
                . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( 'cmh-client' ) ) . '">Limpiar</a>'
                . '</form></div>';
        }

        // Resumen por sucursal.
        if ( ! $city_id && count( $cities ) > 1 ) {
            $groups = CMH_Reports::by_dimension( CMH_Reports::make_filters(), 'city' );
            if ( $groups ) {
                echo '<div class="cmh-panel"><h2>Por sucursal <small style="font-weight:400;font-size:13px;color:#646970">— últimos 12 meses</small></h2>'
                    . '<table class="widefat cmh"><thead><tr>'
                    . '<th>Sucursal</th><th>Equipos</th><th>Disponibilidad</th><th>Intervenciones</th><th>Averías</th>'
                    . '<th>Facturado</th><th>Pendiente</th><th></th></tr></thead><tbody>';
                foreach ( $groups as $g ) {
                    echo '<tr>'
                        . '<td><strong>' . esc_html( $g['name'] ) . '</strong></td>'
                        . '<td>' . intval( $g['machines'] ) . '</td>'
                        . '<td>' . CMH_Reports::avail_badge( $g['availability'] ) . '</td>'
                        . '<td>' . intval( $g['total'] ) . '</td>'
                        . '<td>' . intval( $g['averias'] ) . '</td>'
                        . '<td>' . esc_html( CMH_Reports::money( $g['costo'] ) ) . '</td>'
                        . '<td>' . esc_html( CMH_Reports::money( $g['por_cobrar'] ) ) . '</td>'
                        . '<td><a class="button button-small" href="' . esc_url( CMH_Admin::admin_url( 'cmh-client-reports', [ 'city_id' => $g['id'] ] ) ) . '">Ver reporte</a></td>'
                        . '</tr>';
                }
                echo '</tbody></table></div>';
            }
        }

        $month = (int) current_time( 'n' );
        $year  = (int) current_time( 'Y' );

        echo '<div class="cmh-panel"><h2>Equipos <small style="font-weight:400;font-size:13px;color:#646970">— ' . count( $machines ) . '</small></h2>';
        if ( ! $machines ) {
            echo '<div class="cmh-empty"><div class="cmh-empty-icon"><span class="dashicons dashicons-portfolio"></span></div>'
                . '<strong>Sin equipos disponibles</strong><p>Cuando se te asigne el acceso a una empresa o a una sucursal, sus equipos aparecerán aquí.</p></div>';
        } else {
            echo '<table class="widefat cmh cmh-machine-table"><thead><tr>'
                . '<th>Código</th><th>Marca / Modelo</th><th>Ubicación</th><th>Estado</th><th>Disponib. mes</th><th>Próx. mantenimiento</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ( $machines as $m ) {
                $avail = CMH_Metrics::availability( $m->id, $month, $year );
                $url   = esc_url( CMH_Admin::admin_url( 'cmh-client', [ 'machine_id' => $m->id ] ) );
                echo '<tr>'
                    . '<td><strong>' . esc_html( $m->machine_code ) . '</strong></td>'
                    . '<td>' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</td>'
                    . '<td style="font-size:12px">' . esc_html( $m->company_name . ' / ' . $m->city_name ) . '</td>'
                    . '<td>' . CMH_Admin::status_badge( $m->status ) . '</td>'
                    . '<td>' . CMH_Metrics::fmt_pct( $avail ) . '</td>'
                    . '<td>' . self::due_label( $m->next_maintenance_date ) . '</td>'
                    . '<td><a class="button button-small button-primary" href="' . $url . '">Ver ficha</a></td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        CMH_Admin::page_footer();
    }

    private static function page_machine_client( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();

        if ( ! self::can_access_machine( $machine_id ) )
            wp_die( 'No tienes acceso a este equipo.' );

        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             WHERE m.id=%d", $machine_id
        ) );
        if ( ! $m ) wp_die( 'Equipo no encontrado.' );

        CMH_Admin::page_header( $m->machine_code, [
            [ 'label' => 'Mis Equipos', 'url' => CMH_Admin::admin_url( 'cmh-client' ) ],
            [ 'label' => $m->machine_code ],
        ] );

        $month     = (int) current_time( 'n' );
        $year      = (int) current_time( 'Y' );
        $avail_now = CMH_Metrics::availability( $machine_id, $month, $year );
        $avail_acc = $avail_now === null ? 'blue' : ( $avail_now >= 90 ? 'ok' : ( $avail_now >= 70 ? 'warn' : 'danger' ) );
        $loc       = $m->company_name . ' / ' . $m->city_name;

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(cost),0) costo,
                    COALESCE(SUM(paid_amount),0) pagado,
                    COALESCE(SUM(CASE WHEN cost>paid_amount THEN cost-paid_amount ELSE 0 END),0) por_cobrar
             FROM {$t['interventions']} WHERE machine_id=%d",
            $machine_id
        ) );

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Ficha del equipo</div>'
            . '<h2>' . esc_html( $m->machine_code ) . ' ' . CMH_Admin::status_badge( $m->status ) . '</h2>'
            . '<p>' . esc_html( $loc ) . ' &nbsp;·&nbsp; ' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</p>'
            . '</div><div class="cmh-hero-actions">'
            . '<a class="button cmh-btn-print" href="#">Imprimir / PDF</a>'
            . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( 'cmh-client-reports', [ 'machine_id' => $machine_id ] ) ) . '">Reporte completo</a>'
            . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( 'cmh-client' ) ) . '">Volver</a>'
            . '</div></div>';

        echo '<div class="cmh-grid">';
        CMH_Admin::metric_card( 'Disponibilidad ' . CMH_Metrics::month_label( $month, $year ), CMH_Metrics::fmt_pct( $avail_now ), 'mes actual', $avail_acc );
        CMH_Admin::metric_card( 'Averías este mes', (int) CMH_Metrics::averia_count( $machine_id, $month, $year ), 'mes actual', 'warn' );
        CMH_Admin::metric_card( 'Horómetro', number_format( (float) $m->current_hourmeter, 2, ',', '.' ) . ' h', 'actual', 'blue' );
        CMH_Admin::metric_card( 'Próximo mantenimiento', $m->next_maintenance_date ?: '—', 'programado', 'blue' );
        CMH_Admin::metric_card( 'Intervenciones', (int) $stats->total, 'historial', 'blue' );
        CMH_Admin::metric_card( 'Total facturado', CMH_Reports::money( $stats->costo ), 'historial', 'blue' );
        CMH_Admin::metric_card( 'Pagado', CMH_Reports::money( $stats->pagado ), 'historial', 'ok' );
        CMH_Admin::metric_card( 'Pendiente por pagar', CMH_Reports::money( $stats->por_cobrar ),
            'saldo a tu cargo', (float) $stats->por_cobrar > 0 ? 'warn' : 'ok' );
        echo '</div>';

        // Indicadores gráficos del equipo, con el vocabulario del cliente.
        self::apply_report_context();
        echo '<div class="cmh-panel"><h2>Indicadores del equipo</h2>';
        CMH_Reports::machine_charts( $machine_id, true );
        echo '</div>';

        echo '<div class="cmh-layout"><div class="cmh-main">';
        echo '<div class="cmh-panel"><h2>Últimas intervenciones</h2>';
        self::render_readonly_interventions( $machine_id );
        echo '</div>';

        echo '</div><div class="cmh-side"><div class="cmh-panel"><h2>Disponibilidad mensual</h2>';
        self::render_monthly_availability( $machine_id );
        echo '</div></div></div>';

        CMH_Admin::page_footer();
    }

    /** Intervenciones recientes de la máquina, solo lectura (sin editar/eliminar). */
    private static function render_readonly_interventions( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, f.file_url FROM {$t['interventions']} i
             LEFT JOIN {$t['files']} f ON f.intervention_id=i.id
             WHERE i.machine_id=%d ORDER BY i.intervention_date DESC, i.id DESC LIMIT 30",
            $machine_id
        ) );
        if ( ! $rows ) {
            echo '<p style="color:#646970;font-size:13px;margin:0">Aún no hay intervenciones registradas para este equipo.</p>';
            return;
        }
        echo '<table class="widefat cmh"><thead><tr>'
            . '<th>Fecha</th><th>Tipo</th><th>Técnico</th><th>H. parada</th><th>Facturado</th><th>Pago</th><th>PDF</th>'
            . '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr>'
                . '<td>' . esc_html( $r->intervention_date ) . '</td>'
                . '<td>' . self::mtype_badge( $r->maintenance_type ?: $r->form_type ) . '</td>'
                . '<td>' . esc_html( $r->technician ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->downtime_hours ) . ' h</td>'
                . '<td>' . esc_html( CMH_Reports::money( $r->cost ) ) . '</td>'
                . '<td>' . CMH_Admin::payment_badge( $r->payment_status, $r->cost, $r->paid_amount ) . '</td>'
                . '<td>' . ( $r->file_url ? '<a target="_blank" href="' . esc_url( $r->file_url ) . '">Ver</a>' : '—' ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }

    /** Tabla de disponibilidad de los últimos 12 meses. */
    private static function render_monthly_availability( $machine_id ) {
        $month = (int) current_time( 'n' );
        $year  = (int) current_time( 'Y' );
        echo '<table class="widefat cmh"><thead><tr><th>Mes</th><th>Disponibilidad</th></tr></thead><tbody>';
        for ( $i = 0; $i < 12; $i++ ) {
            $mm = $month - $i;
            $yy = $year;
            while ( $mm <= 0 ) { $mm += 12; $yy--; }
            $avail = CMH_Metrics::availability( $machine_id, $mm, $yy );
            echo '<tr><td>' . esc_html( CMH_Metrics::month_label( $mm, $yy ) ) . '</td>'
                . '<td>' . CMH_Metrics::fmt_pct( $avail ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function due_label( $due_date ) {
        if ( ! $due_date ) return '<span style="color:#646970">—</span>';
        $days = CMH_Metrics::maintenance_days( $due_date );
        if ( $days === null ) return esc_html( $due_date );
        if ( $days < 0 )      return '<span style="color:#d63638">' . esc_html( $due_date ) . ' (vencido)</span>';
        if ( $days <= 3 )     return '<span style="color:#7a4f00">' . esc_html( $due_date ) . ' (en ' . $days . ' d)</span>';
        return esc_html( $due_date ) . ' <span style="color:#646970">(en ' . $days . ' d)</span>';
    }

    /** v2.3 — Delegado en la taxonomía configurable. */
    private static function mtype_badge( $type ) {
        return CMH_Taxonomy::mtype_badge( $type );
    }
}
