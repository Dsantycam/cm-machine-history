<?php
/**
 * CMH_Time — v2.1 Conteo de horas trabajadas por los técnicos.
 *
 * El reloj corre por TRAMOS (tabla `cmh_task_time`): se abre uno cuando una tarea
 * pasa a «En progreso» y se cierra cuando pasa a «Completada» o vuelve a
 * «Pendiente». Una tarea puede tener varios tramos —se pausa y se retoma— y el
 * total de la tarea es la suma de sus tramos.
 *
 * Por qué una tabla y no una columna en `tasks`:
 *   - el técnico asignado a una tarea puede cambiar, y las horas ya trabajadas
 *     deben seguir contando para quien realmente las trabajó;
 *   - permite auditar y corregir un tramo suelto sin tocar los demás.
 *
 * Es tiempo de reloj, que es lo que se pidió: no descuenta almuerzos ni pausas
 * que el técnico no marque. Riesgo conocido: si olvida marcar «Completada», el
 * tramo seguiría abierto toda la noche. Dos defensas:
 *   1. un TOPE por tramo (ajuste `time_max_hours`, 12 h por defecto): al cerrar,
 *      lo que exceda se recorta y queda constancia en la nota del tramo;
 *   2. `close_stale()`, que corre en el cron diario y cierra los tramos que se
 *      quedaron abiertos más allá del tope.
 * En ambos casos el administrador puede corregir el tramo a mano.
 *
 * Visibilidad: todo esto es SOLO para administradores (`edit_others_posts`). El
 * panel del técnico no muestra horas ni tramos.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Time {

    /** Tope por tramo, en horas, si el ajuste no está definido. */
    const MAX_HOURS_DEFAULT = 12;

    /** Tramos que se listan en el detalle de la pantalla. */
    const DETAIL_LIMIT = 300;

    // =========================================================================
    // Init
    // =========================================================================

    public static function init() {
        add_action( 'admin_post_cm_save_time_segment',   [ __CLASS__, 'save_segment' ] );
        add_action( 'admin_post_cm_delete_time_segment', [ __CLASS__, 'delete_segment' ] );
        add_action( 'admin_post_cm_export_time',         [ __CLASS__, 'export_csv' ] );
    }

    private static function table() {
        return CMH_Core::tables()['task_time'];
    }

    // =========================================================================
    // Reglas del reloj
    // =========================================================================

    /** Tope por tramo en segundos. */
    public static function max_seconds() {
        $h = (int) CMH_Schedule::setting( 'time_max_hours' );
        if ( $h < 1 ) $h = self::MAX_HOURS_DEFAULT;
        return $h * HOUR_IN_SECONDS;
    }

    /** Tramo abierto de una tarea (opcionalmente de un técnico), o null. */
    public static function open_segment( $task_id, $user_id = 0 ) {
        global $wpdb;
        $sql = "SELECT * FROM " . self::table() . " WHERE task_id=%d AND ended_at IS NULL";
        $args = [ (int) $task_id ];
        if ( $user_id ) { $sql .= " AND user_id=%d"; $args[] = (int) $user_id; }
        $sql .= " ORDER BY id DESC LIMIT 1";
        return $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
    }

    /**
     * Punto único por donde pasan los cambios de estado de una tarea.
     * `$task` es la fila ANTES del cambio; `$actor_id` quien lo ejecuta.
     */
    public static function on_status_change( $task, $new_status, $actor_id = 0 ) {
        if ( ! $task ) return;

        if ( $new_status === 'en_progreso' ) {
            self::start( $task, self::worker_for( $task, $actor_id ) );
        } else {
            // «Completada» cierra; volver a «Pendiente» también (es una pausa).
            self::stop( (int) $task->id );
        }
    }

    /**
     * A quién se le cargan las horas. Normalmente el técnico asignado, pero si
     * quien mueve la tarea es un técnico (y no un administrador previsualizando),
     * manda él: cubre reasignaciones a medio camino y tareas sin asignar que un
     * técnico toma por su cuenta.
     */
    private static function worker_for( $task, $actor_id ) {
        $actor_id = (int) $actor_id;
        if ( $actor_id
            && user_can( $actor_id, 'cmh_tech' )
            && ! user_can( $actor_id, 'edit_others_posts' ) ) {
            return $actor_id;
        }
        return (int) ( $task->assigned_to ?: 0 );
    }

    /**
     * Abre un tramo. Idempotente: si ya hay uno corriendo para esa tarea y ese
     * técnico, no hace nada (así, reabrir el formato no duplica el conteo).
     */
    public static function start( $task, $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        // Sin técnico identificable no hay a quién cargarle las horas.
        if ( ! $user_id || ! $task ) return false;
        if ( self::open_segment( (int) $task->id, $user_id ) ) return false;

        $wpdb->insert( self::table(), [
            'task_id'    => (int) $task->id,
            'machine_id' => (int) $task->machine_id,
            'user_id'    => $user_id,
            'started_at' => current_time( 'mysql' ),
            'source'     => 'auto',
            'created_at' => current_time( 'mysql' ),
        ] );
        return true;
    }

    /** Cierra los tramos abiertos de una tarea. Devuelve cuántos cerró. */
    public static function stop( $task_id, $user_id = 0 ) {
        global $wpdb;
        $sql  = "SELECT * FROM " . self::table() . " WHERE task_id=%d AND ended_at IS NULL";
        $args = [ (int) $task_id ];
        if ( $user_id ) { $sql .= " AND user_id=%d"; $args[] = (int) $user_id; }

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
        foreach ( $rows as $r ) self::close_row( $r );
        return count( $rows );
    }

    /** Cierra un tramo aplicando el tope. */
    private static function close_row( $row, $at = null ) {
        global $wpdb;
        $at    = $at ?: current_time( 'mysql' );
        $start = strtotime( $row->started_at );
        $secs  = max( 0, strtotime( $at ) - $start );
        $cap   = self::max_seconds();

        $data = [];
        if ( $secs > $cap ) {
            $data['note'] = sprintf(
                'Recortado al tope de %s: entre inicio y cierre transcurrieron %s.',
                self::format( $cap ), self::format( $secs )
            );
            $secs = $cap;
            $at   = date( 'Y-m-d H:i:s', $start + $cap );
        }
        $data['ended_at'] = $at;
        $data['seconds']  = $secs;

        $wpdb->update( self::table(), $data, [ 'id' => (int) $row->id ] );
    }

    /**
     * Cierra tramos que quedaron abiertos más allá del tope. Lo llama el cron
     * diario: sin esto, un técnico que olvide cerrar deja el reloj corriendo y
     * el total del mes deja de tener sentido.
     */
    public static function close_stale() {
        global $wpdb;
        $limit = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::max_seconds() );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE ended_at IS NULL AND started_at < %s",
            $limit
        ) );
        foreach ( $rows as $r ) self::close_row( $r );
        return count( $rows );
    }

    // =========================================================================
    // Consultas
    // =========================================================================

    /** Segundos cerrados de una tarea. */
    public static function task_seconds( $task_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(seconds),0) FROM " . self::table() . " WHERE task_id=%d AND ended_at IS NOT NULL",
            (int) $task_id
        ) );
    }

    /**
     * Segundos por tarea para un grupo de tareas: [ task_id => segundos ].
     * Evita una consulta por fila al pintar la tabla de tareas de una máquina.
     */
    public static function seconds_by_task( array $task_ids ) {
        global $wpdb;
        $ids = array_filter( array_map( 'intval', $task_ids ) );
        if ( ! $ids ) return [];
        $in   = implode( ',', $ids );
        $rows = $wpdb->get_results(
            "SELECT task_id, COALESCE(SUM(seconds),0) AS secs FROM " . self::table() . "
             WHERE ended_at IS NOT NULL AND task_id IN ($in) GROUP BY task_id"
        );
        $out = [];
        foreach ( $rows as $r ) $out[ (int) $r->task_id ] = (int) $r->secs;
        return $out;
    }

    /** IDs de tareas con un tramo abierto ahora mismo. */
    public static function running_task_ids() {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col(
            "SELECT DISTINCT task_id FROM " . self::table() . " WHERE ended_at IS NULL"
        ) );
    }

    /** «3 h 25 min» a partir de segundos. */
    public static function format( $seconds ) {
        $seconds = max( 0, (int) $seconds );
        $h = intdiv( $seconds, 3600 );
        $m = intdiv( $seconds % 3600, 60 );
        if ( $h && $m ) return $h . ' h ' . $m . ' min';
        if ( $h )       return $h . ' h';
        return $m . ' min';
    }

    /** Horas decimales, para CSV y gráficas. */
    public static function hours( $seconds ) {
        return round( max( 0, (int) $seconds ) / 3600, 2 );
    }

    // =========================================================================
    // Filtros de la pantalla
    // =========================================================================

    /**
     * Una fecha 'Y-m-d' que además EXISTE, o '' si no. Comprobar solo la forma
     * dejaría pasar cosas como 2026-13-45, que luego viajan al SQL.
     */
    private static function valid_date( $s ) {
        $s = (string) $s;
        $d = DateTime::createFromFormat( 'Y-m-d', $s );
        return ( $d && $d->format( 'Y-m-d' ) === $s ) ? $s : '';
    }

    private static function filters() {
        $from = self::valid_date( sanitize_text_field( $_GET['from'] ?? '' ) );
        $to   = self::valid_date( sanitize_text_field( $_GET['to']   ?? '' ) );

        // Por defecto, el mes en curso.
        if ( ! $from ) $from = date( 'Y-m-01', strtotime( current_time( 'mysql' ) ) );
        if ( ! $to )   $to   = date( 'Y-m-d',  strtotime( current_time( 'mysql' ) ) );
        if ( $from > $to ) { $tmp = $from; $from = $to; $to = $tmp; }

        return [
            'from'       => $from,
            'to'         => $to,
            'user_id'    => intval( $_GET['user_id']    ?? 0 ),
            'company_id' => intval( $_GET['company_id'] ?? 0 ),
            'machine_id' => intval( $_GET['machine_id'] ?? 0 ),
        ];
    }

    /**
     * WHERE común. Único lugar donde se arma el filtro, para que la tabla, las
     * gráficas y el CSV no puedan divergir.
     */
    private static function where( $f ) {
        global $wpdb;
        $w = [ 'tt.ended_at IS NOT NULL' ];
        $w[] = $wpdb->prepare( 'DATE(tt.started_at) BETWEEN %s AND %s', $f['from'], $f['to'] );
        if ( $f['user_id'] )    $w[] = $wpdb->prepare( 'tt.user_id=%d',    $f['user_id'] );
        if ( $f['machine_id'] ) $w[] = $wpdb->prepare( 'tt.machine_id=%d', $f['machine_id'] );
        if ( $f['company_id'] ) $w[] = $wpdb->prepare( 'm.company_id=%d',  $f['company_id'] );
        return implode( ' AND ', $w );
    }

    private static function from_sql() {
        $t = CMH_Core::tables();
        return self::table() . " tt
            LEFT JOIN {$t['machines']} m ON m.id = tt.machine_id
            LEFT JOIN {$t['tasks']}    ta ON ta.id = tt.task_id";
    }

    /** Totales por técnico. */
    private static function by_tech( $f ) {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT tt.user_id,
                    SUM(tt.seconds)            AS secs,
                    COUNT(*)                   AS segments,
                    COUNT(DISTINCT tt.task_id) AS tasks,
                    COUNT(DISTINCT tt.machine_id) AS machines
             FROM " . self::from_sql() . "
             WHERE " . self::where( $f ) . "
             GROUP BY tt.user_id ORDER BY secs DESC"
        );
    }

    /** Totales por máquina. */
    private static function by_machine( $f ) {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT tt.machine_id, m.machine_code, m.brand, m.model,
                    SUM(tt.seconds) AS secs, COUNT(DISTINCT tt.task_id) AS tasks
             FROM " . self::from_sql() . "
             WHERE " . self::where( $f ) . "
             GROUP BY tt.machine_id, m.machine_code, m.brand, m.model
             ORDER BY secs DESC LIMIT 25"
        );
    }

    /** Detalle de tramos. */
    private static function segments( $f, $limit = self::DETAIL_LIMIT ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT tt.*, m.machine_code, ta.title AS task_title
             FROM " . self::from_sql() . "
             WHERE " . self::where( $f ) . "
             ORDER BY tt.started_at DESC, tt.id DESC LIMIT %d",
            $limit
        ) );
    }

    // =========================================================================
    // Pantalla «Máquinas → Horas técnicos»
    // =========================================================================

    public static function page_time() {
        if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( 'Sin permisos.' );
        global $wpdb; $t = CMH_Core::tables();

        $f = self::filters();
        CMH_Admin::page_header( 'Equipo técnico', [
            [ 'label' => 'Máquinas', 'url' => CMH_Admin::admin_url( CMH_SLUG ) ],
            [ 'label' => 'Equipo técnico' ],
        ] );

        self::render_filter_bar( $f );

        // v2.3 — Vista general de las tareas del equipo, sin entrar máquina por máquina.
        self::render_team_tasks( $f );

        $techs = self::by_tech( $f );
        $total = 0; foreach ( $techs as $r ) $total += (int) $r->secs;

        $running = $wpdb->get_results(
            "SELECT tt.*, m.machine_code, ta.title AS task_title
             FROM " . self::from_sql() . " WHERE tt.ended_at IS NULL
             ORDER BY tt.started_at ASC"
        );

        // ── KPIs ─────────────────────────────────────────────────────────────
        echo '<div class="cmh-grid">';
        CMH_Admin::metric_card( 'Horas del periodo', self::format( $total ), self::hours( $total ) . ' h', 'blue' );
        CMH_Admin::metric_card( 'Técnicos con horas', count( $techs ), 'en el periodo' );
        $tasks_n = 0; foreach ( $techs as $r ) $tasks_n += (int) $r->tasks;
        CMH_Admin::metric_card( 'Tareas trabajadas', $tasks_n, 'suma por técnico' );
        CMH_Admin::metric_card( 'En curso ahora', count( $running ), $running ? 'relojes corriendo' : 'ninguno', $running ? 'warn' : '' );
        echo '</div>';

        // ── En curso ahora ───────────────────────────────────────────────────
        if ( $running ) {
            $now = strtotime( current_time( 'mysql' ) );
            echo '<div class="cmh-panel"><h2>En curso ahora</h2>'
                . '<p style="font-size:12px;color:#646970;margin:-6px 0 10px">Tramos abiertos: la tarea está «En progreso» y todavía no se marca como completada. '
                . 'Estas horas aún no suman en los totales de arriba.</p>'
                . '<table class="widefat cmh"><thead><tr><th>Técnico</th><th>Máquina</th><th>Tarea</th><th>Desde</th><th>Va corriendo</th></tr></thead><tbody>';
            foreach ( $running as $r ) {
                $elapsed = max( 0, $now - strtotime( $r->started_at ) );
                $over    = $elapsed > self::max_seconds();
                echo '<tr>'
                    . '<td>' . esc_html( self::user_name( $r->user_id ) ) . '</td>'
                    . '<td>' . esc_html( $r->machine_code ?: '—' ) . '</td>'
                    . '<td>' . esc_html( $r->task_title ?: '—' ) . '</td>'
                    . '<td>' . esc_html( $r->started_at ) . '</td>'
                    . '<td>' . ( $over
                        ? '<span class="cmh-badge" style="background:#fce8e8;color:#d63638">' . esc_html( self::format( $elapsed ) ) . ' — pasa del tope</span>'
                        : esc_html( self::format( $elapsed ) ) ) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
        }

        // ── Por técnico ──────────────────────────────────────────────────────
        echo '<div class="cmh-panel"><h2>Horas por técnico</h2>';
        if ( $techs ) {
            $rows = [];
            foreach ( $techs as $r ) {
                $rows[] = [
                    'label' => self::user_name( $r->user_id ),
                    'value' => self::hours( $r->secs ),
                    'color' => '#2271b1',
                    'note'  => self::format( $r->secs ),
                    'tip'   => self::user_name( $r->user_id ) . ' — ' . self::format( $r->secs )
                        . ' en ' . intval( $r->tasks ) . ' tarea(s)',
                ];
            }
            echo CMH_Charts::hbars( $rows, [ 'label' => 'Horas trabajadas por técnico' ] );

            echo '<table class="widefat cmh" style="margin-top:14px"><thead><tr>'
                . '<th>Técnico</th><th>Horas</th><th>Decimal</th><th>Tareas</th><th>Máquinas</th><th>Tramos</th><th>Promedio/tarea</th>'
                . '</tr></thead><tbody>';
            foreach ( $techs as $r ) {
                $avg = (int) $r->tasks ? (int) round( $r->secs / (int) $r->tasks ) : 0;
                echo '<tr>'
                    . '<td><strong>' . esc_html( self::user_name( $r->user_id ) ) . '</strong></td>'
                    . '<td>' . esc_html( self::format( $r->secs ) ) . '</td>'
                    . '<td>' . esc_html( number_format_i18n( self::hours( $r->secs ), 2 ) ) . '</td>'
                    . '<td>' . intval( $r->tasks ) . '</td>'
                    . '<td>' . intval( $r->machines ) . '</td>'
                    . '<td>' . intval( $r->segments ) . '</td>'
                    . '<td>' . esc_html( self::format( $avg ) ) . '</td>'
                    . '</tr>';
            }
            echo '<tr style="background:#f6f7f7;font-weight:700"><td>Total</td><td>' . esc_html( self::format( $total ) ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( self::hours( $total ), 2 ) ) . '</td><td colspan="4"></td></tr>';
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#646970;font-size:13px;margin:0">Sin horas registradas en este periodo.</p>';
        }
        echo '</div>';

        // ── Por máquina ──────────────────────────────────────────────────────
        $machines = self::by_machine( $f );
        if ( $machines ) {
            echo '<div class="cmh-panel"><h2>Horas por máquina</h2>'
                . '<table class="widefat cmh"><thead><tr><th>Máquina</th><th>Equipo</th><th>Horas</th><th>Tareas</th></tr></thead><tbody>';
            foreach ( $machines as $r ) {
                echo '<tr>'
                    . '<td><a href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => (int) $r->machine_id ] ) ) . '">'
                    . esc_html( $r->machine_code ?: '—' ) . '</a></td>'
                    . '<td>' . esc_html( trim( ( $r->brand ?: '' ) . ' ' . ( $r->model ?: '' ) ) ?: '—' ) . '</td>'
                    . '<td>' . esc_html( self::format( $r->secs ) ) . '</td>'
                    . '<td>' . intval( $r->tasks ) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
        }

        // ── Detalle de tramos ────────────────────────────────────────────────
        self::render_segments( $f );

        echo '</div>'; // .wrap abierto por page_header
    }

    /**
     * v2.3 — Todas las tareas del equipo en un solo sitio.
     *
     * Antes había que entrar máquina por máquina para saber qué tenía pendiente
     * el equipo. Vive aquí, junto a las horas, porque es la pantalla del equipo
     * técnico. Muestra lo abierto por defecto; lo completado se pide aparte.
     */
    public static function render_team_tasks( $f ) {
        global $wpdb; $t = CMH_Core::tables();

        $show = ( ( $_GET['tasks'] ?? '' ) === 'todas' ) ? 'todas' : 'abiertas';

        $where = [];
        if ( $show === 'abiertas' ) $where[] = "ta.status <> 'completada'";
        if ( $f['user_id'] )    $where[] = $wpdb->prepare( 'ta.assigned_to=%d', $f['user_id'] );
        if ( $f['machine_id'] ) $where[] = $wpdb->prepare( 'ta.machine_id=%d', $f['machine_id'] );
        if ( $f['company_id'] ) $where[] = $wpdb->prepare( 'm.company_id=%d', $f['company_id'] );
        $sql_where = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

        $rows = $wpdb->get_results(
            "SELECT ta.*, m.machine_code, c.name AS company_name
             FROM {$t['tasks']} ta
             LEFT JOIN {$t['machines']}  m ON m.id = ta.machine_id
             LEFT JOIN {$t['companies']} c ON c.id = m.company_id
             $sql_where
             ORDER BY FIELD(ta.status,'en_progreso','pendiente','completada'),
                      ta.due_date IS NULL, ta.due_date ASC, ta.id DESC
             LIMIT 300"
        );

        $secs    = self::seconds_by_task( wp_list_pluck( $rows, 'id' ) );
        $running = self::running_task_ids();
        $today   = current_time( 'Y-m-d' );

        // Un vistazo rápido antes de la tabla.
        $open = $late = $doing = 0;
        foreach ( $rows as $r ) {
            if ( $r->status === 'completada' ) continue;
            $open++;
            if ( $r->status === 'en_progreso' ) $doing++;
            if ( $r->due_date && $r->due_date < $today ) $late++;
        }

        echo '<div class="cmh-panel"><div class="cmh-toolbar"><h2>Tareas del equipo</h2>'
            . '<div class="cmh-view-switch">'
            . '<a class="button button-small' . ( $show === 'abiertas' ? ' active' : '' ) . '" href="'
            . esc_url( add_query_arg( 'tasks', 'abiertas', self::page_url( $f ) ) ) . '">Abiertas</a>'
            . '<a class="button button-small' . ( $show === 'todas' ? ' active' : '' ) . '" href="'
            . esc_url( add_query_arg( 'tasks', 'todas', self::page_url( $f ) ) ) . '">Todas</a>'
            . '</div></div>';

        echo '<p class="cmh-hint">' . intval( $open ) . ' abierta(s) · ' . intval( $doing ) . ' en curso · '
            . ( $late
                ? '<strong style="color:#d63638">' . intval( $late ) . ' vencida(s)</strong>'
                : 'ninguna vencida' )
            . ' · usa los filtros de arriba para acotar por técnico o empresa.</p>';

        if ( ! $rows ) {
            echo '<p class="cmh-muted">No hay tareas que mostrar con estos filtros.</p></div>';
            return;
        }

        echo '<div class="cmh-table-scroll"><table class="widefat cmh"><thead><tr>'
            . '<th>Tarea</th><th>Máquina</th><th>Técnico</th><th>Vence</th>'
            . '<th>Estado</th><th class="cmh-num">Horas</th><th></th>'
            . '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $late_row = ( $r->status !== 'completada' && $r->due_date && $r->due_date < $today );
            $back     = self::page_url( $f );

            echo '<tr>'
                . '<td><strong>' . esc_html( $r->title ) . '</strong>'
                . ( ( $r->source ?? '' ) === 'auto' ? ' <span class="cmh-badge cmh-badge-xs" style="background:#e7f0f7;color:#2271b1">Auto</span>' : '' )
                . '</td>'
                . '<td class="cmh-nowrap">' . ( $r->machine_id
                    ? '<a href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => (int) $r->machine_id ] ) ) . '">'
                      . esc_html( $r->machine_code ?: '—' ) . '</a>'
                    : '—' )
                . ( $r->company_name ? '<br><span class="cmh-muted">' . esc_html( $r->company_name ) . '</span>' : '' ) . '</td>'
                . '<td>' . esc_html( $r->assigned_to ? self::user_name( $r->assigned_to ) : '— Sin asignar —' ) . '</td>'
                . '<td class="cmh-nowrap">' . ( $r->due_date
                    ? ( $late_row
                        ? '<span style="color:#d63638;font-weight:600">' . esc_html( $r->due_date ) . '</span>'
                        : esc_html( $r->due_date ) )
                    : '<span class="cmh-muted">—</span>' ) . '</td>'
                . '<td>' . CMH_Tech::task_status_badge( $r->status )
                . ( in_array( (int) $r->id, $running, true ) ? ' <span class="cmh-badge cmh-badge-xs" style="background:#e7f0fb;color:#1c4d80">reloj</span>' : '' ) . '</td>'
                . '<td class="cmh-num">' . esc_html( isset( $secs[ (int) $r->id ] ) ? self::format( $secs[ (int) $r->id ] ) : '—' ) . '</td>'
                . '<td class="cmh-row-actions">' . CMH_Tech::complete_button( $r, $back ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';

        if ( count( $rows ) >= 300 )
            echo '<p class="cmh-hint">Se muestran las 300 primeras. Afina los filtros para ver el resto.</p>';

        echo '</div>';
    }

    private static function render_filter_bar( $f ) {
        global $wpdb; $t = CMH_Core::tables();

        $companies = $wpdb->get_results( "SELECT id, name FROM {$t['companies']} ORDER BY name ASC" );

        echo '<div class="cmh-panel"><form method="get" style="margin:0"><div class="cmh-form-grid">'
            . '<input type="hidden" name="page" value="' . esc_attr( CMH_SLUG . '-time' ) . '">'
            . '<label>Desde<input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"></label>'
            . '<label>Hasta<input type="date" name="to" value="' . esc_attr( $f['to'] ) . '"></label>'
            . '<label>Técnico<select name="user_id"><option value="0">— Todos —</option>';
        foreach ( CMH_Tech::technicians() as $u )
            echo '<option value="' . intval( $u->ID ) . '" ' . selected( $f['user_id'], $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
        echo '</select></label>'
            . '<label>Empresa<select name="company_id"><option value="0">— Todas —</option>';
        foreach ( $companies as $c )
            echo '<option value="' . intval( $c->id ) . '" ' . selected( $f['company_id'], $c->id, false ) . '>' . esc_html( $c->name ) . '</option>';
        echo '</select></label>'
            . '</div>';

        if ( $f['machine_id'] ) echo '<input type="hidden" name="machine_id" value="' . intval( $f['machine_id'] ) . '">';

        echo '<div style="display:flex;gap:8px;margin-top:12px">'
            . '<button class="button button-primary">Aplicar</button>'
            . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-time' ) ) . '">Limpiar</a>'
            . '<a class="button" href="' . esc_url( self::export_url( $f ) ) . '">Exportar CSV</a>'
            . '</div></form></div>';
    }

    private static function render_segments( $f ) {
        $rows = self::segments( $f );

        echo '<div class="cmh-panel"><h2>Detalle de tramos</h2>'
            . '<p style="font-size:12px;color:#646970;margin:-6px 0 10px">Cada fila es un arranque y su cierre. '
            . 'Puedes corregir las horas de un tramo o eliminarlo si se abrió por error.</p>';

        if ( ! $rows ) {
            echo '<p style="color:#646970;font-size:13px;margin:0">Sin tramos en este periodo.</p></div>';
            return;
        }

        echo '<table class="widefat cmh"><thead><tr>'
            . '<th>Técnico</th><th>Máquina</th><th>Tarea</th><th>Inicio</th><th>Fin</th><th>Duración</th><th>Nota</th><th></th>'
            . '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            echo '<tr>'
                . '<td>' . esc_html( self::user_name( $r->user_id ) ) . '</td>'
                . '<td>' . esc_html( $r->machine_code ?: '—' ) . '</td>'
                . '<td>' . esc_html( $r->task_title ?: '—' )
                . ( $r->source === 'manual' ? ' <span class="cmh-badge" style="background:#f0f0f1;color:#3c434a">Manual</span>' : '' ) . '</td>'
                . '<td>' . esc_html( $r->started_at ) . '</td>'
                . '<td>' . esc_html( $r->ended_at ?: '—' ) . '</td>'
                . '<td><strong>' . esc_html( self::format( $r->seconds ) ) . '</strong></td>'
                . '<td style="font-size:12px;color:#646970">' . esc_html( (string) $r->note ) . '</td>'
                . '<td style="display:flex;gap:6px">'
                . '<button type="button" class="button button-small cmh-btn-toggle-edit" data-target="cmh-seg-' . intval( $r->id ) . '">Editar</button>'
                . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'¿Eliminar este tramo? Las horas dejarán de contar.\')">'
                . '<input type="hidden" name="action" value="cm_delete_time_segment">'
                . '<input type="hidden" name="segment_id" value="' . intval( $r->id ) . '">'
                . '<input type="hidden" name="redirect_to" value="' . esc_url( self::page_url( $f ) ) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'cmh_action' ) . '">'
                . '<button class="button button-small" style="color:#d63638;border-color:#d63638">Eliminar</button>'
                . '</form></td></tr>';

            // Edición inline del tramo.
            echo '<tr id="cmh-seg-' . intval( $r->id ) . '" style="display:none"><td colspan="8" style="background:#f6f7f7">';
            CMH_Admin::form_start( 'cm_save_time_segment' );
            echo '<input type="hidden" name="segment_id" value="' . intval( $r->id ) . '">'
                . '<input type="hidden" name="redirect_to" value="' . esc_url( self::page_url( $f ) ) . '">'
                . '<div class="cmh-form-grid">'
                . '<label>Inicio<input type="datetime-local" name="started_at" value="' . esc_attr( self::to_input( $r->started_at ) ) . '" required></label>'
                . '<label>Fin<input type="datetime-local" name="ended_at" value="' . esc_attr( self::to_input( $r->ended_at ) ) . '"></label>'
                . '<label>Nota<input type="text" name="note" value="' . esc_attr( (string) $r->note ) . '" placeholder="Motivo de la corrección"></label>'
                . '</div>'
                . '<p style="font-size:12px;color:#646970;margin:8px 0">Deja el fin vacío para reabrir el tramo. La duración se recalcula sola a partir de inicio y fin.</p>'
                . '<button class="button button-primary">Guardar tramo</button></form></td></tr>';
        }
        echo '</tbody></table>';

        if ( count( $rows ) >= self::DETAIL_LIMIT )
            echo '<p style="font-size:12px;color:#646970;margin:10px 0 0">Se muestran los ' . intval( self::DETAIL_LIMIT )
                . ' tramos más recientes del periodo. Afina el filtro o exporta el CSV para verlos todos.</p>';

        echo '</div>';
    }

    // =========================================================================
    // Helpers de pantalla
    // =========================================================================

    private static function user_name( $user_id ) {
        $name = get_the_author_meta( 'display_name', (int) $user_id );
        return $name ?: ( 'Usuario #' . intval( $user_id ) );
    }

    /** 'Y-m-d H:i:s' → valor de un <input type="datetime-local">. */
    private static function to_input( $mysql ) {
        if ( ! $mysql ) return '';
        return date( 'Y-m-d\TH:i', strtotime( $mysql ) );
    }

    /** Valor de un <input type="datetime-local"> → 'Y-m-d H:i:s', o null. */
    private static function from_input( $value ) {
        $value = sanitize_text_field( $value );
        if ( ! $value ) return null;
        $ts = strtotime( str_replace( 'T', ' ', $value ) );
        return $ts ? date( 'Y-m-d H:i:s', $ts ) : null;
    }

    private static function page_url( $f ) {
        return CMH_Admin::admin_url( CMH_SLUG . '-time', array_filter( [
            'from'       => $f['from'],
            'to'         => $f['to'],
            'user_id'    => $f['user_id'],
            'company_id' => $f['company_id'],
            'machine_id' => $f['machine_id'],
        ] ) );
    }

    private static function export_url( $f ) {
        return wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( array_filter( [
            'action'     => 'cm_export_time',
            'from'       => $f['from'],
            'to'         => $f['to'],
            'user_id'    => $f['user_id'],
            'company_id' => $f['company_id'],
            'machine_id' => $f['machine_id'],
        ] ) ) ), 'cmh_action' );
    }

    // =========================================================================
    // Handlers
    // =========================================================================

    public static function save_segment() {
        CMH_Admin::check();
        global $wpdb;

        $id  = intval( $_POST['segment_id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d", $id ) );
        if ( ! $row ) wp_die( 'Tramo no encontrado.' );

        $started = self::from_input( $_POST['started_at'] ?? '' );
        $ended   = self::from_input( $_POST['ended_at']   ?? '' );
        if ( ! $started )
            CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-time' ), '', 'El inicio del tramo es obligatorio.' );

        if ( $ended && $ended < $started )
            CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-time' ), '', 'El fin no puede ser anterior al inicio.' );

        $wpdb->update( self::table(), [
            'started_at' => $started,
            'ended_at'   => $ended,
            'seconds'    => $ended ? max( 0, strtotime( $ended ) - strtotime( $started ) ) : null,
            'note'       => sanitize_text_field( $_POST['note'] ?? '' ) ?: null,
            'source'     => 'manual',
        ], [ 'id' => $id ] );

        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-time' ), 'Tramo actualizado.' );
    }

    public static function delete_segment() {
        CMH_Admin::check();
        global $wpdb;
        $id = intval( $_POST['segment_id'] ?? 0 );
        $wpdb->delete( self::table(), [ 'id' => $id ] );
        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-time' ), 'Tramo eliminado.' );
    }

    public static function export_csv() {
        if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'cmh_action' );

        $f    = self::filters();
        $rows = self::segments( $f, 5000 );

        CMH_Admin::csv_headers( 'horas-tecnicos-' . $f['from'] . '_' . $f['to'] . '.csv' );
        CMH_Admin::csv_row( [ 'Técnico', 'Máquina', 'Tarea', 'Inicio', 'Fin', 'Horas', 'Duración', 'Origen', 'Nota' ] );
        foreach ( $rows as $r ) {
            CMH_Admin::csv_row( [
                self::user_name( $r->user_id ),
                $r->machine_code ?: '',
                $r->task_title ?: '',
                $r->started_at,
                $r->ended_at ?: '',
                self::hours( $r->seconds ),
                self::format( $r->seconds ),
                $r->source,
                (string) $r->note,
            ] );
        }
        exit;
    }
}
