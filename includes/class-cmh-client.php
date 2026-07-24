<?php
/**
 * CMH_Client — v0.10 Portal de clientes (solo lectura).
 *
 * - Menú propio "Portal Cliente / Mis Equipos" (capacidad cmh_client). El cliente
 *   ve TODAS las máquinas de la(s) empresa(s) que le fueron asignadas: listado,
 *   ficha con KPIs, disponibilidad mensual e intervenciones con su PDF. Sin editar,
 *   sin tareas, sin técnicos.
 * - Lado admin: panel "Clientes con acceso" en la ficha de empresa para asignar/
 *   quitar usuarios cliente (gated por edit_others_posts).
 *
 * Seguridad: el acceso se concede POR EMPRESA (tabla client_companies). Cada vista
 * del cliente verifica cmh_client Y que la máquina pertenezca a una de sus empresas
 * (can_access_machine). Admins/editores (edit_others_posts) previsualizan todo.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Client {

    // =========================================================================
    // Init
    // =========================================================================

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_post_cm_assign_client',   [ __CLASS__, 'assign_client' ] );
        add_action( 'admin_post_cm_unassign_client', [ __CLASS__, 'unassign_client' ] );
    }

    public static function admin_menu() {
        // "Mis Equipos" es el portal de solo lectura para clientes. Los administradores/
        // editores gestionan todo desde el menú "Máquinas" y no deben ver este menú.
        if ( current_user_can( 'edit_others_posts' ) ) return;

        add_menu_page(
            'Portal Cliente', 'Mis Equipos', 'cmh_client', 'cmh-client',
            [ __CLASS__, 'page_panel' ], 'dashicons-portfolio', 28
        );
    }

    // =========================================================================
    // Helpers de datos (compartidos con CMH_Admin)
    // =========================================================================

    /** Usuarios con rol de cliente, ordenados por nombre. */
    public static function clients() {
        return get_users( [ 'role' => 'cmh_client', 'orderby' => 'display_name', 'order' => 'ASC' ] );
    }

    /** IDs de empresa asignadas a un usuario cliente. */
    public static function client_company_ids( $user_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT company_id FROM {$t['clients']} WHERE user_id=%d", $user_id
        ) ) );
    }

    /** IDs de usuario cliente con acceso a una empresa. */
    public static function company_client_ids( $company_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$t['clients']} WHERE company_id=%d", $company_id
        ) ) );
    }

    /** Objetos WP_User con acceso a una empresa. */
    public static function company_clients( $company_id ) {
        $ids = self::company_client_ids( $company_id );
        if ( ! $ids ) return [];
        return get_users( [ 'include' => $ids, 'orderby' => 'display_name', 'order' => 'ASC' ] );
    }

    /**
     * ¿El usuario actual puede ver esta máquina en el portal?
     * Admins/editores (edit_others_posts) pueden todo; el cliente solo las máquinas
     * de sus empresas asignadas.
     */
    public static function can_access_machine( $machine_id, $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( user_can( $user_id, 'edit_others_posts' ) ) return true;
        global $wpdb; $t = CMH_Core::tables();
        $company_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT company_id FROM {$t['machines']} WHERE id=%d", $machine_id
        ) );
        if ( ! $company_id ) return false;
        return in_array( $company_id, self::client_company_ids( $user_id ), true );
    }

    // =========================================================================
    // Lado admin — panel "Clientes con acceso" en la ficha de empresa
    // =========================================================================

    /** Render del panel de asignación de clientes (se llama desde CMH_Admin::page_company). */
    public static function company_clients_panel( $company_id ) {
        $clients   = self::clients();
        $assigned  = self::company_clients( $company_id );
        $assigned_ids = wp_list_pluck( $assigned, 'ID' );
        $back      = CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] );

        if ( ! $clients ) {
            echo '<div class="cmh-note" style="margin:0 0 12px">No hay usuarios con rol <strong>Cliente (CM)</strong>. '
                . 'Créalos en <a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">Usuarios → Añadir nuevo</a> con el rol «Cliente (CM)».</div>';
        }

        if ( $assigned ) {
            echo '<table class="widefat cmh" style="margin-bottom:12px"><thead><tr><th>Cliente</th><th>Email</th><th></th></tr></thead><tbody>';
            foreach ( $assigned as $u ) {
                echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong></td>'
                    . '<td style="font-size:12px;color:#646970">' . esc_html( $u->user_email ) . '</td>'
                    . '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Quitar el acceso de este cliente?\')">'
                    . '<input type="hidden" name="action" value="cm_unassign_client">'
                    . '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
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
                . '<input type="hidden" name="action" value="cm_assign_client">'
                . '<input type="hidden" name="company_id" value="' . intval( $company_id ) . '">'
                . '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                . '<label>Dar acceso a<select name="user_id" required><option value="">— Seleccionar —</option>';
            foreach ( $available as $u )
                echo '<option value="' . intval( $u->ID ) . '">' . esc_html( $u->display_name ) . '</option>';
            echo '</select></label><button class="button button-primary" style="margin-top:6px">Dar acceso</button></form>';
        }
    }

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
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ), 'Cliente con acceso.' );
    }

    public static function unassign_client() {
        CMH_Admin::check(); global $wpdb; $t = CMH_Core::tables();
        $company_id = intval( $_POST['company_id'] );
        $user_id    = intval( $_POST['user_id'] );
        $wpdb->delete( $t['clients'], [ 'company_id' => $company_id, 'user_id' => $user_id ] );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-companies', [ 'company_id' => $company_id ] ), 'Acceso eliminado.' );
    }

    // =========================================================================
    // Portal del cliente — "Mis Equipos"
    // =========================================================================

    public static function page_panel() {
        if ( ! current_user_can( 'cmh_client' ) ) wp_die( 'Sin permisos.' );
        $machine_id = intval( $_GET['machine_id'] ?? 0 );
        if ( $machine_id ) return self::page_machine_client( $machine_id );
        self::page_my_equipment();
    }

    private static function page_my_equipment() {
        global $wpdb; $t = CMH_Core::tables();
        $uid    = get_current_user_id();
        $is_mgr = current_user_can( 'edit_others_posts' );

        CMH_Admin::page_header( 'Mis Equipos', [ [ 'label' => 'Mis Equipos' ] ] );

        if ( $is_mgr ) {
            $machines = $wpdb->get_results(
                "SELECT m.*, c.name company_name, ci.name city_name
                 FROM {$t['machines']} m
                 JOIN {$t['companies']} c  ON c.id=m.company_id
                 JOIN {$t['cities']}    ci ON ci.id=m.city_id
                 ORDER BY m.machine_code"
            );
            echo '<div class="notice notice-info inline" style="margin:0 0 16px"><p>Estás viendo este portal como administrador (previsualización). Los clientes solo ven las máquinas de las empresas que les asignes.</p></div>';
        } else {
            $ids = self::client_company_ids( $uid );
            if ( $ids ) {
                $in = implode( ',', array_map( 'intval', $ids ) );
                $machines = $wpdb->get_results(
                    "SELECT m.*, c.name company_name, ci.name city_name
                     FROM {$t['machines']} m
                     JOIN {$t['companies']} c  ON c.id=m.company_id
                     JOIN {$t['cities']}    ci ON ci.id=m.city_id
                     WHERE m.company_id IN ($in) ORDER BY m.machine_code"
                );
            } else {
                $machines = [];
            }
        }

        $month = (int) current_time( 'n' );
        $year  = (int) current_time( 'Y' );

        echo '<div class="cmh-panel"><h2>Equipos <small style="font-weight:400;font-size:13px;color:#646970">— ' . count( $machines ) . '</small></h2>';
        if ( ! $machines ) {
            echo '<div class="cmh-empty"><div class="cmh-empty-icon"><span class="dashicons dashicons-portfolio"></span></div>'
                . '<strong>Sin equipos disponibles</strong><p>Cuando se te asigne el acceso a una empresa, sus equipos aparecerán aquí.</p></div>';
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

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Ficha del equipo</div>'
            . '<h2>' . esc_html( $m->machine_code ) . ' ' . CMH_Admin::status_badge( $m->status ) . '</h2>'
            . '<p>' . esc_html( $loc ) . ' &nbsp;·&nbsp; ' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</p>'
            . '</div><div class="cmh-hero-actions">'
            . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( 'cmh-client' ) ) . '">Volver</a>'
            . '</div></div>';

        echo '<div class="cmh-grid">';
        CMH_Admin::metric_card( 'Disponibilidad ' . CMH_Metrics::month_label( $month, $year ), CMH_Metrics::fmt_pct( $avail_now ), 'mes actual', $avail_acc );
        CMH_Admin::metric_card( 'Averías este mes', (int) CMH_Metrics::averia_count( $machine_id, $month, $year ), 'mes actual', 'warn' );
        CMH_Admin::metric_card( 'Horómetro', number_format( (float) $m->current_hourmeter, 2, ',', '.' ) . ' h', 'actual', 'blue' );
        CMH_Admin::metric_card( 'Próximo mantenimiento', $m->next_maintenance_date ?: '—', 'programado', 'blue' );
        echo '</div>';

        echo '<div class="cmh-layout"><div class="cmh-main">';

        // Últimas intervenciones (solo lectura)
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
        echo '<table class="widefat cmh"><thead><tr><th>Fecha</th><th>Tipo</th><th>Técnico</th><th>H. parada</th><th>PDF</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr>'
                . '<td>' . esc_html( $r->intervention_date ) . '</td>'
                . '<td>' . self::mtype_badge( $r->maintenance_type ?: $r->form_type ) . '</td>'
                . '<td>' . esc_html( $r->technician ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->downtime_hours ) . ' h</td>'
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

    private static function mtype_badge( $type ) {
        $styles = [ 'preventivo' => 'background:#e6f4ea;color:#1a6630', 'correctivo' => 'background:#fff3cd;color:#7a4f00', 'averia' => 'background:#fce8e8;color:#d63638', 'evaluacion' => 'background:#f0f0f1;color:#3c434a' ];
        $labels = [ 'preventivo' => 'Preventivo', 'correctivo' => 'Correctivo', 'averia' => 'Avería', 'evaluacion' => 'Evaluación' ];
        $key    = strtolower( $type );
        $style  = $styles[ $key ] ?? 'background:#f0f0f1;color:#3c434a';
        $label  = $labels[ $key ] ?? ucfirst( $type );
        return '<span class="cmh-badge" style="' . $style . '">' . esc_html( $label ) . '</span>';
    }
}
