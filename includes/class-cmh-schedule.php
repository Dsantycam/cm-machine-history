<?php
/**
 * CMH_Schedule — v0.11 Mantenimiento recurrente y alertas automáticas.
 *
 * Dos piezas complementarias:
 *
 * 1. RECURRENCIA — cada máquina puede tener `maintenance_interval_days`. Al registrar
 *    un mantenimiento preventivo (desde el admin, el panel del técnico o Forminator)
 *    se recalcula `next_maintenance_date` = fecha de la intervención + intervalo.
 *    Una fecha escrita a mano por el usuario siempre gana sobre el cálculo.
 *
 * 2. ALERTAS — un job diario de WP-Cron (`cmh_daily_maintenance_event`) revisa qué
 *    mantenimientos y tareas están próximos o vencidos, opcionalmente auto-genera la
 *    tarea de mantenimiento, y envía por wp_mail un resumen al administrador y un
 *    correo individual a cada técnico con lo que le corresponde.
 *
 * Todo es configurable desde «Máquinas → Ajustes» (option `cmh_settings`).
 *
 * Nota WP-Cron: en sitios de bajo tráfico el cron de WordPress dispara tarde porque
 * depende de las visitas. Por eso el job es idempotente (no repite envíos el mismo
 * día) y la página de ajustes incluye un botón para ejecutarlo manualmente.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Schedule {

    /** Nombre del evento de WP-Cron. */
    const CRON_HOOK = 'cmh_daily_maintenance_event';

    /** Option donde viven los ajustes del plugin. */
    const OPTION = 'cmh_settings';

    /** Hora local (0-23) a la que se programa el job diario. */
    const CRON_HOUR = 7;

    /** Intervalos sugeridos en el selector de recurrencia (días => etiqueta). */
    const INTERVAL_PRESETS = [
        30  => 'Mensual (30 días)',
        60  => 'Bimestral (60 días)',
        90  => 'Trimestral (90 días)',
        120 => 'Cuatrimestral (120 días)',
        180 => 'Semestral (180 días)',
        365 => 'Anual (365 días)',
    ];

    // =========================================================================
    // Init
    // =========================================================================

    public static function init() {
        add_action( self::CRON_HOOK,                  [ __CLASS__, 'run_daily' ] );
        add_action( 'admin_post_cm_save_settings',    [ __CLASS__, 'save_settings' ] );
        add_action( 'admin_post_cm_run_alerts',       [ __CLASS__, 'run_alerts_now' ] );
    }

    // =========================================================================
    // Ajustes
    // =========================================================================

    public static function defaults() {
        return [
            'alerts_enabled'    => 1,   // enviar correos
            'alert_days_before' => 7,   // anticipación en días
            'alert_to_admin'    => 1,   // correo resumen al admin del sitio
            'alert_emails'      => '',  // destinatarios extra, separados por coma
            'alert_to_techs'    => 1,   // correo individual a cada técnico asignado
            'auto_task'         => 1,   // auto-generar tarea al vencer el mantenimiento
            'auto_task_title'   => 'Mantenimiento preventivo programado',
            'last_run'          => '',  // 'Y-m-d H:i:s' (hora local del sitio)
            'last_summary'      => '',  // resumen legible de la última corrida
        ];
    }

    /** Ajustes completos (defaults + guardados). */
    public static function settings() {
        $saved = get_option( self::OPTION, [] );
        return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
    }

    public static function setting( $key ) {
        $s = self::settings();
        return $s[ $key ] ?? null;
    }

    private static function update_settings( array $patch ) {
        update_option( self::OPTION, array_merge( self::settings(), $patch ) );
    }

    // =========================================================================
    // WP-Cron
    // =========================================================================

    /** Programa el job diario si aún no existe. Idempotente. */
    public static function schedule_cron() {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) return;
        wp_schedule_event( self::next_run_timestamp(), 'daily', self::CRON_HOOK );
    }

    /** Quita el job (desactivación / desinstalación). */
    public static function unschedule_cron() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /** Timestamp UTC de la próxima CRON_HOUR local. */
    private static function next_run_timestamp() {
        $now    = current_time( 'timestamp' );
        $today  = strtotime( date( 'Y-m-d', $now ) . ' ' . sprintf( '%02d:00:00', self::CRON_HOUR ) );
        $local  = $today > $now ? $today : $today + DAY_IN_SECONDS;
        return $local - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
    }

    /** Próxima ejecución en hora local legible, o '' si no hay job programado. */
    public static function next_run_label() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( ! $ts ) return '';
        return date_i18n( 'd/m/Y H:i', $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
    }

    // =========================================================================
    // Recurrencia de mantenimiento
    // =========================================================================

    /**
     * Recalcula `next_maintenance_date` tras registrar una intervención.
     *
     * Solo actúa si el tipo es preventivo y la máquina tiene intervalo configurado.
     * Si el usuario escribió una fecha a mano en el formulario ($manual_date), esa
     * gana y no se recalcula nada.
     *
     * @return string Fecha calculada 'Y-m-d', o '' si no aplica.
     */
    public static function recalc_next_maintenance( $machine_id, $intervention_date, $maintenance_type, $manual_date = '' ) {
        if ( $manual_date ) return '';
        if ( sanitize_key( $maintenance_type ) !== 'preventivo' ) return '';

        global $wpdb; $t = CMH_Core::tables();
        $interval = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT maintenance_interval_days FROM {$t['machines']} WHERE id=%d", $machine_id
        ) );
        if ( $interval < 1 ) return '';

        $base = $intervention_date ?: current_time( 'Y-m-d' );
        $next = date( 'Y-m-d', strtotime( $base . ' +' . $interval . ' days' ) );

        $wpdb->update( $t['machines'],
            [ 'next_maintenance_date' => $next, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $machine_id ]
        );
        return $next;
    }

    /** Normaliza el intervalo que llega de un formulario (0/vacío => null). */
    public static function sanitize_interval( $value ) {
        $days = (int) $value;
        return $days > 0 ? min( $days, 3650 ) : null;
    }

    /** Etiqueta legible del intervalo de una máquina. */
    public static function interval_label( $days ) {
        $days = (int) $days;
        if ( $days < 1 ) return 'Sin recurrencia';
        return self::INTERVAL_PRESETS[ $days ] ?? ( 'Cada ' . $days . ' días' );
    }

    /** <select> reutilizable de intervalo, con opción «otro» numérica. */
    public static function interval_field( $current = 0, $name = 'maintenance_interval_days' ) {
        $current = (int) $current;
        $is_preset = isset( self::INTERVAL_PRESETS[ $current ] );
        $out  = '<select name="' . esc_attr( $name ) . '" class="cmh-interval-select">';
        $out .= '<option value="0">— Sin recurrencia —</option>';
        foreach ( self::INTERVAL_PRESETS as $d => $label )
            $out .= '<option value="' . $d . '" ' . selected( $current, $d, false ) . '>' . esc_html( $label ) . '</option>';
        $out .= '<option value="custom" ' . selected( $current > 0 && ! $is_preset, true, false ) . '>Otro (días)…</option>';
        $out .= '</select>';
        $out .= '<input type="number" min="1" max="3650" step="1" name="' . esc_attr( $name ) . '_custom" '
              . 'class="cmh-interval-custom" placeholder="días" style="margin-top:6px;'
              . ( $current > 0 && ! $is_preset ? '' : 'display:none' ) . '" '
              . 'value="' . esc_attr( $current > 0 && ! $is_preset ? $current : '' ) . '">';
        return $out;
    }

    /**
     * Lee el intervalo de $_POST considerando el select + el campo «otro».
     * Devuelve int|null listo para guardar.
     */
    public static function interval_from_post( $name = 'maintenance_interval_days' ) {
        $raw = $_POST[ $name ] ?? '';
        if ( $raw === 'custom' ) $raw = $_POST[ $name . '_custom' ] ?? 0;
        return self::sanitize_interval( $raw );
    }

    // =========================================================================
    // Consultas de vencimientos
    // =========================================================================

    /**
     * Máquinas con mantenimiento programado dentro de $days días (incluye vencidas).
     * Se excluyen las inactivas y fuera de servicio: no tiene sentido alertar por ellas.
     */
    public static function due_machines( $days ) {
        global $wpdb; $t = CMH_Core::tables();
        $limit = date( 'Y-m-d', strtotime( '+' . max( 0, (int) $days ) . ' days', current_time( 'timestamp' ) ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.machine_code, m.brand, m.model, m.status,
                    m.next_maintenance_date, m.maintenance_interval_days,
                    c.name company_name, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             WHERE m.next_maintenance_date IS NOT NULL
               AND m.next_maintenance_date <= %s
               AND m.status NOT IN ('inactiva','fuera_servicio')
             ORDER BY m.next_maintenance_date ASC",
            $limit
        ) );
    }

    /** Tareas abiertas que vencen dentro de $days días (incluye vencidas). */
    public static function due_tasks( $days ) {
        global $wpdb; $t = CMH_Core::tables();
        $limit = date( 'Y-m-d', strtotime( '+' . max( 0, (int) $days ) . ' days', current_time( 'timestamp' ) ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ta.id, ta.machine_id, ta.title, ta.due_date, ta.status, ta.assigned_to,
                    m.machine_code, c.name company_name
             FROM {$t['tasks']} ta
             JOIN {$t['machines']}  m ON m.id=ta.machine_id
             JOIN {$t['companies']} c ON c.id=m.company_id
             WHERE ta.status <> 'completada'
               AND ta.due_date IS NOT NULL
               AND ta.due_date <= %s
             ORDER BY ta.due_date ASC",
            $limit
        ) );
    }

    // =========================================================================
    // Job diario
    // =========================================================================

    /**
     * Corre el ciclo completo: auto-tareas + correos.
     *
     * @param bool $force Ignora la marca de «ya corrió hoy» (botón manual).
     * @return array Resumen [ 'machines', 'tasks', 'created', 'emails', 'skipped' ]
     */
    public static function run_daily( $force = false ) {
        $s   = self::settings();
        $today = current_time( 'Y-m-d' );

        // Idempotencia: un solo ciclo por día salvo ejecución manual.
        if ( ! $force && $s['last_run'] && substr( $s['last_run'], 0, 10 ) === $today ) {
            return [ 'machines' => 0, 'tasks' => 0, 'created' => 0, 'emails' => 0, 'skipped' => true ];
        }

        $days     = max( 0, (int) $s['alert_days_before'] );
        $machines = self::due_machines( $days );
        $created  = ! empty( $s['auto_task'] ) ? self::create_auto_tasks( $machines ) : 0;

        // Las tareas se consultan después de auto-generarlas para que entren al correo.
        $tasks  = self::due_tasks( $days );
        $emails = 0;

        if ( ! empty( $s['alerts_enabled'] ) && ( $machines || $tasks ) ) {
            $emails += self::send_admin_digest( $machines, $tasks, $days ) ? 1 : 0;
            if ( ! empty( $s['alert_to_techs'] ) ) $emails += self::send_tech_digests( $machines, $tasks, $days );
        }

        $summary = sprintf(
            '%d mantenimiento(s) y %d tarea(s) en ventana de %d días · %d tarea(s) autogenerada(s) · %d correo(s) enviado(s).',
            count( $machines ), count( $tasks ), $days, $created, $emails
        );
        self::update_settings( [ 'last_run' => current_time( 'mysql' ), 'last_summary' => $summary ] );
        CMH_Core::log( 'info', null, '', null, 'Alertas de mantenimiento: ' . $summary );

        return [
            'machines' => count( $machines ), 'tasks' => count( $tasks ),
            'created'  => $created, 'emails' => $emails, 'skipped' => false,
        ];
    }

    /**
     * Crea una tarea por cada máquina con mantenimiento próximo/vencido.
     *
     * Idempotente: se identifica por (machine_id, source='auto', due_date), así que
     * reejecutar el job el mismo ciclo no duplica tareas. Si hay técnicos asignados,
     * la tarea queda para el primero de ellos.
     */
    private static function create_auto_tasks( $machines ) {
        global $wpdb; $t = CMH_Core::tables();
        $title_base = self::setting( 'auto_task_title' ) ?: 'Mantenimiento preventivo programado';
        $created    = 0;

        foreach ( $machines as $m ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$t['tasks']} WHERE machine_id=%d AND source='auto' AND due_date=%s LIMIT 1",
                $m->id, $m->next_maintenance_date
            ) );
            if ( $exists ) continue;

            $techs = CMH_Tech::assigned_user_ids( (int) $m->id );

            $wpdb->insert( $t['tasks'], [
                'machine_id'  => (int) $m->id,
                'assigned_to' => $techs ? (int) $techs[0] : null,
                'title'       => $title_base . ' — ' . $m->machine_code,
                'notes'       => 'Tarea generada automáticamente por la fecha de próximo mantenimiento ('
                               . $m->next_maintenance_date . ')'
                               . ( $m->maintenance_interval_days ? '. Recurrencia: ' . self::interval_label( $m->maintenance_interval_days ) . '.' : '.' ),
                'due_date'    => $m->next_maintenance_date,
                'status'      => 'pendiente',
                'source'      => 'auto',
                'created_by'  => null,
            ] );
            if ( ! $wpdb->last_error ) $created++;
        }
        return $created;
    }

    // =========================================================================
    // Correos
    // =========================================================================

    /**
     * Envía HTML sin dejar el filtro de content-type pegado para otros plugins.
     *
     * v1.0.1 — Además del filtro, se pasa la cabecera `Content-Type` explícita:
     * los conectores de correo por API (Zoho, SendGrid, Brevo…) sustituyen
     * `wp_mail` por completo y muchos leen solo las cabeceras del mensaje,
     * ignorando el filtro — sin ella el correo llega con el HTML crudo a la vista.
     */
    private static function send_html( $to, $subject, $body ) {
        if ( ! $to ) return false;
        $ct = static function () { return 'text/html; charset=UTF-8'; };
        add_filter( 'wp_mail_content_type', $ct );
        $ok = wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
        remove_filter( 'wp_mail_content_type', $ct );
        return (bool) $ok;
    }

    /** Destinatarios del resumen general: admin del sitio + correos extra. */
    private static function digest_recipients() {
        $s  = self::settings();
        $to = [];
        if ( ! empty( $s['alert_to_admin'] ) ) $to[] = get_option( 'admin_email' );
        foreach ( explode( ',', (string) $s['alert_emails'] ) as $mail ) {
            $mail = trim( $mail );
            if ( is_email( $mail ) ) $to[] = $mail;
        }
        return array_values( array_unique( array_filter( $to ) ) );
    }

    private static function send_admin_digest( $machines, $tasks, $days ) {
        $to = self::digest_recipients();
        if ( ! $to ) return false;

        $body  = self::mail_header( 'Mantenimientos y tareas próximos', 'Ventana de ' . (int) $days . ' días (incluye vencidos).' );
        $body .= self::machines_table_html( $machines );
        $body .= self::tasks_table_html( $tasks, true );
        $body .= self::mail_footer();

        $subject = sprintf( '[%s] %d mantenimiento(s) y %d tarea(s) por atender',
            get_bloginfo( 'name' ), count( $machines ), count( $tasks ) );

        return self::send_html( $to, $subject, $body );
    }

    /**
     * Un correo por técnico, solo con SUS máquinas asignadas y SUS tareas.
     * Un técnico sin nada pendiente no recibe correo.
     */
    private static function send_tech_digests( $machines, $tasks, $days ) {
        $sent = 0;
        foreach ( CMH_Tech::technicians() as $u ) {
            if ( ! is_email( $u->user_email ) ) continue;

            $machine_ids = CMH_Tech::assigned_machine_ids( (int) $u->ID );
            $my_machines = array_values( array_filter( $machines, function ( $m ) use ( $machine_ids ) {
                return in_array( (int) $m->id, $machine_ids, true );
            } ) );
            $my_tasks = array_values( array_filter( $tasks, function ( $ta ) use ( $u ) {
                return (int) $ta->assigned_to === (int) $u->ID;
            } ) );
            if ( ! $my_machines && ! $my_tasks ) continue;

            $body  = self::mail_header(
                'Hola ' . $u->display_name . ',',
                'Esto es lo que tienes por atender en los próximos ' . (int) $days . ' días (incluye vencidos).'
            );
            $body .= self::machines_table_html( $my_machines );
            $body .= self::tasks_table_html( $my_tasks, false );
            $body .= self::mail_footer();

            $subject = sprintf( '[%s] Tienes %d mantenimiento(s) y %d tarea(s) pendientes',
                get_bloginfo( 'name' ), count( $my_machines ), count( $my_tasks ) );

            if ( self::send_html( $u->user_email, $subject, $body ) ) $sent++;
        }
        return $sent;
    }

    // ── Plantilla de correo ───────────────────────────────────────────────────

    private static function mail_header( $title, $intro ) {
        return '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#1d2327;max-width:760px">'
            . '<h2 style="margin:0 0 6px;font-size:18px">' . esc_html( $title ) . '</h2>'
            . '<p style="margin:0 0 18px;color:#646970">' . esc_html( $intro ) . '</p>';
    }

    private static function mail_footer() {
        return '<p style="margin:22px 0 0;font-size:12px;color:#646970">'
            . 'Enviado automáticamente por CM Machine History desde '
            . '<a href="' . esc_url( home_url() ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>.'
            . '</p></div>';
    }

    private static function th( $label ) {
        return '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #dcdcde;font-size:12px;text-transform:uppercase;color:#646970">' . esc_html( $label ) . '</th>';
    }

    private static function td( $html, $extra = '' ) {
        return '<td style="padding:8px 10px;border-bottom:1px solid #f0f0f1;' . $extra . '">' . $html . '</td>';
    }

    /** Texto de vencimiento coloreado, compartido por ambas tablas. */
    private static function due_html( $date ) {
        $days = CMH_Metrics::maintenance_days( $date );
        if ( $days === null ) return '<span style="color:#646970">—</span>';
        if ( $days < 0 )  return '<strong style="color:#d63638">Vencido hace ' . abs( $days ) . ' d</strong>';
        if ( $days === 0 ) return '<strong style="color:#d63638">Vence hoy</strong>';
        if ( $days <= 7 ) return '<strong style="color:#d63638">En ' . $days . ' días</strong>';
        return '<span style="color:#7a4f00">En ' . $days . ' días</span>';
    }

    private static function machines_table_html( $machines ) {
        if ( ! $machines ) return '';
        $out = '<h3 style="font-size:15px;margin:18px 0 8px">Mantenimientos programados (' . count( $machines ) . ')</h3>'
             . '<table style="border-collapse:collapse;width:100%"><thead><tr>'
             . self::th( 'Máquina' ) . self::th( 'Equipo' ) . self::th( 'Cliente' )
             . self::th( 'Fecha' ) . self::th( 'Estado' ) . '</tr></thead><tbody>';
        foreach ( $machines as $m ) {
            $url = CMH_Admin::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $m->id ] );
            $out .= '<tr>'
                . self::td( '<a href="' . esc_url( $url ) . '" style="color:#2271b1;text-decoration:none"><strong>' . esc_html( $m->machine_code ) . '</strong></a>' )
                . self::td( esc_html( trim( $m->brand . ' ' . $m->model ) ) )
                . self::td( esc_html( $m->company_name . ' / ' . $m->city_name ), 'font-size:12px;color:#646970' )
                . self::td( esc_html( $m->next_maintenance_date ) )
                . self::td( self::due_html( $m->next_maintenance_date ) )
                . '</tr>';
        }
        return $out . '</tbody></table>';
    }

    private static function tasks_table_html( $tasks, $show_tech ) {
        if ( ! $tasks ) return '';
        $out = '<h3 style="font-size:15px;margin:22px 0 8px">Tareas pendientes (' . count( $tasks ) . ')</h3>'
             . '<table style="border-collapse:collapse;width:100%"><thead><tr>'
             . self::th( 'Tarea' ) . self::th( 'Máquina' )
             . ( $show_tech ? self::th( 'Técnico' ) : '' )
             . self::th( 'Vence' ) . self::th( 'Estado' ) . '</tr></thead><tbody>';
        foreach ( $tasks as $ta ) {
            $url  = CMH_Admin::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $ta->machine_id ] );
            $tech = $ta->assigned_to ? get_the_author_meta( 'display_name', $ta->assigned_to ) : '—';
            $out .= '<tr>'
                . self::td( '<strong>' . esc_html( $ta->title ) . '</strong>' )
                . self::td( '<a href="' . esc_url( $url ) . '" style="color:#2271b1;text-decoration:none">' . esc_html( $ta->machine_code ) . '</a>' )
                . ( $show_tech ? self::td( esc_html( $tech ?: '—' ), 'font-size:12px;color:#646970' ) : '' )
                . self::td( esc_html( $ta->due_date ) )
                . self::td( self::due_html( $ta->due_date ) )
                . '</tr>';
        }
        return $out . '</tbody></table>';
    }

    // =========================================================================
    // Página de ajustes
    // =========================================================================

    public static function page_settings() {
        $s    = self::settings();
        $days = max( 0, (int) $s['alert_days_before'] );
        $next = self::next_run_label();

        CMH_Admin::page_header( 'Ajustes' );

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Configuración</div>'
            . '<h2>Alertas y mantenimiento recurrente</h2>'
            . '<p>Revisión diaria de mantenimientos y tareas, con aviso por correo.</p>'
            . '</div></div>';

        // Estado del job.
        $cron_ok = (bool) wp_next_scheduled( self::CRON_HOOK );
        echo '<div class="cmh-panel"><h2>Estado del proceso diario</h2>'
            . '<div class="cmh-info-grid">'
            . '<div><span>Job programado</span><strong>' . ( $cron_ok
                ? '<span style="color:#1a6630">Activo</span>'
                : '<span style="color:#d63638">No programado</span>' ) . '</strong></div>'
            . '<div><span>Próxima ejecución</span><strong>' . esc_html( $next ?: '—' ) . '</strong></div>'
            . '<div><span>Última ejecución</span><strong>' . esc_html( $s['last_run'] ?: 'Nunca' ) . '</strong></div>'
            . '</div>'
            . ( $s['last_summary'] ? '<div class="cmh-note" style="margin-top:12px"><strong>Último resultado:</strong> ' . esc_html( $s['last_summary'] ) . '</div>' : '' )
            . '<p style="font-size:12px;color:#646970;margin:12px 0 0">WP-Cron depende de las visitas al sitio: en sitios con poco tráfico el job puede dispararse más tarde de la hora prevista ('
            . sprintf( '%02d:00', self::CRON_HOUR ) . '). El proceso solo se ejecuta una vez al día.</p>';

        CMH_Admin::form_start( 'cm_run_alerts' );
        echo '<input type="hidden" name="redirect_to" value="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-settings' ) ) . '">'
            . '<p style="margin:14px 0 0"><button class="button button-primary">Ejecutar ahora</button> '
            . '<span style="font-size:12px;color:#646970;margin-left:8px">Corre el ciclo completo y envía los correos de inmediato.</span></p>'
            . '</form></div>';

        // Formulario de ajustes.
        echo '<div class="cmh-panel"><h2>Alertas por correo</h2>';
        CMH_Admin::form_start( 'cm_save_settings' );
        echo '<input type="hidden" name="redirect_to" value="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-settings' ) ) . '">'

            . '<label><input type="checkbox" name="alerts_enabled" value="1" ' . checked( $s['alerts_enabled'], 1, false ) . '> '
            . 'Enviar alertas por correo</label>'

            . '<div class="cmh-form-grid" style="margin-top:12px">'
            . '<label>Días de anticipación'
            . '<input type="number" name="alert_days_before" value="' . esc_attr( $days ) . '" min="0" max="365" step="1" required>'
            . '</label>'
            . '<label>Correos adicionales <span class="cmh-optional">(separados por coma)</span>'
            . '<input type="text" name="alert_emails" value="' . esc_attr( $s['alert_emails'] ) . '" placeholder="jefe@empresa.com, coordinador@empresa.com">'
            . '</label>'
            . '</div>'
            . '<p style="font-size:12px;color:#646970;margin:4px 0 14px">Se avisa de mantenimientos y tareas que vencen dentro de esa ventana, y de todo lo ya vencido. Las máquinas inactivas o fuera de servicio no generan alertas.</p>'

            . '<label><input type="checkbox" name="alert_to_admin" value="1" ' . checked( $s['alert_to_admin'], 1, false ) . '> '
            . 'Enviar resumen al correo del administrador <span class="cmh-optional">(' . esc_html( get_option( 'admin_email' ) ) . ')</span></label>'
            . '<label><input type="checkbox" name="alert_to_techs" value="1" ' . checked( $s['alert_to_techs'], 1, false ) . '> '
            . 'Enviar a cada técnico un correo con sus máquinas y tareas</label>';

        echo '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Tareas automáticas</p>'
            . '<label><input type="checkbox" name="auto_task" value="1" ' . checked( $s['auto_task'], 1, false ) . '> '
            . 'Crear una tarea automáticamente cuando el mantenimiento entre en la ventana de alerta</label>'
            . '<label>Título de la tarea generada'
            . '<input type="text" name="auto_task_title" value="' . esc_attr( $s['auto_task_title'] ) . '" placeholder="Mantenimiento preventivo programado"></label>'
            . '<p style="font-size:12px;color:#646970;margin:6px 0 0">Se le agrega el código de la máquina y se asigna al primer técnico asignado, si lo hay. No se duplica: una tarea por máquina y fecha programada.</p>'
            . '</div>'

            . '<button class="button button-primary">Guardar ajustes</button></form></div>';

        // Vista previa de lo que se enviaría hoy.
        $machines = self::due_machines( $days );
        $tasks    = self::due_tasks( $days );
        echo '<div class="cmh-panel"><h2>Vista previa — qué se alertaría hoy</h2>';
        if ( ! $machines && ! $tasks ) {
            echo '<p style="color:#646970;font-size:13px;margin:0">Nada pendiente en la ventana de ' . intval( $days ) . ' días. No se enviaría ningún correo.</p>';
        } else {
            echo '<p style="color:#646970;font-size:13px;margin:0 0 10px">'
                . intval( count( $machines ) ) . ' mantenimiento(s) y ' . intval( count( $tasks ) ) . ' tarea(s) entrarían en el correo de hoy.</p>'
                . self::machines_table_html( $machines )
                . self::tasks_table_html( $tasks, true );
        }
        echo '</div>';

        CMH_Admin::page_footer();
    }

    // =========================================================================
    // Handlers
    // =========================================================================

    public static function save_settings() {
        CMH_Admin::check();

        self::update_settings( [
            'alerts_enabled'    => isset( $_POST['alerts_enabled'] ) ? 1 : 0,
            'alert_days_before' => min( 365, max( 0, (int) ( $_POST['alert_days_before'] ?? 7 ) ) ),
            'alert_to_admin'    => isset( $_POST['alert_to_admin'] ) ? 1 : 0,
            'alert_emails'      => sanitize_text_field( $_POST['alert_emails'] ?? '' ),
            'alert_to_techs'    => isset( $_POST['alert_to_techs'] ) ? 1 : 0,
            'auto_task'         => isset( $_POST['auto_task'] ) ? 1 : 0,
            'auto_task_title'   => sanitize_text_field( $_POST['auto_task_title'] ?? '' ) ?: 'Mantenimiento preventivo programado',
        ] );

        // Reprograma el job si se había perdido (p. ej. tras un cambio de cron).
        self::schedule_cron();

        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-settings' ), 'Ajustes guardados.' );
    }

    public static function run_alerts_now() {
        CMH_Admin::check();
        $r = self::run_daily( true );

        $msg = sprintf(
            'Proceso ejecutado: %d mantenimiento(s) y %d tarea(s) en ventana · %d tarea(s) creada(s) · %d correo(s) enviado(s).',
            $r['machines'], $r['tasks'], $r['created'], $r['emails']
        );
        $warn = ( $r['emails'] === 0 && ( $r['machines'] || $r['tasks'] ) && ! self::setting( 'alerts_enabled' ) )
            ? 'Las alertas por correo están desactivadas: no se envió ningún email.' : '';

        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-settings' ), $msg, $warn );
    }
}
