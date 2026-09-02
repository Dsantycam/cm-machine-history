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
        add_action( 'admin_post_cmh_open_task_form',     [ __CLASS__, 'open_task_form' ] );
    }

    public static function admin_menu() {
        // El menú "Mis Máquinas" es solo para técnicos. Los administradores/editores
        // tienen su propio menú "Máquinas" y no deben ver este panel reducido.
        if ( current_user_can( 'edit_others_posts' ) ) return;

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

    /**
     * v2.0 — IDs de máquina donde el usuario tiene alguna tarea asignada.
     * Se cuentan las tareas en cualquier estado: la tarea es el registro de que
     * se le autorizó a operar esa máquina, y no debe perder el acceso justo
     * después de cerrarla —que es cuando suele querer revisar lo que registró—.
     */
    public static function task_machine_ids( $user_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT machine_id FROM {$t['tasks']} WHERE assigned_to=%d", $user_id
        ) ) );
    }

    /** v2.0 — Máquinas que el técnico puede abrir: asignadas + las que tiene por tarea. */
    public static function accessible_machine_ids( $user_id ) {
        return array_values( array_unique( array_merge(
            self::assigned_machine_ids( $user_id ),
            self::task_machine_ids( $user_id )
        ) ) );
    }

    /** v2.0 — ¿El usuario tiene alguna tarea asignada en esta máquina? */
    public static function has_task_on_machine( $machine_id, $user_id ) {
        global $wpdb; $t = CMH_Core::tables();
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['tasks']} WHERE machine_id=%d AND assigned_to=%d LIMIT 1",
            $machine_id, $user_id
        ) );
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
     *
     * Admins/editores (edit_others_posts) pueden todo. Para el técnico hay DOS
     * vías de acceso, y basta con una:
     *   - estar asignado a la máquina (tabla `assignments`), o
     *   - tener una tarea asignada en esa máquina (v2.0).
     *
     * La segunda vía se agregó porque asignarle una tarea a alguien ya es
     * autorizarlo a operar esa máquina: antes la tarea aparecía en su panel pero
     * el botón «Abrir» chocaba contra «No tienes acceso», que es un callejón sin
     * salida desde el punto de vista del técnico.
     */
    public static function can_access_machine( $machine_id, $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( user_can( $user_id, 'edit_others_posts' ) ) return true;
        return self::is_assigned( $machine_id, $user_id )
            || self::has_task_on_machine( $machine_id, $user_id );
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

    // =========================================================================
    // v2.0 — Abrir el formato de Forminator prellenado desde una tarea
    // =========================================================================

    /**
     * Botón (o selector) para abrir el formato que corresponde a una tarea.
     *
     * Si la tarea trae `form_id`, es un botón directo. Si no —porque quien la creó
     * no eligió formato—, se ofrece el selector de los tres formatos: es el caso
     * de las tareas automáticas del cron, que no saben de qué tipo es la máquina.
     *
     * @param object $task Fila de la tabla `tasks`.
     * @param string $back URL a la que volver si algo falta por configurar.
     */
    public static function open_form_control( $task, $back = '' ) {
        $forms = CMH_Integration::forms_for_select();
        $nonce = wp_create_nonce( 'cmh_action' );
        $post  = esc_url( admin_url( 'admin-post.php' ) );
        $fid   = (int) ( $task->form_id ?? 0 );

        // Sin URL configurada no hay a dónde ir: mejor decirlo que dar un botón muerto.
        $usable = [];
        foreach ( array_keys( $forms ) as $id ) if ( CMH_Integration::form_url( $id ) ) $usable[] = $id;
        if ( ! $usable ) {
            return '<span style="font-size:12px;color:#646970" title="Un administrador debe indicar en qué página vive cada formulario">Formatos sin configurar</span>';
        }

        if ( $fid && CMH_Integration::is_valid_form( $fid ) && CMH_Integration::form_url( $fid ) ) {
            $url = wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( [
                'action'  => 'cmh_open_task_form',
                'task_id' => (int) $task->id,
                'form_id' => $fid,
                'back'    => $back,
            ] ) ), 'cmh_action' );
            return '<a class="button button-small button-primary" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">'
                . 'Abrir formato</a><br><span style="font-size:11px;color:#646970">'
                . esc_html( CMH_Integration::form_label( $fid ) ) . '</span>';
        }

        // Sin formato definido: que el técnico elija cuál diligenciar.
        $html = '<form method="get" action="' . $post . '" target="_blank" rel="noopener" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">'
              . '<input type="hidden" name="action" value="cmh_open_task_form">'
              . '<input type="hidden" name="task_id" value="' . intval( $task->id ) . '">'
              . '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">'
              . '<input type="hidden" name="_wpnonce" value="' . $nonce . '">'
              . '<select name="form_id" required style="max-width:210px"><option value="">— Formato —</option>';
        foreach ( $forms as $id => $label ) {
            if ( ! in_array( $id, $usable, true ) ) continue;
            $html .= '<option value="' . intval( $id ) . '">' . esc_html( $label ) . '</option>';
        }
        return $html . '</select><button class="button button-small button-primary">Abrir</button></form>';
    }

    /**
     * Abre el formato prellenado: valida acceso, marca la tarea «en progreso» y
     * redirige a la página del formulario con el código de máquina en la URL.
     *
     * El cambio de estado va aquí, del lado del servidor, para que no dependa de
     * que el técnico se acuerde de moverlo. Solo avanza `pendiente → en_progreso`
     * y solo si quien abre es el técnico asignado: si abre el administrador para
     * revisar, o la tarea ya está completada, el estado no se toca.
     */
    public static function open_task_form() {
        if ( ! is_user_logged_in() ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'cmh_action' );

        global $wpdb; $t = CMH_Core::tables();
        $task_id = intval( $_GET['task_id'] ?? 0 );
        $form_id = intval( $_GET['form_id'] ?? 0 );
        $back    = ! empty( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : CMH_Admin::admin_url( 'cmh-tech' );

        $task = $wpdb->get_row( $wpdb->prepare(
            "SELECT ta.*, m.machine_code FROM {$t['tasks']} ta
             JOIN {$t['machines']} m ON m.id=ta.machine_id WHERE ta.id=%d",
            $task_id
        ) );
        if ( ! $task ) wp_die( 'Tarea no encontrada.' );

        if ( ! current_user_can( 'edit_others_posts' ) ) {
            if ( ! current_user_can( 'cmh_tech' ) ) wp_die( 'Sin permisos.' );
            if ( ! self::can_access_machine( (int) $task->machine_id ) ) wp_die( 'No tienes acceso a esta tarea.' );
        }

        if ( ! $form_id ) $form_id = (int) $task->form_id;
        if ( ! CMH_Integration::is_valid_form( $form_id ) ) {
            CMH_Admin::redirect_to( $back, '', 'Elige un formato válido para abrir.' );
        }

        $url = CMH_Integration::form_url( $form_id, [
            'cmh_machine' => $task->machine_code,
            'cmh_task'    => (int) $task->id,
        ] );
        if ( ! $url ) {
            CMH_Admin::redirect_to( $back, '', 'El formato «' . CMH_Integration::form_label( $form_id )
                . '» no tiene página configurada. Un administrador debe indicarla en Máquinas → Ajustes.' );
        }

        if ( $task->status === 'pendiente' && (int) $task->assigned_to === get_current_user_id() ) {
            $wpdb->update( $t['tasks'],
                [ 'status' => 'en_progreso', 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $task->id ]
            );
            CMH_Time::on_status_change( $task, 'en_progreso', get_current_user_id() );
        }

        wp_safe_redirect( $url );
        exit;
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
            // v2.0 — Incluye también las máquinas que solo tiene por tarea; si no,
            // la tarea aparecía en el panel pero su máquina no existía para él.
            $ids = self::accessible_machine_ids( $uid );
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
        $assigned_ids = $is_mgr ? [] : self::assigned_machine_ids( $uid );

        if ( $is_mgr ) {
            echo '<div class="notice notice-info inline" style="margin:0 0 16px"><p>Estás viendo este panel como administrador (previsualización). Los técnicos solo ven las máquinas que les asignes.</p></div>';
        }

        // Tareas pendientes del usuario.
        $tasks = self::tasks_for_user( $uid, true );
        echo '<div class="cmh-panel"><h2>Mis tareas pendientes <small style="font-weight:400;font-size:13px;color:#646970">— ' . count( $tasks ) . '</small></h2>';
        if ( $tasks ) {
            $back = CMH_Admin::admin_url( 'cmh-tech' );
            echo '<table class="widefat cmh"><thead><tr><th>Tarea</th><th>Máquina</th><th>Vence</th><th>Estado</th><th>Formato</th><th></th></tr></thead><tbody>';
            foreach ( $tasks as $ta ) {
                echo '<tr>'
                    . '<td><strong>' . esc_html( $ta->title ) . '</strong>' . ( $ta->notes ? '<br><span style="font-size:12px;color:#646970">' . esc_html( wp_trim_words( $ta->notes, 20 ) ) . '</span>' : '' ) . '</td>'
                    . '<td>' . esc_html( $ta->machine_code ) . '</td>'
                    . '<td>' . self::due_label( $ta->due_date ) . '</td>'
                    . '<td>' . self::task_status_badge( $ta->status ) . '</td>'
                    . '<td>' . self::open_form_control( $ta, $back ) . '</td>'
                    . '<td><a class="button button-small" href="' . esc_url( CMH_Admin::admin_url( 'cmh-tech', [ 'machine_id' => $ta->machine_id ] ) ) . '#cmh-tech-tareas">Ver máquina</a></td>'
                    . '</tr>';
            }
            echo '</tbody></table>'
                . '<p style="font-size:12px;color:#646970;margin:10px 0 0">«Abrir formato» abre el formulario en una pestaña nueva con los datos de la máquina ya cargados. La tarea pasa sola a «En progreso».</p>';
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
                $url      = esc_url( CMH_Admin::admin_url( 'cmh-tech', [ 'machine_id' => $m->id ] ) );
                $by_task  = ! $is_mgr && ! in_array( (int) $m->id, $assigned_ids, true );
                echo '<tr>'
                    . '<td><strong>' . esc_html( $m->machine_code ) . '</strong>'
                    . ( $by_task ? ' <span class="cmh-badge" style="background:#e7f0f7;color:#2271b1" title="Tienes acceso a esta máquina porque se te asignó una tarea en ella">Por tarea</span>' : '' )
                    . '</td>'
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

        echo '</div><div class="cmh-side">';

        // v2.0 — Formatos de esta máquina, prellenados, sin depender de una tarea.
        self::render_form_links( $m->machine_code );

        echo '<div class="cmh-panel"><h2>Registrar intervención</h2>';
        self::tech_intervention_form( $machine_id, (float) $m->current_hourmeter );
        echo '</div></div></div>';

        CMH_Admin::page_footer();
    }

    /**
     * v2.0 — Enlaces directos a los tres formatos, ya prellenados con esta máquina.
     *
     * No pasa por el handler de tarea porque no hay tarea que mover de estado: es
     * un enlace limpio a la página del formulario con el código en la URL.
     */
    public static function render_form_links( $machine_code ) {
        $links = [];
        foreach ( CMH_Integration::forms_for_select() as $id => $label ) {
            $url = CMH_Integration::form_url( $id, [ 'cmh_machine' => $machine_code ] );
            if ( ! $url ) continue;
            $links[] = '<a class="button" style="width:100%;text-align:center;margin-bottom:6px" target="_blank" rel="noopener" href="'
                . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        if ( ! $links ) return;

        echo '<div class="cmh-panel"><h2>Diligenciar formato</h2>'
            . '<p style="font-size:12px;color:#646970;margin:-6px 0 10px">Se abre en una pestaña nueva con los datos de <strong>'
            . esc_html( $machine_code ) . '</strong> ya cargados.</p>'
            . implode( '', $links ) . '</div>';
    }

    /** Tareas de la máquina, con controles de estado para el técnico. */
    private static function render_tech_tasks( $machine_id ) {
        $tasks = self::tasks_for_machine( $machine_id );
        if ( ! $tasks ) {
            echo '<p style="color:#646970;font-size:13px;margin:0">No hay tareas para esta máquina.</p>';
            return;
        }
        $back = CMH_Admin::admin_url( 'cmh-tech', [ 'machine_id' => $machine_id ] );
        echo '<table class="widefat cmh"><thead><tr><th>Tarea</th><th>Vence</th><th>Estado</th><th>Formato</th><th>Cambiar estado</th></tr></thead><tbody>';
        foreach ( $tasks as $ta ) {
            echo '<tr>'
                . '<td><strong>' . esc_html( $ta->title ) . '</strong>' . ( $ta->notes ? '<br><span style="font-size:12px;color:#646970">' . esc_html( $ta->notes ) . '</span>' : '' ) . '</td>'
                . '<td>' . self::due_label( $ta->due_date ) . '</td>'
                . '<td>' . self::task_status_badge( $ta->status ) . '</td>'
                . '<td>' . self::open_form_control( $ta, $back ) . '</td>'
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

        // v2.1 — El reloj de horas sigue al estado de la tarea.
        CMH_Time::on_status_change( $task, $status, get_current_user_id() );

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

        // v0.11 — Recurrencia: un preventivo reprograma solo la próxima fecha.
        $msg  = 'Intervención registrada.';
        $auto = CMH_Schedule::recalc_next_maintenance(
            $machine_id, sanitize_text_field( $_POST['intervention_date'] ), $mtype
        );
        if ( $auto ) $msg .= ' Próximo mantenimiento: ' . $auto . '.';

        CMH_Admin::redirect_to(
            CMH_Admin::admin_url( 'cmh-tech', [ 'machine_id' => $machine_id ] ),
            $msg, $hm_warn
        );
    }
}
