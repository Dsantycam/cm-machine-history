<?php
/**
 * CMH_Tech — v0.9 Panel de técnicos.
 *
 * - Menú propio "Mis Máquinas" (capacidad cmh_tech) con vista reducida: el técnico
 *   solo ve las máquinas que le fueron asignadas, sus tareas y puede registrar
 *   intervenciones sobre ellas. No accede a empresas, ciudades ni métricas globales.
 * - Helpers compartidos de asignaciones (técnico ↔ máquina) y tareas, usados también
 *   por CMH_Admin en el tab "Técnicos" de la hoja de vida.
 *
 * Seguridad: cada acción del técnico verifica la capacidad cmh_tech Y que la máquina
 * esté asignada al usuario actual (can_access_machine). Los administradores/editores
 * (edit_others_posts) pueden ver cualquier máquina en el panel para previsualizar.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Tech {

    const TASK_STATUSES = [
        'pendiente'   => 'Pendiente',
        'en_progreso' => 'En progreso',
        'completada'  => 'Completada',
    ];

    // =========================================================================
    // Init
    // =========================================================================

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_post_cmh_tech_update_task',   [ __CLASS__, 'tech_update_task' ] );
        add_action( 'admin_post_cmh_tech_save_intervention', [ __CLASS__, 'tech_save_intervention' ] );
    }

    public static function admin_menu() {
        add_menu_page(
            'Mis Máquinas', 'Mis Máquinas', 'cmh_tech', 'cmh-tech',
            [ __CLASS__, 'page_panel' ], 'dashicons-clipboard', 27
        );
    }

    // =========================================================================
    // Helpers de datos (compartidos con CMH_Admin)
    // =========================================================================

    /** Usuarios con rol de técnico, ordenados por nombre. */
    public static function technicians() {
        return get_users( [ 'role' => 'cmh_technician', 'orderby' => 'display_name', 'order' => 'ASC' ] );
    }

    /** IDs de usuario asignados a una máquina. */
    public static function assigned_user_ids( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$t['assignments']} WHERE machine_id=%d", $machine_id
        ) ) );
    }

    /** Objetos WP_User asignados a una máquina. */
    public static function machine_techs( $machine_id ) {
        $ids = self::assigned_user_ids( $machine_id );
        if ( ! $ids ) return [];
        return get_users( [ 'include' => $ids, 'orderby' => 'display_name', 'order' => 'ASC' ] );
    }

    /** IDs de máquina asignadas a un usuario. */
    public static function assigned_machine_ids( $user_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT machine_id FROM {$t['assignments']} WHERE user_id=%d", $user_id
        ) ) );
    }

    /** ¿El usuario está asignado a esta máquina? */
    public static function is_assigned( $machine_id, $user_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['assignments']} WHERE machine_id=%d AND user_id=%d", $machine_id, $user_id
        ) );
    }

    /**
     * ¿El usuario actual puede ver/operar esta máquina en el panel del técnico?
     * Admins/editores (edit_others_posts) pueden todo; el resto solo lo asignado.
     */
    public static function can_access_machine( $machine_id, $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( user_can( $user_id, 'edit_others_posts' ) ) return true;
        return self::is_assigned( $machine_id, $user_id );
    }

    /** Tareas de una máquina (con nombre del técnico asignado). */
    public static function tasks_for_machine( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t['tasks']} WHERE machine_id=%d ORDER BY FIELD(status,'en_progreso','pendiente','completada'), due_date IS NULL, due_date ASC, id DESC",
            $machine_id
        ) );
    }

    /** Tareas asignadas a un usuario, opcionalmente solo las abiertas. */
    public static function tasks_for_user( $user_id, $only_open = false ) {
        global $wpdb; $t = CMH_Core::tables();
        $extra = $only_open ? " AND ta.status <> 'completada'" : '';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ta.*, m.machine_code, m.id machine_id
             FROM {$t['tasks']} ta JOIN {$t['machines']} m ON m.id=ta.machine_id
             WHERE ta.assigned_to=%d $extra
             ORDER BY FIELD(ta.status,'en_progreso','pendiente','completada'), ta.due_date IS NULL, ta.due_date ASC, ta.id DESC",
            $user_id
        ) );
    }

    public static function task_status_badge( $status ) {
        $status = sanitize_key( $status );
        $styles = [
            'pendiente'   => 'background:#fff3cd;color:#7a4f00',
            'en_progreso' => 'background:#e7f0fb;color:#1c4d80',
            'completada'  => 'background:#e6f4ea;color:#1a6630',
        ];
        $style = $styles[ $status ] ?? 'background:#f0f0f1;color:#3c434a';
        $label = self::TASK_STATUSES[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
        return '<span class="cmh-badge" style="' . $style . '">' . esc_html( $label ) . '</span>';
    }

    // =========================================================================
    // Panel del técnico — "Mis Máquinas"
    // =========================================================================

    public static function page_panel() {
        if ( ! current_user_can( 'cmh_tech' ) ) wp_die( 'Sin permisos.' );
        $machine_id = intval( $_GET['machine_id'] ?? 0 );
        if ( $machine_id ) return self::page_machine_tech( $machine_id );
        self::page_my_machines();
    }

    private static function page_my_machines() {
        global $wpdb; $t = CMH_Core::tables();
        $uid    = get_current_user_id();
        $is_mgr = current_user_can( 'edit_others_posts' );

        CMH_Admin::page_header( 'Mis Máquinas', [ [ 'label' => 'Mis Máquinas' ] ] );

        // Máquinas asignadas (o todas, si es admin/editor previsualizando).
        if ( $is_mgr ) {
            $machines = $wpdb->get_results(
                "SELECT m.*, c.name company_name, ci.name city_name
                 FROM {$t['machines']} m
                 JOIN {$t['companies']} c  ON c.id=m.company_id
                 JOIN {$t['cities']}    ci ON ci.id=m.city_id
                 ORDER BY m.machine_code"
            );
        } else {
            $ids = self::assigned_machine_ids( $uid );
            if ( $ids ) {
                $in = implode( ',', array_map( 'intval', $ids ) );
                $machines = $wpdb->get_results(
                    "SELECT m.*, c.name company_name, ci.name city_name
                     FROM {$t['machines']} m
                     JOIN {$t['companies']} c  ON c.id=m.company_id
                     JOIN {$t['cities']}    ci ON ci.id=m.city_id
                     WHERE m.id IN ($in) ORDER BY m.machine_code"
                );
            } else {
                $machines = [];
            }
        }

        if ( $is_mgr ) {
            echo '<div class="notice notice-info inline" style="margin:0 0 16px"><p>Estás viendo este panel como administrador (previsualización). Los técnicos solo ven las máquinas que les asignes.</p></div>';
        }

        // Tareas pendientes del usuario.
        $tasks = self::tasks_for_user( $uid, true );
        echo '<div class="cmh-panel"><h2>Mis tareas pendientes <small style="font-weight:400;font-size:13px;color:#646970">— ' . count( $tasks ) . '</small></h2>';
        if ( $tasks ) {
            echo '<table class="widefat cmh"><thead><tr><th>Tarea</th><th>Máquina</th><th>Vence</th><th>Estado</th><th></th></tr></thead><tbody>';
            foreach ( $tasks as $ta ) {
                echo '<tr>'
                    . '<td><strong>' . esc_html( $ta->title ) . '</strong>' . ( $ta->notes ? '<br><span style="font-size:12px;color:#646970">' . esc_html( wp_trim_words( $ta->notes, 20 ) ) . '</span>' : '' ) . '</td>'
                    . '<td>' . esc_html( $ta->machine_code ) . '</td>'
                    . '<td>' . self::due_label( $ta->due_date ) . '</td>'
                    . '<td>' . self::task_status_badge( $ta->status ) . '</td>'
                    . '<td><a class="button button-small" href="' . esc_url( CMH_Admin::admin_url( 'cmh-tech', [ 'machine_id' => $ta->machine_id ] ) ) . '#cmh-tech-tareas">Abrir</a></td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#646970;font-size:13px;margin:4px 0 0">No tienes tareas pendientes. 🎉</p>';
        }
        echo '</div>';

        // Grid de máquinas asignadas.
        echo '<div class="cmh-panel"><h2>Máquinas asignadas <small style="font-weight:400;font-size:13px;color:#646970">— ' . count( $machines ) . '</small></h2>';
        if ( ! $machines ) {
            echo '<div class="cmh-empty"><div class="cmh-empty-icon"><span class="dashicons dashicons-hammer"></span></div>'
                . '<strong>Sin máquinas asignadas</strong><p>Cuando un administrador te asigne máquinas, aparecerán aquí.</p></div>';
        } else {
            echo '<table class="widefat cmh cmh-machine-table"><thead><tr>'
                . '<th>Código</th><th>Marca / Modelo</th><th>Ubicación</th><th>Estado</th><th>Horómetro</th><th>Tareas</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ( $machines as $m ) {
                $open = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$t['tasks']} WHERE machine_id=%d AND status<>'completada'", $m->id
                ) );
                $url = esc_url( CMH_Admin::admin_url( 'cmh-tech', [ 'machine_id' => $m->id ] ) );
                echo '<tr>'
                    . '<td><strong>' . esc_html( $m->machine_code ) . '</strong></td>'
                    . '<td>' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</td>'
                    . '<td style="font-size:12px">' . esc_html( $m->company_name . ' / ' . $m->city_name ) . '</td>'
                    . '<td>' . CMH_Admin::status_badge( $m->status ) . '</td>'
                    . '<td>' . esc_html( $m->current_hourmeter ) . ' h</td>'
                    . '<td>' . ( $open ? '<span class="cmh-badge" style="background:#fff3cd;color:#7a4f00">' . $open . ' pendiente' . ( $open > 1 ? 's' : '' ) . '</span>' : '<span style="color:#646970">—</span>' ) . '</td>'
                    . '<td><a class="button button-small button-primary" href="' . $url . '">Abrir</a></td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        CMH_Admin::page_footer();
    }

    private static function page_machine_tech( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();

        if ( ! self::can_access_machine( $machine_id ) )
            wp_die( 'No tienes acceso a esta máquina.' );

        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             WHERE m.id=%d", $machine_id
        ) );
        if ( ! $m ) wp_die( 'Máquina no encontrada.' );

        CMH_Admin::page_header( $m->machine_code, [
            [ 'label' => 'Mis Máquinas', 'url' => CMH_Admin::admin_url( 'cmh-tech' ) ],
            [ 'label' => $m->machine_code ],
        ] );

        $month     = (int) current_time( 'n' );
        $year      = (int) current_time( 'Y' );
        $avail_now = CMH_Metrics::availability( $machine_id, $month, $year );
        $avail_acc = $avail_now === null ? 'blue' : ( $avail_now >= 90 ? 'ok' : ( $avail_now >= 70 ? 'warn' : 'danger' ) );
        $loc       = $m->company_name . ' / ' . $m->city_name;

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Ficha del técnico</div>'
            . '<h2>' . esc_html( $m->machine_code ) . ' ' . CMH_Admin::status_badge( $m->status ) . '</h2>'
            . '<p>' . esc_html( $loc ) . ' &nbsp;·&nbsp; ' . esc_html( trim( $m->brand . ' ' . $m->model ) ) . '</p>'
            . '</div><div class="cmh-hero-actions">'
            . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( 'cmh-tech' ) ) . '">Volver</a>'
            . '</div></div>';

        echo '<div class="cmh-grid">';
        CMH_Admin::metric_card( 'Disponibilidad ' . CMH_Metrics::month_label( $month, $year ), CMH_Metrics::fmt_pct( $avail_now ), 'mes actual', $avail_acc );
        CMH_Admin::metric_card( 'Averías este mes', (int) CMH_Metrics::averia_count( $machine_id, $month, $year ), 'mes actual', 'warn' );
        CMH_Admin::metric_card( 'Horómetro', number_format( (float) $m->current_hourmeter, 2, ',', '.' ) . ' h', 'actual', 'blue' );
        CMH_Admin::metric_card( 'H. programadas / mes', number_format( (float) $m->scheduled_hours_monthly, 0 ) . ' h', 'base disponib.', 'blue' );
        echo '</div>';

        echo '<div class="cmh-layout"><div class="cmh-main">';

        // Mis tareas en esta máquina
        echo '<div id="cmh-tech-tareas" class="cmh-panel"><h2>Tareas de esta máquina</h2>';
        self::render_tech_tasks( $machine_id );
        echo '</div>';

        // Últimas intervenciones (solo lectura)
        echo '<div class="cmh-panel"><h2>Últimas intervenciones</h2>';
        self::render_readonly_interventions( $machine_id );
        echo '</div>';

        echo '</div><div class="cmh-side"><div class="cmh-panel"><h2>Registrar intervención</h2>';
        self::tech_intervention_form( $machine_id, (float) $m->current_hourmeter );
        echo '</div></div></div>';

        CMH_Admin::page_footer();
    }

    /** Tareas de la máquina, con controles de estado para el técnico. */
    private static function render_tech_tasks( $machine_id ) {
        $tasks = self::tasks_for_machine( $machine_id );
        if ( ! $tasks ) {
            echo '<p style="color:#646970;font-size:13px;margin:0">No hay tareas para esta máquina.</p>';
            return;
        }
        echo '<table class="widefat cmh"><thead><tr><th>Tarea</th><th>Vence</th><th>Estado</th><th>Cambiar estado</th></tr></thead><tbody>';
        foreach ( $tasks as $ta ) {
            echo '<tr>'
                . '<td><strong>' . esc_html( $ta->title ) . '</strong>' . ( $ta->notes ? '<br><span style="font-size:12px;color:#646970">' . esc_html( $ta->notes ) . '</span>' : '' ) . '</td>'
                . '<td>' . self::due_label( $ta->due_date ) . '</td>'
                . '<td>' . self::task_status_badge( $ta->status ) . '</td>'
                . '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:6px;align-items:center">'
                . '<input type="hidden" name="action" value="cmh_tech_update_task">'
                . '<input type="hidden" name="task_id" value="' . intval( $ta->id ) . '">'
                . '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                . '<select name="status">';
            foreach ( self::TASK_STATUSES as $k => $v )
                echo '<option value="' . esc_attr( $k ) . '" ' . selected( $ta->status, $k, false ) . '>' . esc_html( $v ) . '</option>';
            echo '</select><button class="button button-small">Actualizar</button></form></td>'
                . '</tr>';
        }
        echo '</tbody></table>';
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
            echo '<p style="color:#646970;font-size:13px;margin:0">Aún no hay intervenciones. Registra la primera en el formulario de la derecha.</p>';
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

    /** Formulario de intervención simplificado para el técnico. */
    private static function tech_intervention_form( $machine_id, $last_hourmeter = 0 ) {
        static $systems = [ 'frenos' => 'Frenos', 'potencia' => 'Potencia', 'traccion' => 'Tracción', 'seguridad' => 'Seguridad', 'encendido' => 'Encendido', 'refrigeracion' => 'Refrigeración', 'mastil' => 'Mástil', 'direccion' => 'Dirección', 'combustible' => 'Combustible', 'hidraulico' => 'Sist. Hidráulico', 'electronico' => 'Electrónico', 'otro' => 'Otro' ];
        $me = wp_get_current_user();

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
            . '<input type="hidden" name="action" value="cmh_tech_save_intervention">'
            . '<input type="hidden" name="machine_id" value="' . intval( $machine_id ) . '">'
            . wp_nonce_field( 'cmh_action', '_wpnonce', true, false );

        echo '<label>Fecha <em>*</em></label><input type="date" name="intervention_date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required>'
            . '<label>Tipo <em>*</em></label><select name="maintenance_type">'
            . '<option value="preventivo">Preventivo</option>'
            . '<option value="correctivo">Correctivo</option>'
            . '<option value="averia">Avería</option>'
            . '<option value="evaluacion">Evaluación</option>'
            . '</select>'
            . '<label>Técnico</label><input name="technician" value="' . esc_attr( $me->display_name ) . '">'
            . '<label>Horómetro</label><input type="number" step="0.01" name="hourmeter" min="0" id="cmh-hourmeter-input" data-last-hourmeter="' . esc_attr( $last_hourmeter ) . '">'
            . '<div id="cmh-hourmeter-warn" class="cmh-field-warning" style="display:none"></div>';

        echo '<div class="cmh-form-section"><p class="cmh-form-section-title">Falla / Parada</p>'
            . '<label>Sistema / Falla</label><select name="failure_system"><option value="">— Seleccionar —</option>';
        foreach ( $systems as $k => $v ) echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
        echo '</select>'
            . '<label>Horas parada <span class="cmh-optional">(averías)</span></label>'
            . '<input type="number" step="0.01" name="downtime_hours" value="0" min="0"></div>';

        echo '<div class="cmh-form-section"><p class="cmh-form-section-title">Detalle</p>'
            . '<label>Horas trabajadas</label><input type="number" step="0.01" name="worked_hours" value="0" min="0">'
            . '<label>Repuestos / insumos</label><textarea name="parts"></textarea>'
            . '<label>Servicios prestados</label><textarea name="services"></textarea>'
            . '<label>Observaciones</label><textarea name="observations"></textarea></div>'
            . '<button class="button button-primary">Guardar intervención</button></form>';
    }

    private static function due_label( $due_date ) {
        if ( ! $due_date ) return '<span style="color:#646970">—</span>';
        $days = CMH_Metrics::maintenance_days( $due_date );
        if ( $days === null ) return esc_html( $due_date );
        if ( $days < 0 )      return '<span style="color:#d63638">' . esc_html( $due_date ) . ' (vencida hace ' . abs( $days ) . ' d)</span>';
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

    // =========================================================================
    // Handlers del técnico (gated: cmh_tech + máquina asignada)
    // =========================================================================

    private static function tech_check() {
        if ( ! current_user_can( 'cmh_tech' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'cmh_action' );
    }

    public static function tech_update_task() {
        self::tech_check();
        global $wpdb; $t = CMH_Core::tables();
        $task_id = intval( $_POST['task_id'] );
        $task    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['tasks']} WHERE id=%d", $task_id ) );
        if ( ! $task ) wp_die( 'Tarea no encontrada.' );
        if ( ! self::can_access_machine( (int) $task->machine_id ) ) wp_die( 'No tienes acceso a esta tarea.' );

        $status = sanitize_key( $_POST['status'] ?? '' );
        if ( ! isset( self::TASK_STATUSES[ $status ] ) ) wp_die( 'Estado inválido.' );

        $wpdb->update( $t['tasks'], [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $task_id ] );

        CMH_Admin::redirect_to(
            CMH_Admin::admin_url( 'cmh-tech', [ 'machine_id' => (int) $task->machine_id ] ),
            'Tarea actualizada.'
        );
    }

    public static function tech_save_intervention() {
        self::tech_check();
        global $wpdb; $t = CMH_Core::tables();
        $machine_id = intval( $_POST['machine_id'] );
        if ( ! self::can_access_machine( $machine_id ) ) wp_die( 'No tienes acceso a esta máquina.' );
        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['machines']} WHERE id=%d", $machine_id ) ) )
            wp_die( 'Máquina no encontrada.' );

        $mtype     = sanitize_text_field( $_POST['maintenance_type'] );
        $hourmeter = floatval( $_POST['hourmeter'] );
        $prev_hm   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT current_hourmeter FROM {$t['machines']} WHERE id=%d", $machine_id ) );

        $hm_warn = '';
        if ( $hourmeter > 0 && $prev_hm > 0 && $hourmeter < $prev_hm )
            $hm_warn = sprintf( 'Horómetro ingresado (%.2f h) es menor al registrado anteriormente (%.2f h).', $hourmeter, $prev_hm );

        $wpdb->insert( $t['interventions'], [
            'machine_id'           => $machine_id, 'forminator_form_id' => null,
            'intervention_date'    => sanitize_text_field( $_POST['intervention_date'] ),
            'form_type'            => 'manual',
            'maintenance_type'     => $mtype,
            'technician'           => sanitize_text_field( $_POST['technician'] ),
            'hourmeter'            => $hourmeter,
            'worked_hours'         => floatval( $_POST['worked_hours'] ),
            'downtime_hours'       => floatval( $_POST['downtime_hours'] ),
            'cost'                 => 0,
            'affects_availability' => CMH_Metrics::auto_affects_availability( $mtype, 0 ),
            'failure_system'       => sanitize_text_field( $_POST['failure_system'] ),
            'parts'                => sanitize_textarea_field( $_POST['parts'] ),
            'services'             => sanitize_textarea_field( $_POST['services'] ),
            'observations'         => sanitize_textarea_field( $_POST['observations'] ),
        ] );

        if ( $hourmeter > 0 && $hourmeter >= $prev_hm )
            $wpdb->update( $t['machines'], [ 'current_hourmeter' => $hourmeter, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $machine_id ] );

        CMH_Admin::redirect_to(
            CMH_Admin::admin_url( 'cmh-tech', [ 'machine_id' => $machine_id ] ),
            'Intervención registrada.', $hm_warn
        );
    }
}
