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

    /** v2.10 — Option con lo ya anunciado a los clientes, por máquina. */
    const NOTICES_OPTION = 'cmh_client_notices';

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
            // v2.10 — Avisos a los clientes. Apagado de fábrica: al actualizar no
            // sale ningún correo a un cliente hasta que el admin lo encienda.
            'client_alerts'           => 0, // avisar a los clientes de sus mantenimientos
            'client_alert_days'       => 7, // anticipación del primer aviso
            'client_alert_reminder'   => 1, // recordatorio el día anterior
            'client_alert_reschedule' => 1, // avisar si cambia una fecha ya anunciada
            'auto_task'         => 1,   // auto-generar tarea al vencer el mantenimiento
            'auto_task_title'   => 'Mantenimiento preventivo programado',
            'auto_task_hours'   => 0,   // v2.10 — tiempo estimado de la tarea automática (0 = sin estimación)
            'auto_task_when'    => 'immediate', // 'immediate' o 'window'
            'auto_complete_task' => 1,  // cerrar la tarea al llegar su intervención
            'time_max_hours'    => 12,  // tope por tramo de trabajo del técnico
            // v2.9.1 — Qué ve el técnico en su panel. El dinero, apagado de fábrica.
            'tech_show_costs'      => 0, // costo, cobrado, saldo y estado de pago
            'tech_show_indicators' => 1, // disponibilidad, averías del mes, horas programadas
            'tech_show_pdfs'       => 1, // PDF de los formatos
            'tech_show_detail'     => 0, // repuestos, servicios y observaciones anteriores
            'delete_data_on_uninstall' => 0, // al eliminar el plugin, NO borrar datos
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
        if ( sanitize_key( $maintenance_type ) !== 'preventivo' ) return '';
        if ( $manual_date ) {
            // v2.10 — Preventivo hecho con la próxima fecha escrita a mano: el
            // mantenimiento anunciado ya pasó, la fecha nueva no es un «cambio».
            self::forget_client_notice( $machine_id );
            return '';
        }

        global $wpdb; $t = CMH_Core::tables();
        $interval = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT maintenance_interval_days FROM {$t['machines']} WHERE id=%d", $machine_id
        ) );
        if ( $interval < 1 ) {
            // v2.9 — Sin recurrencia, el preventivo que se acaba de hacer ES el
            // programado si su fecha ya llegó o está dentro de la ventana de
            // aviso: se limpia la fecha para que la máquina no siga «vencida».
            $due = $wpdb->get_var( $wpdb->prepare(
                "SELECT next_maintenance_date FROM {$t['machines']} WHERE id=%d", $machine_id
            ) );
            $base  = $intervention_date ?: current_time( 'Y-m-d' );
            $limit = date( 'Y-m-d', strtotime( $base . ' +' . max( 0, (int) self::setting( 'alert_days_before' ) ) . ' days' ) );
            if ( $due && $due <= $limit ) self::clear_next_maintenance( $machine_id );
            return '';
        }

        $base = $intervention_date ?: current_time( 'Y-m-d' );
        $next = date( 'Y-m-d', strtotime( $base . ' +' . $interval . ' days' ) );

        self::forget_client_notice( $machine_id );
        $wpdb->update( $t['machines'],
            [ 'next_maintenance_date' => $next, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $machine_id ]
        );
        CMH_Schedule::sync_machine_task( (int) $machine_id );
        return $next;
    }

    /** v2.9 — Quita la fecha de próximo mantenimiento (ya se hizo, y no hay recurrencia). */
    private static function clear_next_maintenance( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();
        self::forget_client_notice( $machine_id );
        $wpdb->update( $t['machines'],
            [ 'next_maintenance_date' => null, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $machine_id ]
        );
        self::sync_machine_task( (int) $machine_id );
    }

    /**
     * v2.9 — Se cerró (o se dio por hecha sin formato) una tarea. Si era la del
     * mantenimiento programado, ese mantenimiento ya se hizo.
     *
     * Hasta ahora la tarea quedaba «Completada», pero la máquina conservaba la
     * fecha vieja y seguía apareciendo como «Mant. vencido» en la ficha, el
     * dashboard, el portal y los correos. Solo un preventivo registrado movía
     * esa fecha, y solo si la máquina tenía recurrencia.
     *
     * Ahora, si la máquina sigue apuntando a la fecha de ESA tarea:
     *   - con recurrencia, la próxima fecha se calcula desde hoy;
     *   - sin recurrencia, la fecha se limpia.
     * Si la fecha ya había cambiado (por ejemplo, porque llegó el preventivo),
     * no se toca nada.
     *
     * @param object $task      Fila de la tarea ANTES del cambio de estado.
     * @param string $done_date Día en que se terminó (Y-m-d). Vacío = hoy.
     */
    public static function on_task_done( $task, $done_date = '' ) {
        if ( ! $task || ( $task->source ?? '' ) !== 'auto' || empty( $task->due_date ) ) return;

        global $wpdb; $t = CMH_Core::tables();
        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, next_maintenance_date, maintenance_interval_days FROM {$t['machines']} WHERE id=%d",
            (int) $task->machine_id
        ) );
        if ( ! $m || $m->next_maintenance_date !== $task->due_date ) return;

        $interval = (int) $m->maintenance_interval_days;
        if ( $interval < 1 ) {
            self::clear_next_maintenance( (int) $m->id );
            return;
        }

        $next = date( 'Y-m-d', strtotime( ( $done_date ?: current_time( 'Y-m-d' ) ) . ' +' . $interval . ' days' ) );
        self::forget_client_notice( (int) $m->id );
        $wpdb->update( $t['machines'],
            [ 'next_maintenance_date' => $next, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $m->id ]
        );
        self::sync_machine_task( (int) $m->id );
    }

    /**
     * v2.9 — Reparación única al actualizar: máquinas que siguen «vencidas»
     * aunque su tarea de mantenimiento ya se cerró antes de esta versión. Se
     * les aplica lo mismo que on_task_done, contando desde el día del cierre.
     *
     * @return int Máquinas corregidas.
     */
    public static function repair_done_maintenance() {
        global $wpdb; $t = CMH_Core::tables();
        $tasks = $wpdb->get_results(
            "SELECT ta.* FROM {$t['tasks']} ta
             JOIN {$t['machines']} m ON m.id = ta.machine_id
             WHERE ta.source = 'auto'
               AND ta.status IN ('completada','sin_formato')
               AND ta.due_date = m.next_maintenance_date"
        );
        foreach ( $tasks as $ta ) {
            self::on_task_done( $ta, $ta->updated_at ? substr( $ta->updated_at, 0, 10 ) : '' );
        }
        return count( $tasks );
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
             WHERE ta.status NOT IN ('completada','sin_formato')
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
            return [ 'machines' => 0, 'tasks' => 0, 'created' => 0, 'emails' => 0, 'clients' => 0, 'skipped' => true ];
        }

        // v2.1 — Cierra los relojes que quedaron corriendo más allá del tope
        // (el técnico abrió la tarea y nunca la marcó como completada).
        if ( class_exists( 'CMH_Time' ) ) CMH_Time::close_stale();

        // v2.2 — En modo «inmediato», pone al día las máquinas cuya fecha se
        // programó antes de activar esta versión, y mueve las tareas cuya fecha
        // haya cambiado por fuera del panel.
        if ( self::setting( 'auto_task' ) && self::setting( 'auto_task_when' ) === 'immediate' ) {
            self::sync_all_tasks();
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

        // v2.10 — Los clientes van aparte: tienen su propia ventana y solo se
        // les avisa una vez por etapa, así que no dependen de lo de arriba.
        $client_emails = ( ! empty( $s['alerts_enabled'] ) && ! empty( $s['client_alerts'] ) )
            ? self::send_client_alerts() : 0;

        $summary = sprintf(
            '%d mantenimiento(s) y %d tarea(s) en ventana de %d días · %d tarea(s) autogenerada(s) · %d correo(s) enviado(s) · %d aviso(s) a clientes.',
            count( $machines ), count( $tasks ), $days, $created, $emails, $client_emails
        );
        self::update_settings( [ 'last_run' => current_time( 'mysql' ), 'last_summary' => $summary ] );
        CMH_Core::log( 'info', null, '', null, 'Alertas de mantenimiento: ' . $summary );

        return [
            'machines' => count( $machines ), 'tasks' => count( $tasks ),
            'created'  => $created, 'emails' => $emails, 'clients' => $client_emails, 'skipped' => false,
        ];
    }

    /**
     * Crea una tarea por cada máquina con mantenimiento próximo/vencido.
     *
     * Idempotente: se identifica por (machine_id, source='auto', due_date), así que
     * reejecutar el job el mismo ciclo no duplica tareas. Si hay técnicos asignados,
     * la tarea queda para el primero de ellos.
     */
    // =========================================================================
    // Tarea del mantenimiento programado (v2.2)
    // =========================================================================

    /**
     * Mantiene sincronizada la tarea automática de una máquina con su fecha de
     * próximo mantenimiento. Se llama cada vez que esa fecha puede haber cambiado.
     *
     * Hasta la v2.1 la tarea solo nacía cuando la fecha entraba en la ventana de
     * alerta, así que programar un mantenimiento a tres meses no mostraba nada.
     * Ahora, en el modo «inmediato» (el de fábrica), la tarea existe desde que se
     * guarda la fecha y **se mueve con ella** en vez de dejar huérfana la anterior.
     *
     * Devuelve 'created', 'moved', 'removed' o '' si no hizo nada.
     */
    public static function sync_machine_task( $machine_id ) {
        global $wpdb; $t = CMH_Core::tables();
        $machine_id = (int) $machine_id;
        if ( ! $machine_id || ! self::setting( 'auto_task' ) ) return '';

        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, machine_code, status, next_maintenance_date, maintenance_interval_days
             FROM {$t['machines']} WHERE id=%d", $machine_id
        ) );
        if ( ! $m ) return '';

        // La tarea automática viva de esta máquina, si la hay.
        $task = $wpdb->get_row( $wpdb->prepare(
            // v2.9 — Una tarea «sin formato» ya está hecha: no es la viva, no se mueve.
            "SELECT * FROM {$t['tasks']} WHERE machine_id=%d AND source='auto' AND status NOT IN ('completada','sin_formato')
             ORDER BY id DESC LIMIT 1", $machine_id
        ) );

        $inactive = in_array( $m->status, [ 'inactiva', 'fuera_servicio' ], true );
        $date     = $m->next_maintenance_date;

        // Sin fecha, o máquina fuera de juego: la tarea pendiente ya no aplica.
        if ( ! $date || $inactive ) {
            if ( $task && $task->status === 'pendiente' && ! self::task_has_time( (int) $task->id ) ) {
                $wpdb->delete( $t['tasks'], [ 'id' => (int) $task->id ] );
                return 'removed';
            }
            return '';
        }

        // Ya existe: basta con moverla si la fecha cambió. No se toca el técnico
        // ni el estado, que pueden haberse ajustado a mano.
        if ( $task ) {
            if ( $task->due_date === $date ) return '';
            $wpdb->update( $t['tasks'],
                [ 'due_date' => $date, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $task->id ]
            );
            return 'moved';
        }

        // No existe. En modo «inmediato» se crea ya; en modo «ventana» se deja
        // para el proceso diario, que es quien mira la anticipación.
        if ( self::setting( 'auto_task_when' ) !== 'immediate' ) return '';

        return self::insert_auto_task( $m ) ? 'created' : '';
    }

    /** ¿La tarea tiene tramos de horas registrados? Si los tiene, no se borra. */
    private static function task_has_time( $task_id ) {
        global $wpdb; $t = CMH_Core::tables();
        if ( empty( $t['task_time'] ) ) return false;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['task_time']} WHERE task_id=%d LIMIT 1", (int) $task_id
        ) );
    }

    /**
     * Inserta la tarea automática de una máquina. Idempotente por (máquina,
     * origen automático, fecha): no duplica si ya existe una para esa fecha.
     */
    private static function insert_auto_task( $m ) {
        global $wpdb; $t = CMH_Core::tables();

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['tasks']} WHERE machine_id=%d AND source='auto' AND due_date=%s LIMIT 1",
            $m->id, $m->next_maintenance_date
        ) );
        if ( $exists ) return false;

        $title_base = self::setting( 'auto_task_title' ) ?: 'Mantenimiento preventivo programado';

        $wpdb->insert( $t['tasks'], [
            'machine_id'  => (int) $m->id,
            // v2.2 — Al técnico principal de la máquina, no al primero de la lista.
            'assigned_to' => CMH_Tech::primary_user_id( (int) $m->id ) ?: null,
            'title'       => $title_base . ' — ' . $m->machine_code,
            'notes'       => 'Tarea generada automáticamente por la fecha de próximo mantenimiento ('
                           . $m->next_maintenance_date . ')'
                           . ( $m->maintenance_interval_days ? '. Recurrencia: ' . self::interval_label( $m->maintenance_interval_days ) . '.' : '.' ),
            'due_date'    => $m->next_maintenance_date,
            'status'      => 'pendiente',
            'source'      => 'auto',
            // v2.10 — El estimado de Ajustes; se puede cambiar luego en la tarea.
            'estimated_hours' => (float) self::setting( 'auto_task_hours' ) > 0 ? round( (float) self::setting( 'auto_task_hours' ), 2 ) : null,
            'created_by'  => null,
        ] );
        return ! $wpdb->last_error;
    }

    /**
     * Pone al día todas las máquinas de una vez. Lo usa el proceso diario en modo
     * «inmediato», para que las fechas programadas antes de activar esta versión
     * también tengan su tarea.
     */
    public static function sync_all_tasks() {
        global $wpdb; $t = CMH_Core::tables();
        $ids = $wpdb->get_col(
            "SELECT id FROM {$t['machines']}
             WHERE next_maintenance_date IS NOT NULL AND status NOT IN ('inactiva','fuera_servicio')"
        );
        $n = 0;
        foreach ( $ids as $id ) {
            if ( self::sync_machine_task( (int) $id ) ) $n++;
        }
        return $n;
    }

    private static function create_auto_tasks( $machines ) {
        $created = 0;
        foreach ( $machines as $m ) {
            // v2.2 — La inserción vive en insert_auto_task, compartida con la
            // sincronización inmediata, para que ambas rutas creen la tarea igual
            // (mismo título, mismas notas y el técnico principal de la máquina).
            if ( self::insert_auto_task( $m ) ) $created++;
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
     * Un correo por técnico, SOLO con lo que es suyo. Un técnico sin nada
     * pendiente no recibe correo.
     *
     * v2.9 — Hasta ahora el correo traía también el mantenimiento de TODAS las
     * máquinas a las que el técnico tenía acceso: una máquina con tres técnicos
     * asignados le llegaba a los tres, aunque la tarea fuera de uno solo. Ahora:
     *   - Tareas: solo las asignadas a él.
     *   - Mantenimientos sin tarea: solo los de las máquinas donde él es el
     *     técnico principal, que es a quien le tocaría la tarea. Si la máquina
     *     ya tiene una tarea en la ventana, manda la tarea (y su asignado).
     */
    private static function send_tech_digests( $machines, $tasks, $days ) {
        $sent = 0;

        // Máquinas que ya tienen tarea en la ventana: se avisan por la tarea.
        $with_task = array_map( 'intval', wp_list_pluck( $tasks, 'machine_id' ) );

        foreach ( CMH_Tech::technicians() as $u ) {
            if ( ! is_email( $u->user_email ) ) continue;
            $uid = (int) $u->ID;

            $my_tasks = array_values( array_filter( $tasks, function ( $ta ) use ( $uid ) {
                return (int) $ta->assigned_to === $uid;
            } ) );
            $my_machines = array_values( array_filter( $machines, function ( $m ) use ( $uid, $with_task ) {
                return ! in_array( (int) $m->id, $with_task, true )
                    && CMH_Tech::primary_user_id( (int) $m->id ) === $uid;
            } ) );
            if ( ! $my_machines && ! $my_tasks ) continue;

            $body  = self::mail_header(
                'Hola ' . $u->display_name . ',',
                'Esto es lo que tienes asignado para los próximos ' . (int) $days . ' días (incluye vencidos).'
            );
            // Los enlaces van a «Mis Máquinas»: el técnico no entra al panel de administración.
            $body .= self::machines_table_html( $my_machines, 'cmh-tech' );
            $body .= self::tasks_table_html( $my_tasks, false, 'cmh-tech' );
            $body .= self::mail_footer();

            $subject = sprintf( '[%s] Tienes %d mantenimiento(s) y %d tarea(s) pendientes',
                get_bloginfo( 'name' ), count( $my_machines ), count( $my_tasks ) );

            if ( self::send_html( $u->user_email, $subject, $body ) ) $sent++;
        }
        return $sent;
    }

    // =========================================================================
    // v2.10 — Avisos a los clientes
    // =========================================================================

    /*
     * Hasta la v2.9 el cliente no recibía nada del sistema: solo veía su portal.
     * Ahora recibe, por cada mantenimiento programado de sus equipos:
     *   - un AVISO cuando entra en su ventana (client_alert_days),
     *   - un RECORDATORIO el día anterior (o el mismo día, si el cron llega tarde),
     *   - un CAMBIO DE FECHA si se mueve una fecha que ya se le había anunciado.
     * Nunca se le avisa de un vencido: eso es un pendiente nuestro, no suyo.
     *
     * Para no repetir, la option NOTICES_OPTION guarda por máquina la fecha
     * anunciada y las etapas ya enviadas: [ id => [ 'date' => Y-m-d, 'sent' => [] ] ].
     * Cuando el mantenimiento SE HACE (preventivo registrado, tarea cerrada) la
     * entrada se borra con forget_client_notice(): la fecha que calcule la
     * recurrencia es un mantenimiento nuevo, no un «cambio de fecha».
     */

    private static function client_notices() {
        $n = get_option( self::NOTICES_OPTION, [] );
        return is_array( $n ) ? $n : [];
    }

    /** El mantenimiento anunciado ya se hizo: lo que venga después se anuncia de cero. */
    public static function forget_client_notice( $machine_id ) {
        $n = self::client_notices();
        if ( ! isset( $n[ (int) $machine_id ] ) ) return;
        unset( $n[ (int) $machine_id ] );
        update_option( self::NOTICES_OPTION, $n, false );
    }

    /**
     * Qué aviso le toca hoy a cada máquina. No guarda nada: lo usan el envío y la
     * vista previa. $state sale con cómo debe quedar la option si se envía.
     *
     * @return array [ machine_id => [ 'm' => fila, 'kind' => notice|reminder|moved, 'from' => Y-m-d ] ]
     */
    private static function client_plan( &$state ) {
        global $wpdb; $t = CMH_Core::tables();
        $s        = self::settings();
        $days     = max( 1, (int) $s['client_alert_days'] );
        $reminder = ! empty( $s['client_alert_reminder'] );
        $moved_on = ! empty( $s['client_alert_reschedule'] );
        $today    = current_time( 'Y-m-d' );
        $limit    = date( 'Y-m-d', strtotime( '+' . $days . ' days', current_time( 'timestamp' ) ) );

        $state = self::client_notices();
        // Las ya anunciadas se traen aunque su fecha nueva quede fuera de la
        // ventana: hay que ver si cambió. Los IDs vienen de la option, enteros.
        $known = array_map( 'intval', array_keys( $state ) );
        $or    = $known ? ' OR m.id IN (' . implode( ',', $known ) . ')' : '';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.machine_code, m.brand, m.model, m.company_id, m.city_id,
                    m.next_maintenance_date, ci.name city_name
             FROM {$t['machines']} m
             JOIN {$t['cities']} ci ON ci.id=m.city_id
             WHERE m.next_maintenance_date IS NOT NULL
               AND m.status NOT IN ('inactiva','fuera_servicio')
               AND ( ( m.next_maintenance_date >= %s AND m.next_maintenance_date <= %s ) $or )
             ORDER BY m.next_maintenance_date ASC",
            $today, $limit
        ), OBJECT_K );

        $plan = [];

        // 1. Lo ya anunciado: ¿sigue igual, cambió o desapareció?
        foreach ( $state as $id => $st ) {
            $m = $rows[ $id ] ?? null;
            if ( ! $m ) { unset( $state[ $id ] ); continue; }   // sin fecha o inactiva: se olvida en silencio
            if ( $m->next_maintenance_date === ( $st['date'] ?? '' ) ) continue;

            $d = CMH_Metrics::maintenance_days( $m->next_maintenance_date );
            if ( $d < 0 ) {
                // Se movió a una fecha ya pasada: no se le avisa al cliente de un vencido.
                $state[ $id ] = [ 'date' => $m->next_maintenance_date, 'sent' => [ 'notice', 'reminder' ] ];
            } elseif ( $moved_on ) {
                $plan[ $id ] = [ 'm' => $m, 'kind' => 'moved', 'from' => $st['date'] ?? '' ];
                // El correo del cambio ya anuncia la fecha nueva: cuenta como aviso,
                // aunque falte mucho (solo quedará el recordatorio del día anterior),
                // y como recordatorio si la fecha nueva es mañana u hoy.
                $sent = [ 'notice' ];
                if ( $d <= 1 ) $sent[] = 'reminder';
                $state[ $id ] = [ 'date' => $m->next_maintenance_date, 'sent' => $sent ];
            } else {
                // Sin avisos de cambio: la fecha nueva se anuncia como cualquier otra.
                $state[ $id ] = [ 'date' => $m->next_maintenance_date, 'sent' => [] ];
            }
        }

        // 2. Lo que está en la ventana y aún no tiene su aviso o su recordatorio.
        foreach ( $rows as $id => $m ) {
            if ( isset( $plan[ $id ] ) ) continue;
            $d = CMH_Metrics::maintenance_days( $m->next_maintenance_date );
            if ( $d < 0 || $d > $days ) continue;

            $st   = $state[ $id ] ?? [ 'date' => $m->next_maintenance_date, 'sent' => [] ];
            $sent = (array) ( $st['sent'] ?? [] );

            if ( $reminder && $d <= 1 && ! in_array( 'reminder', $sent, true ) ) {
                $plan[ $id ] = [ 'm' => $m, 'kind' => 'reminder', 'from' => '' ];
                $sent = [ 'notice', 'reminder' ];
            } elseif ( ! in_array( 'notice', $sent, true ) ) {
                $plan[ $id ] = [ 'm' => $m, 'kind' => 'notice', 'from' => '' ];
                $sent[] = 'notice';
            } else {
                continue;
            }
            $state[ $id ] = [ 'date' => $m->next_maintenance_date, 'sent' => array_values( array_unique( $sent ) ) ];
        }

        return $plan;
    }

    /** Los avisos del plan que caen en el alcance (empresas o sucursales) de un cliente. */
    private static function client_items( $plan, $user_id ) {
        $acl = CMH_Client::acl( $user_id );
        if ( ! $acl['companies'] && ! $acl['cities'] ) return [];
        return array_values( array_filter( $plan, function ( $it ) use ( $acl ) {
            return in_array( (int) $it['m']->company_id, $acl['companies'], true )
                || in_array( (int) $it['m']->city_id,    $acl['cities'],    true );
        } ) );
    }

    /**
     * Un correo por cliente con lo de sus equipos. Las etapas se marcan como
     * enviadas aunque ningún cliente tenga acceso a la máquina: si se le da
     * acceso después, no le llega de golpe un aviso viejo.
     *
     * @return int Correos enviados.
     */
    private static function send_client_alerts() {
        $state = [];
        $plan  = self::client_plan( $state );
        $sent  = 0;

        if ( $plan ) {
            foreach ( CMH_Client::clients() as $u ) {
                if ( ! is_email( $u->user_email ) ) continue;
                $items = self::client_items( $plan, (int) $u->ID );
                if ( ! $items ) continue;
                if ( self::send_html( $u->user_email, self::client_subject( $items ), self::client_body( $u, $items ) ) ) $sent++;
            }
        }

        update_option( self::NOTICES_OPTION, $state, false );
        return $sent;
    }

    private static function client_date( $date ) {
        return $date ? date_i18n( 'd/m/Y', strtotime( $date ) ) : '—';
    }

    private static function client_subject( $items ) {
        $site = get_bloginfo( 'name' );
        if ( count( $items ) > 1 ) {
            return sprintf( '[%s] Próximos mantenimientos de tus equipos (%d)', $site, count( $items ) );
        }
        $it   = $items[0];
        $code = $it['m']->machine_code;
        $date = self::client_date( $it['m']->next_maintenance_date );
        if ( $it['kind'] === 'moved' ) return sprintf( '[%s] Nueva fecha de mantenimiento para %s: %s', $site, $code, $date );
        if ( $it['kind'] === 'reminder' ) {
            $d = CMH_Metrics::maintenance_days( $it['m']->next_maintenance_date );
            return sprintf( '[%s] %s: mantenimiento de %s', $site, $d === 0 ? 'Hoy' : 'Mañana', $code );
        }
        return sprintf( '[%s] Mantenimiento programado para %s el %s', $site, $code, $date );
    }

    /** Qué pasa con ese mantenimiento, en palabras del cliente. */
    private static function client_status_html( $it ) {
        $d = CMH_Metrics::maintenance_days( $it['m']->next_maintenance_date );
        if ( $it['kind'] === 'moved' ) {
            return '<strong style="color:#7a4f00">Cambió de fecha</strong>'
                . '<br><span style="font-size:12px;color:#646970">Antes: ' . esc_html( self::client_date( $it['from'] ) ) . '</span>';
        }
        if ( $d === 0 ) return '<strong style="color:#2271b1">Es hoy</strong>';
        if ( $d === 1 ) return '<strong style="color:#2271b1">Es mañana</strong>';
        return '<span style="color:#1d2327">En ' . (int) $d . ' días</span>';
    }

    private static function client_body( $u, $items ) {
        $body = self::mail_header(
            'Hola ' . $u->display_name . ',',
            'Te contamos los próximos mantenimientos programados para tus equipos.'
        );
        $body .= '<table style="border-collapse:collapse;width:100%"><thead><tr>'
            . self::th( 'Equipo' ) . self::th( 'Marca / modelo' ) . self::th( 'Sede' )
            . self::th( 'Fecha' ) . self::th( 'Aviso' ) . '</tr></thead><tbody>';
        foreach ( $items as $it ) {
            $m   = $it['m'];
            $url = CMH_Admin::admin_url( 'cmh-client', [ 'machine_id' => $m->id ] );
            $body .= '<tr>'
                . self::td( '<a href="' . esc_url( $url ) . '" style="color:#2271b1;text-decoration:none"><strong>' . esc_html( $m->machine_code ) . '</strong></a>' )
                . self::td( esc_html( trim( $m->brand . ' ' . $m->model ) ) )
                . self::td( esc_html( $m->city_name ), 'font-size:12px;color:#646970' )
                . self::td( '<strong>' . esc_html( self::client_date( $m->next_maintenance_date ) ) . '</strong>' )
                . self::td( self::client_status_html( $it ) )
                . '</tr>';
        }
        $body .= '</tbody></table>'
            . '<p style="margin:18px 0 0">Si necesitas mover alguna de estas fechas, comunícate con nosotros. '
            . 'En el portal puedes ver la hoja de vida completa de cada equipo.</p>';
        return $body . self::mail_footer();
    }

    /** Vista previa para Ajustes: [ [ 'user' => WP_User, 'items' => [...] ] ]. No marca nada. */
    private static function client_preview() {
        $state = [];
        $plan  = self::client_plan( $state );
        if ( ! $plan ) return [];
        $out = [];
        foreach ( CMH_Client::clients() as $u ) {
            $items = self::client_items( $plan, (int) $u->ID );
            if ( $items ) $out[] = [ 'user' => $u, 'items' => $items ];
        }
        return $out;
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

    /** $page: página destino de los enlaces (admin por defecto, «cmh-tech» para técnicos). */
    private static function machines_table_html( $machines, $page = '' ) {
        if ( ! $machines ) return '';
        $out = '<h3 style="font-size:15px;margin:18px 0 8px">Mantenimientos programados (' . count( $machines ) . ')</h3>'
             . '<table style="border-collapse:collapse;width:100%"><thead><tr>'
             . self::th( 'Máquina' ) . self::th( 'Equipo' ) . self::th( 'Cliente' )
             . self::th( 'Fecha' ) . self::th( 'Estado' ) . '</tr></thead><tbody>';
        foreach ( $machines as $m ) {
            $url = CMH_Admin::admin_url( $page ?: CMH_SLUG . '-machines', [ 'machine_id' => $m->id ] );
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

    private static function tasks_table_html( $tasks, $show_tech, $page = '' ) {
        if ( ! $tasks ) return '';
        $out = '<h3 style="font-size:15px;margin:22px 0 8px">Tareas pendientes (' . count( $tasks ) . ')</h3>'
             . '<table style="border-collapse:collapse;width:100%"><thead><tr>'
             . self::th( 'Tarea' ) . self::th( 'Máquina' )
             . ( $show_tech ? self::th( 'Técnico' ) : '' )
             . self::th( 'Vence' ) . self::th( 'Estado' ) . '</tr></thead><tbody>';
        foreach ( $tasks as $ta ) {
            $url  = CMH_Admin::admin_url( $page ?: CMH_SLUG . '-machines', [ 'machine_id' => $ta->machine_id ] );
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

        echo '<div class="cmh-head"><div class="cmh-head-info">'
            . '<div class="cmh-head-title"><h2>Ajustes</h2></div>'
            . '<p class="cmh-head-meta"><span>Alertas, tareas automáticas, horas y las listas del plugin</span>'
            . '<span>Proceso diario: <strong>' . esc_html( $next ) . '</strong></span></p>'
            . '</div></div>';

        // Estado del job.
        $cron_ok = (bool) wp_next_scheduled( self::CRON_HOOK );
        // v2.4 — Las mismas secciones, en pestañas: la página había crecido a
        // seis bloques largos y encontrar un ajuste obligaba a recorrerla entera.
        echo '<div class="cmh-tabs-wrapper"><div class="cmh-tabs">'
            . '<a href="#tab-alertas" class="cmh-tab" data-tab="alertas">Alertas y tareas</a>'
            . '<a href="#tab-listas"  class="cmh-tab" data-tab="listas">Listas configurables</a>'
            . '<a href="#tab-estado"  class="cmh-tab" data-tab="estado">Estado y vista previa</a>'
            . '</div>';

        echo '<div id="tab-alertas" class="cmh-tab-panel">';
        echo '<div class="cmh-panel"><h2>Alertas por correo</h2>';
        CMH_Admin::form_start( 'cm_save_settings' );
        echo '<input type="hidden" name="redirect_to" value="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-settings' ) ) . '">'

            . '<label><input type="checkbox" name="alerts_enabled" value="1" ' . checked( $s['alerts_enabled'], 1, false ) . '> '
            . 'Enviar alertas por correo</label>'

            . '<div class="cmh-form-grid" style="margin-top:12px">'
            . '<label>Días de anticipación'
            . '<input type="number" name="alert_days_before" value="' . esc_attr( $days ) . '" min="0" max="365" step="1" required>'
            . '</label>'
            . '<label>Correos adicionales <span class="cmh-optional">(separados por coma · reciben el resumen completo)</span>'
            . '<input type="text" name="alert_emails" value="' . esc_attr( $s['alert_emails'] ) . '" placeholder="jefe@empresa.com, coordinador@empresa.com">'
            . '</label>'
            . '</div>'
            . '<p style="font-size:12px;color:#646970;margin:4px 0 14px">Se avisa de mantenimientos y tareas que vencen dentro de esa ventana, y de todo lo ya vencido. Las máquinas inactivas o fuera de servicio no generan alertas.</p>'

            . '<label><input type="checkbox" name="alert_to_admin" value="1" ' . checked( $s['alert_to_admin'], 1, false ) . '> '
            . 'Enviar resumen al correo del administrador <span class="cmh-optional">(' . esc_html( get_option( 'admin_email' ) ) . ')</span></label>'
            . '<label><input type="checkbox" name="alert_to_techs" value="1" ' . checked( $s['alert_to_techs'], 1, false ) . '> '
            . 'Enviar a cada técnico un correo solo con las tareas asignadas a él</label>'
            . '<p style="font-size:12px;color:#646970;margin:4px 0 0">Los «Correos adicionales» y el del administrador reciben el resumen COMPLETO de todos: no pongas ahí el correo de un técnico.</p>'

            // v2.10 — Avisos a los clientes.
            . '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Avisos a clientes</p>'
            . '<label><input type="checkbox" name="client_alerts" value="1" ' . checked( $s['client_alerts'], 1, false ) . '> '
            . '<strong>Avisar a los clientes de los mantenimientos programados de sus equipos</strong></label>'
            . '<div class="cmh-form-grid" style="margin-top:8px">'
            . '<label>Días de anticipación del aviso'
            . '<input type="number" name="client_alert_days" value="' . esc_attr( max( 1, (int) $s['client_alert_days'] ) ) . '" min="1" max="365" step="1" style="max-width:120px">'
            . '</label>'
            . '</div>'
            . '<label><input type="checkbox" name="client_alert_reminder" value="1" ' . checked( $s['client_alert_reminder'], 1, false ) . '> '
            . 'Enviar además un recordatorio el día anterior</label>'
            . '<label><input type="checkbox" name="client_alert_reschedule" value="1" ' . checked( $s['client_alert_reschedule'], 1, false ) . '> '
            . 'Avisar si cambia la fecha de un mantenimiento que ya se le anunció</label>'
            . '<p class="cmh-hint">Cada cliente recibe solo los equipos de las empresas o sucursales a las que tiene acceso, en un solo correo al día. '
            . 'Por cada mantenimiento le llegan como mucho el aviso y el recordatorio; nunca se le avisa de un mantenimiento vencido. '
            . 'Un cambio de fecha se le comunica en el proceso diario siguiente. Revisa en «Estado y vista previa» qué recibiría cada cliente antes de encenderlo.</p>'
            . '</div>';

        echo '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Tareas automáticas</p>'
            . '<label><input type="checkbox" name="auto_task" value="1" ' . checked( $s['auto_task'], 1, false ) . '> '
            . 'Crear una tarea automáticamente para cada mantenimiento programado</label>'
            . '<label>Título de la tarea generada'
            . '<input type="text" name="auto_task_title" value="' . esc_attr( $s['auto_task_title'] ) . '" placeholder="Mantenimiento preventivo programado"></label>'
            . '<label>Tiempo estimado de la tarea generada <span class="cmh-optional">(horas · vacío = sin estimación)</span>'
            . '<input type="number" name="auto_task_hours" min="0" max="9999" step="0.25" placeholder="Ej. 2" style="max-width:120px" value="'
            . esc_attr( (float) $s['auto_task_hours'] > 0 ? 0 + $s['auto_task_hours'] : '' ) . '"></label>'
            . '<p class="cmh-hint">Lo que el reloj mida por encima de esto se cuenta como horas extra en «Equipo técnico». Aplica a las tareas que se creen desde ahora; cada tarea se puede ajustar al editarla.</p>'
            . '<p style="font-size:12px;color:#646970;margin:10px 0 4px"><strong>¿Cuándo se crea?</strong></p>'
            . '<label style="font-weight:400"><input type="radio" name="auto_task_when" value="immediate" '
            . checked( $s['auto_task_when'], 'immediate', false ) . '> '
            . 'Apenas se programe la fecha, aunque falten meses <span class="cmh-optional">(recomendado: ves el plan completo)</span></label>'
            . '<label style="font-weight:400"><input type="radio" name="auto_task_when" value="window" '
            . checked( $s['auto_task_when'], 'window', false ) . '> '
            . 'Solo cuando entre en la ventana de alerta de arriba</label>'
            . '<p style="font-size:12px;color:#646970;margin:6px 0 0">Si cambias la fecha de un mantenimiento, la tarea se mueve con ella en vez de quedar una vieja suelta. '
            . 'La tarea se asigna al <strong>técnico principal</strong> de la máquina, que marcas en la pestaña Técnicos de su hoja de vida.</p>'
            . '<label><input type="checkbox" name="auto_complete_task" value="1" ' . checked( $s['auto_complete_task'], 1, false ) . '> '
            . 'Dar la tarea por <strong>completada</strong> cuando llegue su intervención</label>'
            . '<p class="cmh-hint">Se cierra la tarea que esté <em>en curso</em> para esa máquina; si hay varias, la del técnico que firma el formulario. Una tarea pendiente que nadie abrió nunca se cierra sola. El botón «Completar» está siempre disponible en las listas.</p>'
            . '</div>'

            . '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Horas de técnicos</p>'
            . '<label>Tope de horas por tramo de trabajo'
            . '<input type="number" name="time_max_hours" min="1" max="24" step="1" value="' . esc_attr( (int) $s['time_max_hours'] ) . '" style="max-width:120px"></label>'
            . '<p style="font-size:12px;color:#646970;margin:6px 0 0">El reloj de una tarea arranca cuando el técnico la pone «En progreso» y para cuando la marca «Completada». '
            . 'Si olvida cerrarla, este tope evita que el reloj corra toda la noche: al cerrar se recorta a este máximo y queda la nota, y el proceso diario cierra solo lo que siga abierto. '
            . 'Puedes corregir cualquier tramo a mano en <a href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-time' ) ) . '">Máquinas → Equipo técnico</a>.</p>'
            . '</div>'

            // v2.9.1 — Qué ve el técnico en «Mis Máquinas».
            . '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title">Qué ve el técnico</p>'
            . '<p style="font-size:12px;color:#646970;margin:0 0 8px">Lo que aparece en la ficha de cada máquina dentro de «Mis Máquinas». Las tareas, el horómetro y los formatos para diligenciar se ven siempre.</p>'
            . '<label><input type="checkbox" name="tech_show_costs" value="1" ' . checked( $s['tech_show_costs'], 1, false ) . '> '
            . '<strong>Costos y pagos</strong> <span class="cmh-optional">— costo total, cobrado, por cobrar y el estado de pago de cada intervención</span></label>'
            . '<label><input type="checkbox" name="tech_show_indicators" value="1" ' . checked( $s['tech_show_indicators'], 1, false ) . '> '
            . '<strong>Indicadores</strong> <span class="cmh-optional">— disponibilidad del mes, averías del mes y horas programadas</span></label>'
            . '<label><input type="checkbox" name="tech_show_pdfs" value="1" ' . checked( $s['tech_show_pdfs'], 1, false ) . '> '
            . '<strong>PDF de los formatos</strong> <span class="cmh-optional">— si lo apagas, tampoco puede abrirlos con un enlace directo</span></label>'
            . '<label><input type="checkbox" name="tech_show_detail" value="1" ' . checked( $s['tech_show_detail'], 1, false ) . '> '
            . '<strong>Detalle de intervenciones anteriores</strong> <span class="cmh-optional">— repuestos, servicios y observaciones</span></label>'
            . '<p style="font-size:12px;color:#646970;margin:6px 0 0">Ojo: si un técnico es también administrador o cliente, en esos otros menús ve lo que corresponde a ese rol.</p>'
            . '</div>'

            . '<div class="cmh-form-section">'
            . '<p class="cmh-form-section-title" style="color:#d63638">Al eliminar el plugin</p>'
            . '<label><input type="checkbox" name="delete_data_on_uninstall" value="1" ' . checked( $s['delete_data_on_uninstall'], 1, false ) . '> '
            . '<strong>Borrar todos los datos si elimino el plugin</strong></label>'
            . '<p style="font-size:12px;color:#646970;margin:6px 0 0">Déjalo <strong>apagado</strong> salvo que sepas muy bien lo que haces. '
            . 'Apagado, eliminar el plugin desde la pantalla de Plugins conserva máquinas, intervenciones, tareas, horas y accesos: si lo vuelves a instalar, todo sigue ahí. '
            . 'Encendido, eliminarlo <strong>borra las 12 tablas del plugin sin posibilidad de deshacer</strong>. Desactivar el plugin nunca borra nada, con el interruptor como esté.</p>'
            . '</div>'

            . '<button class="button button-primary">Guardar ajustes</button></form></div>';

        // v2.3 — Listas configurables: tipos de mantenimiento y estados de pago.
        echo '</div>';

        echo '<div id="tab-listas" class="cmh-tab-panel">';
        CMH_Taxonomy::render_settings_panels();
        echo '</div>';

        echo '<div id="tab-estado" class="cmh-tab-panel">';
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

        // v2.10 — Qué recibiría cada cliente hoy. Se calcula aunque los avisos
        // estén apagados, para poder revisarlo antes de encenderlos.
        $preview = self::client_preview();
        echo '<div class="cmh-panel"><h2>Vista previa — avisos a clientes hoy</h2>';
        if ( empty( $s['client_alerts'] ) ) {
            echo '<div class="cmh-note" style="margin:0 0 12px">Los avisos a clientes están <strong>apagados</strong>: esto es lo que se enviaría si los enciendes.</div>';
        }
        if ( ! $preview ) {
            echo '<p style="color:#646970;font-size:13px;margin:0">Ningún cliente tiene hoy un aviso pendiente en la ventana de '
                . intval( max( 1, (int) $s['client_alert_days'] ) ) . ' días.</p>';
        } else {
            foreach ( $preview as $p ) {
                echo '<h3 style="font-size:14px;margin:14px 0 6px">' . esc_html( $p['user']->display_name )
                    . ' <span style="font-weight:400;color:#646970;font-size:12px">' . esc_html( $p['user']->user_email ) . '</span></h3>'
                    . '<p style="font-size:12px;color:#646970;margin:0 0 6px">Asunto: ' . esc_html( self::client_subject( $p['items'] ) ) . '</p>'
                    . '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Equipo</th><th>Sede</th><th>Fecha</th><th>Aviso</th></tr></thead><tbody>';
                foreach ( $p['items'] as $it ) {
                    echo '<tr><td><strong>' . esc_html( $it['m']->machine_code ) . '</strong></td>'
                        . '<td>' . esc_html( $it['m']->city_name ) . '</td>'
                        . '<td>' . esc_html( self::client_date( $it['m']->next_maintenance_date ) ) . '</td>'
                        . '<td>' . self::client_status_html( $it ) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
        }
        echo '</div>';
        echo '</div></div>';   // cierra el panel y .cmh-tabs-wrapper


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
            'client_alerts'           => isset( $_POST['client_alerts'] ) ? 1 : 0,
            'client_alert_days'       => min( 365, max( 1, (int) ( $_POST['client_alert_days'] ?? 7 ) ) ),
            'client_alert_reminder'   => isset( $_POST['client_alert_reminder'] ) ? 1 : 0,
            'client_alert_reschedule' => isset( $_POST['client_alert_reschedule'] ) ? 1 : 0,
            'auto_task'       => isset( $_POST['auto_task'] ) ? 1 : 0,
            'auto_task_title'   => sanitize_text_field( $_POST['auto_task_title'] ?? '' ) ?: 'Mantenimiento preventivo programado',
            'auto_task_hours'   => (float) CMH_Time::estimate_from_post( 'auto_task_hours' ),
            'auto_task_when'    => ( ( $_POST['auto_task_when'] ?? '' ) === 'window' ) ? 'window' : 'immediate',
            'auto_complete_task' => isset( $_POST['auto_complete_task'] ) ? 1 : 0,
            'time_max_hours'    => min( 24, max( 1, (int) ( $_POST['time_max_hours'] ?? 12 ) ) ),
            'tech_show_costs'      => isset( $_POST['tech_show_costs'] ) ? 1 : 0,
            'tech_show_indicators' => isset( $_POST['tech_show_indicators'] ) ? 1 : 0,
            'tech_show_pdfs'       => isset( $_POST['tech_show_pdfs'] ) ? 1 : 0,
            'tech_show_detail'     => isset( $_POST['tech_show_detail'] ) ? 1 : 0,
            'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ) ? 1 : 0,
        ] );

        // Reprograma el job si se había perdido (p. ej. tras un cambio de cron).
        self::schedule_cron();

        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-settings' ), 'Ajustes guardados.' );
    }

    public static function run_alerts_now() {
        CMH_Admin::check();
        $r = self::run_daily( true );

        $msg = sprintf(
            'Proceso ejecutado: %d mantenimiento(s) y %d tarea(s) en ventana · %d tarea(s) creada(s) · %d correo(s) enviado(s) · %d aviso(s) a clientes.',
            $r['machines'], $r['tasks'], $r['created'], $r['emails'], $r['clients']
        );
        $warn = ( $r['emails'] === 0 && ( $r['machines'] || $r['tasks'] ) && ! self::setting( 'alerts_enabled' ) )
            ? 'Las alertas por correo están desactivadas: no se envió ningún email.' : '';

        CMH_Admin::redirect_to( CMH_Admin::admin_url( CMH_SLUG . '-settings' ), $msg, $warn );
    }
}
