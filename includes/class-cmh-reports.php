<?php
/**
 * CMH_Reports — Reportería cruzada, indicadores y gráficas.
 *
 * v1.0 — una página de reportes para el administrador, con filtro por empresa,
 *        ciudad y rango de meses.
 * v2.0 — el mismo motor sirve CUATRO niveles de alcance (todo · empresa ·
 *        ciudad/sucursal · máquina) y DOS audiencias (administrador y cliente).
 *
 * ALCANCE (scope). Todos los bloques se calculan sobre el mismo WHERE, que se
 * arma una sola vez en scope():
 *   - filtros del usuario: company_id, city_id, machine_id, brand
 *   - restricción de acceso (ACL): al servir el portal del cliente se inyecta
 *     un OR de sus empresas y ciudades asignadas. Como la ACL entra con AND
 *     sobre TODA consulta, ningún filtro manipulado por URL puede sacar datos
 *     fuera de lo asignado: devolvería conjunto vacío, no datos ajenos.
 *
 * AUDIENCIA. set_context() decide etiquetas, enlaces y la página destino de los
 * formularios. El cliente ve los mismos números con textos suyos («Total
 * facturado» en vez de «Costo total», «Pendiente por pagar» en vez de «Por
 * cobrar») y sin enlaces a la administración.
 *
 * FÓRMULAS
 *   Disponibilidad de periodo — extiende la de CMH_Metrics sin cambiarla:
 *     base  = Σ scheduled_hours_monthly (máquinas del filtro) × nº de meses
 *     avail = (base − horas parada por AVERÍAS) / base × 100, acotado a 0–100
 *     Con un rango de un mes da lo mismo que CMH_Metrics::fleet_availability().
 *   MTTR = horas parada por averías / nº de averías.
 *   MTBF = horas de operación real (base − parada por averías) / nº de averías.
 *   Cumplimiento preventivo = tareas con fecha límite en el periodo cerradas a
 *     tiempo / tareas con fecha límite en el periodo. Fuente: tabla `tasks`
 *     (las que genera el cron de mantenimiento recurrente y las manuales).
 *
 * Salvedad conocida (heredada, ver PLAN.md 16.5): la base usa el
 * scheduled_hours_monthly ACTUAL de cada máquina para todos los meses del rango
 * y asume que la máquina existió durante todo el periodo.
 *
 * Sin librerías de gráficas: todo es SVG generado en PHP por CMH_Charts (R4).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Reports {

    /** Tope de meses del rango, para que una fecha absurda no dispare el coste de la página. */
    const MAX_MONTHS = 36;

    /**
     * Contexto de render. `acl === null` = sin restricción (administrador).
     * Con ACL, `companies`/`cities` vacíos significan «este usuario no tiene
     * nada asignado» y toda consulta devuelve vacío.
     */
    private static $ctx = [
        'audience' => 'admin',
        'acl'      => null,
        'page'     => '',
    ];

    // =========================================================================
    // Init y contexto
    // =========================================================================

    public static function init() {
        add_action( 'admin_post_cm_export_report', [ __CLASS__, 'export_report' ] );
    }

    /**
     * @param string     $audience 'admin' | 'client'
     * @param array|null $acl      [ 'companies' => int[], 'cities' => int[] ] o null
     * @param string     $page     Slug de la página que hospeda los reportes.
     */
    public static function set_context( $audience, $acl = null, $page = '' ) {
        self::$ctx = [
            'audience' => $audience === 'client' ? 'client' : 'admin',
            'acl'      => $acl,
            'page'     => $page ?: ( $audience === 'client' ? 'cmh-client-reports' : CMH_SLUG . '-reports' ),
        ];
    }

    /** Restablece el contexto de administrador (útil tras render parciales). */
    public static function reset_context() {
        self::set_context( 'admin', null, CMH_SLUG . '-reports' );
    }

    public static function is_client() { return self::$ctx['audience'] === 'client'; }

    private static function page_slug() {
        return self::$ctx['page'] ?: CMH_SLUG . '-reports';
    }

    /** URL de la ficha de una máquina, según audiencia. */
    private static function machine_url( $machine_id ) {
        return self::is_client()
            ? CMH_Admin::admin_url( 'cmh-client', [ 'machine_id' => $machine_id ] )
            : CMH_Admin::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $machine_id ] );
    }

    /** Etiquetas que cambian según quién mira. */
    private static function L( $key ) {
        $map = [
            'costo_total'  => [ 'admin' => 'Costo total',    'client' => 'Total facturado' ],
            'costo'        => [ 'admin' => 'Costo',          'client' => 'Facturado' ],
            'por_cobrar'   => [ 'admin' => 'Por cobrar',     'client' => 'Pendiente por pagar' ],
            'saldo_hint'   => [ 'admin' => 'saldo pendiente','client' => 'saldo a tu cargo' ],
            'pagado'       => [ 'admin' => 'Cobrado',        'client' => 'Pagado' ],
            'machines'     => [ 'admin' => 'Máquinas',       'client' => 'Equipos' ],
            'machine'      => [ 'admin' => 'Máquina',        'client' => 'Equipo' ],
            'ranking'      => [ 'admin' => 'Máquinas — peor disponibilidad del periodo', 'client' => 'Tus equipos — disponibilidad del periodo' ],
            'costs_title'  => [ 'admin' => 'Costos de mantenimiento', 'client' => 'Inversión en mantenimiento' ],
            'costs_hint'   => [
                'admin'  => 'Costo facturado por mes, cuánto se ha cobrado y cuánto queda pendiente.',
                'client' => 'Cuánto se facturó por el mantenimiento de tus equipos cada mes, cuánto está pagado y cuánto queda pendiente.',
            ],
        ];
        $row = $map[ $key ] ?? null;
        if ( ! $row ) return $key;
        return $row[ self::$ctx['audience'] ] ?? $row['admin'];
    }

    // =========================================================================
    // Filtros y rango
    // =========================================================================

    /** Lee y sanea los filtros desde $_GET. */
    public static function filters() {
        $to   = self::sanitize_month( $_GET['to']   ?? '' ) ?: current_time( 'Y-m' );
        $from = self::sanitize_month( $_GET['from'] ?? '' );
        if ( ! $from ) {
            $from = date( 'Y-m', strtotime( '-11 months', strtotime( $to . '-01' ) ) );
        }
        // Rango invertido: lo enderezamos en vez de devolver una tabla vacía.
        if ( $from > $to ) { $tmp = $from; $from = $to; $to = $tmp; }

        return [
            'company_id' => intval( $_GET['company_id'] ?? 0 ),
            'city_id'    => intval( $_GET['city_id']    ?? 0 ),
            'machine_id' => intval( $_GET['machine_id'] ?? 0 ),
            'brand'      => sanitize_text_field( $_GET['brand'] ?? '' ),
            'dim'        => self::sanitize_dim( $_GET['dim'] ?? '' ),
            'from'       => $from,
            'to'         => $to,
        ];
    }

    /** Filtros de un alcance concreto, sin leer $_GET (dashboard, hoja de vida). */
    public static function make_filters( $args = [] ) {
        $to   = $args['to']   ?? current_time( 'Y-m' );
        $from = $args['from'] ?? date( 'Y-m', strtotime( '-11 months', strtotime( $to . '-01' ) ) );
        return array_merge( [
            'company_id' => 0,
            'city_id'    => 0,
            'machine_id' => 0,
            'brand'      => '',
            'dim'        => '',
            'from'       => $from,
            'to'         => $to,
        ], $args );
    }

    /** Dimensiones de agrupación disponibles. */
    public static function dimensions() {
        return [
            'company' => 'Empresa',
            'city'    => 'Ciudad / Sucursal',
            'machine' => self::L( 'machine' ),
            'brand'   => 'Marca',
            'model'   => 'Modelo',
        ];
    }

    private static function sanitize_dim( $v ) {
        $v = sanitize_key( $v );
        return isset( self::dimensions()[ $v ] ) ? $v : '';
    }

    /**
     * Dimensión efectiva: la que pidió el usuario o, si no pidió ninguna, la que
     * aporta información en ese nivel de alcance (comparar empresas cuando ya
     * filtraste una sola empresa no dice nada).
     */
    public static function effective_dim( $f ) {
        if ( $f['dim'] ) return $f['dim'];
        if ( $f['machine_id'] ) return '';
        if ( $f['city_id']    ) return 'machine';
        if ( $f['company_id'] ) return 'city';
        return self::is_client() ? 'city' : 'company';
    }

    /** Valida 'YYYY-MM'. Devuelve '' si no lo es. */
    private static function sanitize_month( $v ) {
        $v = sanitize_text_field( $v );
        return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $v ) ? $v : '';
    }

    /** Primer día del mes 'from'. */
    private static function date_start( $f ) { return $f['from'] . '-01'; }

    /** Último día del mes 'to'. */
    private static function date_end( $f ) { return date( 'Y-m-t', strtotime( $f['to'] . '-01' ) ); }

    /**
     * Meses del rango como lista ordenada: [ ['y'=>2026,'m'=>7,'label'=>'Jul 2026'], ... ]
     * Acotado a MAX_MONTHS.
     */
    public static function months( $f ) {
        $cur = new DateTime( $f['from'] . '-01' );
        $end = new DateTime( $f['to']   . '-01' );
        $out = [];
        while ( $cur <= $end && count( $out ) < self::MAX_MONTHS ) {
            $y = (int) $cur->format( 'Y' );
            $m = (int) $cur->format( 'n' );
            $out[] = [ 'y' => $y, 'm' => $m, 'label' => CMH_Metrics::month_label( $m, $y ) ];
            $cur->modify( '+1 month' );
        }
        return $out;
    }

    /**
     * Cláusula WHERE de alcance sobre el alias `m` (machines): filtros del
     * usuario + restricción de acceso del contexto.
     *
     * @return array [ string $sql, array $params ]
     */
    private static function scope( $f, $alias = 'm' ) {
        $sql = []; $params = [];

        if ( ! empty( $f['company_id'] ) ) { $sql[] = "$alias.company_id=%d"; $params[] = (int) $f['company_id']; }
        if ( ! empty( $f['city_id']    ) ) { $sql[] = "$alias.city_id=%d";    $params[] = (int) $f['city_id'];    }
        if ( ! empty( $f['machine_id'] ) ) { $sql[] = "$alias.id=%d";         $params[] = (int) $f['machine_id']; }
        if ( ! empty( $f['brand']      ) ) { $sql[] = "$alias.brand=%s";      $params[] = $f['brand'];            }

        $acl = self::$ctx['acl'];
        if ( is_array( $acl ) ) {
            $co = array_map( 'intval', $acl['companies'] ?? [] );
            $ci = array_map( 'intval', $acl['cities']    ?? [] );
            if ( ! $co && ! $ci ) {
                $sql[] = '1=0';   // sin nada asignado: no ve nada
            } else {
                $or = [];
                if ( $co ) $or[] = "$alias.company_id IN (" . implode( ',', $co ) . ")";
                if ( $ci ) $or[] = "$alias.city_id IN ("    . implode( ',', $ci ) . ")";
                $sql[] = '(' . implode( ' OR ', $or ) . ')';
            }
        }

        return [ $sql ? ' AND ' . implode( ' AND ', $sql ) : '', $params ];
    }

    /** Ejecuta una consulta con o sin parámetros preparados, según haga falta. */
    private static function q( $sql, $params, $method = 'get_results' ) {
        global $wpdb;
        return $params ? $wpdb->$method( $wpdb->prepare( $sql, $params ) ) : $wpdb->$method( $sql );
    }

    // =========================================================================
    // Consultas
    // =========================================================================

    /** Horas programadas/mes y nº de máquinas dentro del filtro. */
    public static function scope_totals( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $params ) = self::scope( $f );
        $sql = "SELECT COALESCE(SUM(m.scheduled_hours_monthly),0) sched, COUNT(*) machines
                FROM {$t['machines']} m WHERE 1=1 $where";
        $row = self::q( $sql, $params, 'get_row' );
        return [ 'sched' => (float) $row->sched, 'machines' => (int) $row->machines ];
    }

    /** Totales de intervenciones del periodo dentro del filtro. */
    public static function period_totals( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(i.affects_availability=1),0) averias,
                    COALESCE(SUM(i.maintenance_type='preventivo'),0) preventivos,
                    COALESCE(SUM(i.maintenance_type IN('correctivo','averia')),0) correctivos,
                    COALESCE(SUM(i.maintenance_type='evaluacion'),0) evaluaciones,
                    COALESCE(SUM(CASE WHEN i.affects_availability=1 THEN i.downtime_hours ELSE 0 END),0) dt_averia,
                    COALESCE(SUM(i.downtime_hours),0) dt_total,
                    COALESCE(SUM(i.cost),0) costo,
                    COALESCE(SUM(i.paid_amount),0) pagado,
                    COALESCE(SUM(CASE WHEN i.cost>i.paid_amount THEN i.cost-i.paid_amount ELSE 0 END),0) por_cobrar
             FROM {$t['interventions']} i
             JOIN {$t['machines']} m ON m.id=i.machine_id
             WHERE i.intervention_date BETWEEN %s AND %s $where",
            $params
        ) );
    }

    /** Serie mensual del periodo: disponibilidad, averías, preventivos y dinero por mes. */
    public static function monthly_series( $f, $sched ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT YEAR(i.intervention_date) y, MONTH(i.intervention_date) m,
                    COALESCE(SUM(CASE WHEN i.affects_availability=1 THEN i.downtime_hours ELSE 0 END),0) dt,
                    COALESCE(SUM(i.affects_availability=1),0) averias,
                    COALESCE(SUM(i.maintenance_type='preventivo'),0) preventivos,
                    COALESCE(SUM(i.maintenance_type IN('correctivo','averia')),0) correctivos,
                    COUNT(*) total,
                    COALESCE(SUM(i.cost),0) costo,
                    COALESCE(SUM(i.paid_amount),0) pagado,
                    COALESCE(SUM(CASE WHEN i.cost>i.paid_amount THEN i.cost-i.paid_amount ELSE 0 END),0) por_cobrar
             FROM {$t['interventions']} i
             JOIN {$t['machines']} m ON m.id=i.machine_id
             WHERE i.intervention_date BETWEEN %s AND %s $where
             GROUP BY y, m",
            $params
        ) );

        $by_key = [];
        foreach ( $rows as $r ) $by_key[ (int) $r->y . '-' . (int) $r->m ] = $r;

        $out = [];
        foreach ( self::months( $f ) as $mo ) {
            $r  = $by_key[ $mo['y'] . '-' . $mo['m'] ] ?? null;
            $dt = $r ? (float) $r->dt : 0.0;
            $av = $r ? (int)   $r->averias : 0;
            $out[] = [
                'y'            => $mo['y'],
                'm'            => $mo['m'],
                'label'        => $mo['label'],
                // Base de un mes: las horas programadas de las máquinas del filtro.
                'availability' => $sched > 0 ? min( 100.0, max( 0.0, ( $sched - $dt ) / $sched * 100 ) ) : null,
                'downtime'     => $dt,
                'averias'      => $av,
                'preventivos'  => $r ? (int)   $r->preventivos : 0,
                'correctivos'  => $r ? (int)   $r->correctivos : 0,
                'total'        => $r ? (int)   $r->total       : 0,
                'costo'        => $r ? (float) $r->costo       : 0.0,
                'pagado'       => $r ? (float) $r->pagado      : 0.0,
                'por_cobrar'   => $r ? (float) $r->por_cobrar  : 0.0,
                'mttr'         => $av > 0 ? round( $dt / $av, 2 ) : null,
                'mtbf'         => $av > 0 && $sched > 0 ? round( max( 0, $sched - $dt ) / $av, 2 ) : null,
            ];
        }
        return $out;
    }

    /** Columnas de agrupación por dimensión: [ expr id, expr nombre, JOIN, ¿enlazable? ] */
    private static function dim_sql( $dim, $t ) {
        switch ( $dim ) {
            case 'city':
                return [ 'm.city_id', 'ci.name', "JOIN {$t['cities']} ci ON ci.id=m.city_id", 'city_id' ];
            case 'machine':
                return [ 'm.id', 'm.machine_code', '', 'machine_id' ];
            case 'brand':
                return [ "COALESCE(NULLIF(m.brand,''),'—')", "COALESCE(NULLIF(m.brand,''),'—')", '', 'brand' ];
            case 'model':
                return [ "COALESCE(NULLIF(m.model,''),'—')", "COALESCE(NULLIF(m.model,''),'—')", '', '' ];
            case 'company':
            default:
                return [ 'm.company_id', 'co.name', "JOIN {$t['companies']} co ON co.id=m.company_id", 'company_id' ];
        }
    }

    /**
     * Comparativa agrupada por cualquier dimensión (empresa, ciudad/sucursal,
     * máquina, marca o modelo) con todos los indicadores del periodo.
     */
    public static function by_dimension( $f, $dim ) {
        global $wpdb; $t = CMH_Core::tables();
        if ( ! $dim ) return [];

        $n_months = max( 1, count( self::months( $f ) ) );
        list( $id_col, $name_col, $join, $link_key ) = self::dim_sql( $dim, $t );

        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        // El filtro de fechas va en el ON del LEFT JOIN: así los grupos sin
        // intervenciones en el periodo siguen apareciendo (con 0), en vez de
        // desaparecer de la comparativa.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT $id_col gid, $name_col gname,
                    COUNT(DISTINCT m.id) machines,
                    COALESCE(SUM(CASE WHEN i.affects_availability=1 THEN i.downtime_hours ELSE 0 END),0) dt,
                    COALESCE(SUM(i.affects_availability=1),0) averias,
                    COALESCE(SUM(i.maintenance_type='preventivo'),0) preventivos,
                    COALESCE(SUM(i.maintenance_type IN('correctivo','averia')),0) correctivos,
                    COUNT(i.id) total,
                    COALESCE(SUM(i.cost),0) costo,
                    COALESCE(SUM(i.paid_amount),0) pagado,
                    COALESCE(SUM(CASE WHEN i.cost>i.paid_amount THEN i.cost-i.paid_amount ELSE 0 END),0) por_cobrar
             FROM {$t['machines']} m
             $join
             LEFT JOIN {$t['interventions']} i
                    ON i.machine_id=m.id AND i.intervention_date BETWEEN %s AND %s
             WHERE 1=1 $where
             GROUP BY gid, gname
             ORDER BY gname",
            $params
        ) );

        // Las horas programadas se consultan aparte: en la consulta de arriba el
        // LEFT JOIN duplica la fila de la máquina por cada intervención e inflaría la suma.
        $sched_by_gid = [];
        $sql2  = "SELECT $id_col gid, COALESCE(SUM(m.scheduled_hours_monthly),0) sched
                  FROM {$t['machines']} m $join WHERE 1=1 $where GROUP BY gid";
        $rows2 = self::q( $sql2, $sparams );
        foreach ( $rows2 as $r2 ) $sched_by_gid[ (string) $r2->gid ] = (float) $r2->sched;

        $out = [];
        foreach ( $rows as $r ) {
            $sched = $sched_by_gid[ (string) $r->gid ] ?? 0.0;
            $base  = $sched * $n_months;
            $dt    = (float) $r->dt;
            $av    = (int) $r->averias;
            $out[] = [
                'id'           => $r->gid,
                'link_key'     => $link_key,
                'name'         => $r->gname,
                'machines'     => (int) $r->machines,
                'availability' => $base > 0 ? min( 100.0, max( 0.0, ( $base - $dt ) / $base * 100 ) ) : null,
                'mttr'         => $av > 0 ? round( $dt / $av, 2 ) : null,
                'mtbf'         => $av > 0 && $base > 0 ? round( max( 0, $base - $dt ) / $av, 2 ) : null,
                'averias'      => $av,
                'preventivos'  => (int)   $r->preventivos,
                'correctivos'  => (int)   $r->correctivos,
                'total'        => (int)   $r->total,
                'downtime'     => $dt,
                'costo'        => (float) $r->costo,
                'pagado'       => (float) $r->pagado,
                'por_cobrar'   => (float) $r->por_cobrar,
            ];
        }
        return $out;
    }

    /** Ranking de máquinas del filtro, peor disponibilidad primero. */
    public static function machine_ranking( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        $n_months = max( 1, count( self::months( $f ) ) );

        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.machine_code, m.brand, m.model, m.status, m.scheduled_hours_monthly sched,
                    co.name company_name, ci.name city_name,
                    COALESCE(SUM(CASE WHEN i.affects_availability=1 THEN i.downtime_hours ELSE 0 END),0) dt,
                    COALESCE(SUM(i.affects_availability=1),0) averias,
                    COALESCE(SUM(i.maintenance_type='preventivo'),0) preventivos,
                    COUNT(i.id) total,
                    COALESCE(SUM(i.cost),0) costo,
                    COALESCE(SUM(i.paid_amount),0) pagado,
                    COALESCE(SUM(CASE WHEN i.cost>i.paid_amount THEN i.cost-i.paid_amount ELSE 0 END),0) por_cobrar
             FROM {$t['machines']} m
             JOIN {$t['companies']} co ON co.id=m.company_id
             JOIN {$t['cities']}    ci ON ci.id=m.city_id
             LEFT JOIN {$t['interventions']} i
                    ON i.machine_id=m.id AND i.intervention_date BETWEEN %s AND %s
             WHERE 1=1 $where
             GROUP BY m.id, m.machine_code, m.brand, m.model, m.status,
                      m.scheduled_hours_monthly, co.name, ci.name",
            $params
        ) );

        $out = [];
        foreach ( $rows as $r ) {
            $base = (float) $r->sched * $n_months;
            $dt   = (float) $r->dt;
            $av   = (int) $r->averias;
            $out[] = [
                'id'           => (int) $r->id,
                'machine_code' => $r->machine_code,
                'equipo'       => trim( $r->brand . ' ' . $r->model ),
                'ubicacion'    => $r->company_name . ' / ' . $r->city_name,
                'status'       => $r->status,
                'availability' => $base > 0 ? min( 100.0, max( 0.0, ( $base - $dt ) / $base * 100 ) ) : null,
                'mttr'         => $av > 0 ? round( $dt / $av, 2 ) : null,
                'mtbf'         => $av > 0 && $base > 0 ? round( max( 0, $base - $dt ) / $av, 2 ) : null,
                'averias'      => $av,
                'preventivos'  => (int)   $r->preventivos,
                'total'        => (int)   $r->total,
                'downtime'     => $dt,
                'costo'        => (float) $r->costo,
                'pagado'       => (float) $r->pagado,
                'por_cobrar'   => (float) $r->por_cobrar,
            ];
        }

        // Peor disponibilidad primero; las máquinas sin base (N/A) al final.
        usort( $out, function ( $a, $b ) {
            if ( $a['availability'] === null ) return 1;
            if ( $b['availability'] === null ) return -1;
            if ( $a['availability'] == $b['availability'] ) return $b['averias'] <=> $a['averias'];
            return $a['availability'] <=> $b['availability'];
        } );
        return $out;
    }

    /** Distribución de averías por sistema fallado. */
    public static function failures_by_system( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT COALESCE(NULLIF(i.failure_system,''),'') sistema,
                    COUNT(*) n,
                    COALESCE(SUM(i.downtime_hours),0) dt
             FROM {$t['interventions']} i
             JOIN {$t['machines']} m ON m.id=i.machine_id
             WHERE i.affects_availability=1
               AND i.intervention_date BETWEEN %s AND %s $where
             GROUP BY sistema
             ORDER BY n DESC",
            $params
        ) );

        $labels = CMH_Admin::failure_systems();
        $out = [];
        foreach ( $rows as $r ) {
            $key = (string) $r->sistema;
            $out[] = [
                'key'      => $key,
                'label'    => $key === '' ? 'Sin especificar' : ( $labels[ $key ] ?? ucfirst( $key ) ),
                'n'        => (int) $r->n,
                'downtime' => (float) $r->dt,
            ];
        }
        return $out;
    }

    /**
     * Cumplimiento del plan preventivo, a partir de las tareas con fecha límite
     * dentro del periodo (las que crea el cron de mantenimiento recurrente y las
     * que se agregan a mano en la hoja de vida).
     *
     * «A tiempo» = tarea completada con fecha de cierre ≤ fecha límite.
     *
     * @return array [ 'totals' => [...], 'series' => [ mes => [...] ] ]
     */
    public static function compliance( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        $expr_done   = "tk.status='completada'";
        $expr_ontime = "tk.status='completada' AND tk.updated_at IS NOT NULL AND DATE(tk.updated_at) <= tk.due_date";
        $expr_late   = "tk.status<>'completada' AND tk.due_date < CURDATE()";

        $totals = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) programadas,
                    COALESCE(SUM($expr_done),0)   ejecutadas,
                    COALESCE(SUM($expr_ontime),0) a_tiempo,
                    COALESCE(SUM($expr_late),0)   vencidas
             FROM {$t['tasks']} tk
             JOIN {$t['machines']} m ON m.id=tk.machine_id
             WHERE tk.due_date IS NOT NULL AND tk.due_date BETWEEN %s AND %s $where",
            $params
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT YEAR(tk.due_date) y, MONTH(tk.due_date) m,
                    COUNT(*) programadas,
                    COALESCE(SUM($expr_done),0)   ejecutadas,
                    COALESCE(SUM($expr_ontime),0) a_tiempo
             FROM {$t['tasks']} tk
             JOIN {$t['machines']} m ON m.id=tk.machine_id
             WHERE tk.due_date IS NOT NULL AND tk.due_date BETWEEN %s AND %s $where
             GROUP BY y, m",
            $params
        ) );

        $by_key = [];
        foreach ( $rows as $r ) $by_key[ (int) $r->y . '-' . (int) $r->m ] = $r;

        $series = [];
        foreach ( self::months( $f ) as $mo ) {
            $r    = $by_key[ $mo['y'] . '-' . $mo['m'] ] ?? null;
            $prog = $r ? (int) $r->programadas : 0;
            $ont  = $r ? (int) $r->a_tiempo    : 0;
            $series[] = [
                'label'       => $mo['label'],
                'programadas' => $prog,
                'ejecutadas'  => $r ? (int) $r->ejecutadas : 0,
                'a_tiempo'    => $ont,
                'pct'         => $prog > 0 ? round( $ont / $prog * 100, 1 ) : null,
            ];
        }

        $prog = (int) $totals->programadas;
        return [
            'totals' => [
                'programadas' => $prog,
                'ejecutadas'  => (int) $totals->ejecutadas,
                'a_tiempo'    => (int) $totals->a_tiempo,
                'vencidas'    => (int) $totals->vencidas,
                'pct'         => $prog > 0 ? round( (int) $totals->a_tiempo / $prog * 100, 1 ) : null,
            ],
            'series' => $series,
        ];
    }

    /** Intervenciones del periodo dentro del filtro (detalle al bajar a una máquina). */
    public static function interventions( $f, $limit = 60 ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );
        $params[] = (int) $limit;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, m.machine_code, m.id machine_id, fl.file_url
             FROM {$t['interventions']} i
             JOIN {$t['machines']} m ON m.id=i.machine_id
             LEFT JOIN {$t['files']} fl ON fl.intervention_id=i.id
             WHERE i.intervention_date BETWEEN %s AND %s $where
             ORDER BY i.intervention_date DESC, i.id DESC
             LIMIT %d",
            $params
        ) );
    }

    /** Marcas presentes dentro del alcance, para el selector de filtros. */
    public static function brands( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        $bare = $f; $bare['brand'] = ''; $bare['machine_id'] = 0;
        list( $where, $params ) = self::scope( $bare );
        $sql = "SELECT DISTINCT m.brand FROM {$t['machines']} m WHERE m.brand<>'' $where ORDER BY m.brand";
        return array_map( function ( $r ) { return $r->brand; }, self::q( $sql, $params ) );
    }

    // =========================================================================
    // Gráficas (delegan en CMH_Charts)
    // =========================================================================

    /** Barras de disponibilidad mensual (0–100%), coloreadas por umbral. */
    public static function chart_availability( $series, $opts = [] ) {
        $points = [];
        foreach ( $series as $p ) {
            $points[] = [
                'label' => $p['label'],
                'value' => $p['availability'],
                'color' => CMH_Charts::avail_color( $p['availability'] ),
                'tip'   => $p['label'] . ' — ' . CMH_Metrics::fmt_pct( $p['availability'] )
                         . ' · ' . $p['averias'] . ' averías · ' . self::hours( $p['downtime'] ) . ' parada',
            ];
        }
        return CMH_Charts::bars( $points, array_merge( [
            'pct'       => true,
            'label'     => 'Disponibilidad mensual',
            'value_fmt' => function ( $v ) { return number_format( $v, 0 ); },
        ], $opts ) );
    }

    /** Barras horizontales de averías por sistema. */
    public static function chart_systems( $data ) {
        $rows = [];
        foreach ( $data as $d ) {
            $rows[] = [
                'label' => $d['label'],
                'value' => $d['n'],
                'note'  => (string) $d['n'],
                'tip'   => $d['label'] . ' — ' . $d['n'] . ' averías · ' . self::hours( $d['downtime'] ) . ' parada',
            ];
        }
        return CMH_Charts::hbars( $rows, [ 'label' => 'Averías por sistema fallado' ] );
    }

    /** Barras agrupadas preventivo vs. correctivo por mes. */
    public static function chart_mix( $series ) {
        $points = [];
        foreach ( $series as $p ) {
            $points[] = [
                'label'  => $p['label'],
                'values' => [ $p['preventivos'], $p['correctivos'] ],
                'tips'   => [
                    $p['label'] . ' — ' . $p['preventivos'] . ' preventivos',
                    $p['label'] . ' — ' . $p['correctivos'] . ' correctivos/averías',
                ],
            ];
        }
        return CMH_Charts::groups( $points, [
            [ 'label' => 'Preventivos',          'color' => CMH_Charts::GREEN ],
            [ 'label' => 'Correctivos / averías','color' => CMH_Charts::AMBER ],
        ], [ 'label' => 'Preventivo frente a correctivo por mes' ] );
    }

    /** Línea de costos por mes: facturado, pagado y saldo. */
    public static function chart_costs( $series ) {
        $points = [];
        foreach ( $series as $p ) {
            $points[] = [
                'label'  => $p['label'],
                'values' => [ $p['costo'], $p['pagado'], $p['por_cobrar'] ],
                'tips'   => [
                    $p['label'] . ' — ' . self::L( 'costo' ) . ': ' . self::money( $p['costo'] ),
                    $p['label'] . ' — ' . self::L( 'pagado' ) . ': ' . self::money( $p['pagado'] ),
                    $p['label'] . ' — ' . self::L( 'por_cobrar' ) . ': ' . self::money( $p['por_cobrar'] ),
                ],
            ];
        }
        return CMH_Charts::line( $points, [
            [ 'label' => self::L( 'costo' ),      'color' => CMH_Charts::BLUE,  'fill' => true ],
            [ 'label' => self::L( 'pagado' ),     'color' => CMH_Charts::GREEN ],
            [ 'label' => self::L( 'por_cobrar' ), 'color' => CMH_Charts::RED ],
        ], [ 'label' => 'Tendencia de costos', 'axis_fmt' => function ( $v ) { return '$' . CMH_Charts::short_num( $v ); } ] );
    }

    /** Barras de cumplimiento del plan preventivo por mes. */
    public static function chart_compliance( $series ) {
        $points = [];
        foreach ( $series as $p ) {
            $points[] = [
                'label' => $p['label'],
                'value' => $p['pct'],
                'color' => CMH_Charts::avail_color( $p['pct'] ),
                'tip'   => $p['label'] . ' — ' . ( $p['pct'] === null ? 'sin mantenimientos programados' :
                            number_format( $p['pct'], 1, ',', '.' ) . '% · ' . $p['a_tiempo'] . ' a tiempo de ' . $p['programadas'] ),
            ];
        }
        return CMH_Charts::bars( $points, [
            'pct'       => true,
            'label'     => 'Cumplimiento del plan preventivo',
            'value_fmt' => function ( $v ) { return number_format( $v, 0 ); },
        ] );
    }

    /** Comparativa horizontal de disponibilidad por dimensión. */
    public static function chart_dimension( $groups ) {
        $rows = [];
        foreach ( $groups as $g ) {
            $rows[] = [
                'label' => $g['name'],
                'value' => $g['availability'],
                'color' => CMH_Charts::avail_color( $g['availability'] ),
                'note'  => CMH_Metrics::fmt_pct( $g['availability'] ),
                'tip'   => $g['name'] . ' — ' . CMH_Metrics::fmt_pct( $g['availability'] )
                         . ' · ' . $g['machines'] . ' máquina(s) · ' . $g['averias'] . ' averías',
            ];
        }
        return CMH_Charts::hbars( $rows, [ 'label' => 'Disponibilidad comparada', 'max' => 100, 'padR' => 80 ] );
    }

    // =========================================================================
    // Formato
    // =========================================================================

    public static function money( $v ) { return '$' . number_format( (float) $v, 0, ',', '.' ); }
    public static function hours( $v ) { return number_format( (float) $v, 1, ',', '.' ) . ' h'; }

    public static function avail_badge( $pct ) {
        if ( $pct === null ) return '<span style="color:#8c8f94">N/A</span>';
        $cls = $pct >= 90 ? 'cmh-avail-ok' : ( $pct >= 70 ? 'cmh-avail-warn' : 'cmh-avail-danger' );
        return '<span class="cmh-avail-badge ' . $cls . '">' . esc_html( CMH_Metrics::fmt_pct( $pct ) ) . '</span>';
    }

    private static function pct( $v ) {
        return $v === null ? 'N/A' : number_format( (float) $v, 1, ',', '.' ) . '%';
    }

    // =========================================================================
    // Bloques de render — los comparten la página admin, el portal del cliente,
    // el dashboard y la hoja de vida.
    // =========================================================================

    /** Cinta de KPIs del periodo. */
    public static function render_kpis( $f, $scope, $totals, $comp = null ) {
        $n     = max( 1, count( self::months( $f ) ) );
        $base  = $scope['sched'] * $n;
        $dt    = (float) $totals->dt_averia;
        $av    = (int) $totals->averias;
        $avail = $base > 0 ? min( 100.0, max( 0.0, ( $base - $dt ) / $base * 100 ) ) : null;
        $mttr  = $av > 0 ? round( $dt / $av, 2 ) : null;
        $mtbf  = $av > 0 && $base > 0 ? round( max( 0, $base - $dt ) / $av, 2 ) : null;
        $acc   = $avail === null ? 'blue' : ( $avail >= 90 ? 'ok' : ( $avail >= 70 ? 'warn' : 'danger' ) );

        echo '<div class="cmh-grid">';
        CMH_Admin::metric_card( 'Disponibilidad', CMH_Metrics::fmt_pct( $avail ), 'periodo completo', $acc );
        CMH_Admin::metric_card( 'MTTR', CMH_Metrics::fmt_mttr( $mttr ), 'promedio por avería', 'warn' );
        CMH_Admin::metric_card( 'MTBF', CMH_Metrics::fmt_mttr( $mtbf ), 'operación entre fallas', 'blue' );
        if ( $comp !== null ) {
            $cacc = $comp['pct'] === null ? 'blue' : ( $comp['pct'] >= 90 ? 'ok' : ( $comp['pct'] >= 70 ? 'warn' : 'danger' ) );
            CMH_Admin::metric_card( 'Cumplimiento preventivo', self::pct( $comp['pct'] ),
                $comp['programadas'] . ' programado(s)', $cacc );
        }
        CMH_Admin::metric_card( 'Intervenciones', (int) $totals->total,       'en el periodo', 'blue' );
        CMH_Admin::metric_card( 'Preventivos',    (int) $totals->preventivos, 'en el periodo', 'ok' );
        CMH_Admin::metric_card( 'Averías',        $av,                        'en el periodo', 'danger' );
        CMH_Admin::metric_card( 'Horas parada',   self::hours( $totals->dt_averia ), 'por averías', 'danger' );
        CMH_Admin::metric_card( self::L( 'costo_total' ), self::money( $totals->costo ),  'en el periodo', 'blue' );
        CMH_Admin::metric_card( self::L( 'pagado' ),      self::money( $totals->pagado ), 'en el periodo', 'ok' );
        CMH_Admin::metric_card( self::L( 'por_cobrar' ),  self::money( $totals->por_cobrar ),
            self::L( 'saldo_hint' ), (float) $totals->por_cobrar > 0 ? 'warn' : 'ok' );
        echo '</div>';
    }

    /** Tendencia mensual: gráfica de disponibilidad + tabla mes a mes. */
    public static function render_trend( $f, $scope, $series, $export = true ) {
        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Tendencia de disponibilidad</h2>'
            . ( $export ? '<a class="button" href="' . esc_url( self::export_url( 'monthly', $f ) ) . '">Exportar CSV</a>' : '' )
            . '</div>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Base mensual: '
            . esc_html( number_format( $scope['sched'], 0, ',', '.' ) ) . ' h programadas entre '
            . intval( $scope['machines'] ) . ' ' . esc_html( strtolower( self::L( 'machines' ) ) )
            . '. Solo las averías descuentan disponibilidad.</p>'
            . '<div class="cmh-chart">' . self::chart_availability( $series ) . '</div>';

        echo '<table class="widefat cmh" style="margin-top:16px"><thead><tr>'
            . '<th>Mes</th><th>Disponibilidad</th><th>MTTR</th><th>MTBF</th><th>Intervenciones</th>'
            . '<th>Preventivos</th><th>Averías</th><th>H. parada</th><th>' . esc_html( self::L( 'costo' ) ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $series as $p ) {
            echo '<tr>'
                . '<td><strong>' . esc_html( $p['label'] ) . '</strong></td>'
                . '<td>' . self::avail_badge( $p['availability'] ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $p['mttr'] ) ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $p['mtbf'] ) ) . '</td>'
                . '<td>' . intval( $p['total'] ) . '</td>'
                . '<td>' . intval( $p['preventivos'] ) . '</td>'
                . '<td>' . intval( $p['averias'] ) . '</td>'
                . '<td>' . esc_html( self::hours( $p['downtime'] ) ) . '</td>'
                . '<td>' . esc_html( self::money( $p['costo'] ) ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Mezcla de mantenimiento: barras por mes + anillo de composición. */
    public static function render_mix( $f, $totals, $series ) {
        $prev = (int) $totals->preventivos;
        $corr = (int) $totals->correctivos;
        $eval = (int) $totals->evaluaciones;
        $tot  = $prev + $corr + $eval;

        echo '<div class="cmh-panel"><h2>Preventivo frente a correctivo</h2>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Una flota sana se sostiene con preventivos: '
            . 'mientras más peso tenga el correctivo, más se está apagando incendios.</p>';

        if ( ! $tot ) {
            echo CMH_Charts::empty_note( 'Sin intervenciones registradas en el periodo.' ) . '</div>';
            return;
        }

        echo '<div class="cmh-split">'
            . '<div class="cmh-split-main">'
            . CMH_Charts::legend( [
                [ 'label' => 'Preventivos',           'color' => CMH_Charts::GREEN ],
                [ 'label' => 'Correctivos / averías', 'color' => CMH_Charts::AMBER ],
            ] )
            . '<div class="cmh-chart">' . self::chart_mix( $series ) . '</div>'
            . '</div>'
            . '<div class="cmh-split-side">'
            . CMH_Charts::donut( [
                [ 'label' => 'Preventivos',           'value' => $prev, 'color' => CMH_Charts::GREEN ],
                [ 'label' => 'Correctivos / averías', 'value' => $corr, 'color' => CMH_Charts::AMBER ],
                [ 'label' => 'Evaluaciones',          'value' => $eval, 'color' => CMH_Charts::GRAY ],
            ], [
                'label'  => 'Composición de intervenciones',
                'center' => $tot > 0 ? number_format( $prev / $tot * 100, 0 ) . '%' : '',
                'hint'   => 'preventivo',
            ] )
            . '<p style="font-size:12px;color:#646970;margin:8px 0 0;text-align:center">'
            . intval( $prev ) . ' preventivos · ' . intval( $corr ) . ' correctivos'
            . ( $eval ? ' · ' . intval( $eval ) . ' evaluaciones' : '' ) . '</p>'
            . '</div></div></div>';
    }

    /** Costos del periodo: línea de facturado/pagado/saldo + tabla. */
    public static function render_costs( $f, $totals, $series, $export = true ) {
        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>' . esc_html( self::L( 'costs_title' ) ) . '</h2>'
            . ( $export ? '<a class="button" href="' . esc_url( self::export_url( 'costs', $f ) ) . '">Exportar CSV</a>' : '' )
            . '</div>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">' . esc_html( self::L( 'costs_hint' ) ) . '</p>';

        if ( (float) $totals->costo <= 0 && (float) $totals->pagado <= 0 ) {
            echo CMH_Charts::empty_note( 'Sin costos registrados en el periodo.' ) . '</div>';
            return;
        }

        echo CMH_Charts::legend( [
                [ 'label' => self::L( 'costo' ),      'color' => CMH_Charts::BLUE ],
                [ 'label' => self::L( 'pagado' ),     'color' => CMH_Charts::GREEN ],
                [ 'label' => self::L( 'por_cobrar' ), 'color' => CMH_Charts::RED ],
            ] )
            . '<div class="cmh-chart">' . self::chart_costs( $series ) . '</div>';

        $prom = (int) $totals->total > 0 ? (float) $totals->costo / (int) $totals->total : 0;
        echo '<div class="cmh-grid" style="margin-top:16px">';
        CMH_Admin::metric_card( self::L( 'costo_total' ), self::money( $totals->costo ),  'periodo', 'blue' );
        CMH_Admin::metric_card( self::L( 'pagado' ),      self::money( $totals->pagado ), 'periodo', 'ok' );
        CMH_Admin::metric_card( self::L( 'por_cobrar' ),  self::money( $totals->por_cobrar ),
            self::L( 'saldo_hint' ), (float) $totals->por_cobrar > 0 ? 'warn' : 'ok' );
        CMH_Admin::metric_card( 'Costo promedio', self::money( $prom ), 'por intervención', 'blue' );
        echo '</div>';

        echo '<table class="widefat cmh" style="margin-top:16px"><thead><tr>'
            . '<th>Mes</th><th>Intervenciones</th><th>' . esc_html( self::L( 'costo' ) ) . '</th>'
            . '<th>' . esc_html( self::L( 'pagado' ) ) . '</th><th>' . esc_html( self::L( 'por_cobrar' ) ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $series as $p ) {
            echo '<tr>'
                . '<td><strong>' . esc_html( $p['label'] ) . '</strong></td>'
                . '<td>' . intval( $p['total'] ) . '</td>'
                . '<td>' . esc_html( self::money( $p['costo'] ) ) . '</td>'
                . '<td>' . esc_html( self::money( $p['pagado'] ) ) . '</td>'
                . '<td>' . ( $p['por_cobrar'] > 0
                    ? '<span style="color:#d63638">' . esc_html( self::money( $p['por_cobrar'] ) ) . '</span>'
                    : esc_html( self::money( 0 ) ) ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Cumplimiento del plan preventivo. */
    public static function render_compliance( $f, $comp, $export = true ) {
        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Cumplimiento del plan preventivo</h2>'
            . ( $export ? '<a class="button" href="' . esc_url( self::export_url( 'compliance', $f ) ) . '">Exportar CSV</a>' : '' )
            . '</div>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Mantenimientos programados con fecha límite en el periodo '
            . 'frente a los que se cerraron a tiempo. Fuente: las tareas de mantenimiento (automáticas y manuales).</p>';

        if ( ! $comp['totals']['programadas'] ) {
            echo CMH_Charts::empty_note( 'No hay mantenimientos programados con fecha límite dentro del periodo. '
                . 'Se cuentan las tareas que genera la recurrencia de mantenimiento y las creadas a mano.' ) . '</div>';
            return;
        }

        echo '<div class="cmh-grid">';
        $t = $comp['totals'];
        $acc = $t['pct'] === null ? 'blue' : ( $t['pct'] >= 90 ? 'ok' : ( $t['pct'] >= 70 ? 'warn' : 'danger' ) );
        CMH_Admin::metric_card( 'Cumplimiento', self::pct( $t['pct'] ), 'cerrados a tiempo', $acc );
        CMH_Admin::metric_card( 'Programados',  $t['programadas'], 'en el periodo', 'blue' );
        CMH_Admin::metric_card( 'Ejecutados',   $t['ejecutadas'],  'completados',   'ok' );
        CMH_Admin::metric_card( 'Vencidos',     $t['vencidas'],    'sin cerrar y fuera de fecha', $t['vencidas'] > 0 ? 'danger' : 'ok' );
        echo '</div>';

        echo '<div class="cmh-chart" style="margin-top:12px">' . self::chart_compliance( $comp['series'] ) . '</div>';

        echo '<table class="widefat cmh" style="margin-top:16px"><thead><tr>'
            . '<th>Mes</th><th>Programados</th><th>Ejecutados</th><th>A tiempo</th><th>Cumplimiento</th>'
            . '</tr></thead><tbody>';
        foreach ( $comp['series'] as $p ) {
            echo '<tr>'
                . '<td><strong>' . esc_html( $p['label'] ) . '</strong></td>'
                . '<td>' . intval( $p['programadas'] ) . '</td>'
                . '<td>' . intval( $p['ejecutadas'] ) . '</td>'
                . '<td>' . intval( $p['a_tiempo'] ) . '</td>'
                . '<td>' . self::avail_badge( $p['pct'] ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Comparativa por dimensión: gráfica + tabla, con enlace para bajar de nivel. */
    public static function render_dimension( $f, $dim, $groups, $export = true ) {
        if ( ! $dim ) return;
        $label = self::dimensions()[ $dim ] ?? $dim;

        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Comparativa por ' . esc_html( mb_strtolower( $label ) ) . '</h2>'
            . ( $export ? '<a class="button" href="' . esc_url( self::export_url( 'dimension', $f ) ) . '">Exportar CSV</a>' : '' )
            . '</div>';

        if ( ! $groups ) {
            echo CMH_Charts::empty_note( 'Sin datos para el filtro actual.' ) . '</div>';
            return;
        }

        echo '<div class="cmh-chart">' . self::chart_dimension( $groups ) . '</div>';

        // Con dimensiones grandes (una fila por máquina de toda la flota) la tabla
        // se vuelve inmanejable: se acota y el CSV sigue trayendo todo.
        $all = count( $groups );
        if ( $all > 50 ) {
            $groups = array_slice( $groups, 0, 50 );
            echo '<p style="font-size:12px;color:#646970;margin:12px 0 0">Mostrando 50 de ' . intval( $all )
                . '. El CSV incluye todos.</p>';
        }

        echo '<table class="widefat cmh" style="margin-top:16px"><thead><tr>'
            . '<th>' . esc_html( $label ) . '</th><th>' . esc_html( self::L( 'machines' ) ) . '</th>'
            . '<th>Disponibilidad</th><th>MTTR</th><th>MTBF</th><th>Intervenciones</th><th>Preventivos</th>'
            . '<th>Averías</th><th>H. parada</th><th>' . esc_html( self::L( 'costo' ) ) . '</th>'
            . '<th>' . esc_html( self::L( 'por_cobrar' ) ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $groups as $g ) {
            $name = esc_html( $g['name'] );
            if ( $g['link_key'] === 'machine_id' ) {
                $cell = '<a href="' . esc_url( self::machine_url( (int) $g['id'] ) ) . '"><strong>' . $name . '</strong></a>';
            } elseif ( $g['link_key'] ) {
                $args = [ 'from' => $f['from'], 'to' => $f['to'] ];
                if ( $g['link_key'] !== 'company_id' && $f['company_id'] ) $args['company_id'] = $f['company_id'];
                if ( $g['link_key'] !== 'city_id'    && $f['city_id']    ) $args['city_id']    = $f['city_id'];
                $args[ $g['link_key'] ] = $g['id'];
                $cell = '<a href="' . esc_url( CMH_Admin::admin_url( self::page_slug(), $args ) ) . '"><strong>' . $name . '</strong></a>';
            } else {
                $cell = '<strong>' . $name . '</strong>';
            }

            echo '<tr>'
                . '<td>' . $cell . '</td>'
                . '<td>' . intval( $g['machines'] ) . '</td>'
                . '<td>' . self::avail_badge( $g['availability'] ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $g['mttr'] ) ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $g['mtbf'] ) ) . '</td>'
                . '<td>' . intval( $g['total'] ) . '</td>'
                . '<td>' . intval( $g['preventivos'] ) . '</td>'
                . '<td>' . intval( $g['averias'] ) . '</td>'
                . '<td>' . esc_html( self::hours( $g['downtime'] ) ) . '</td>'
                . '<td>' . esc_html( self::money( $g['costo'] ) ) . '</td>'
                . '<td>' . esc_html( self::money( $g['por_cobrar'] ) ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Ranking de máquinas del alcance. */
    public static function render_ranking( $f, $ranking, $export = true ) {
        $shown = array_slice( $ranking, 0, 20 );

        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>' . esc_html( self::L( 'ranking' ) ) . '</h2>'
            . ( $export ? '<a class="button" href="' . esc_url( self::export_url( 'machines', $f ) ) . '">Exportar CSV</a>' : '' )
            . '</div>';

        if ( ! $ranking ) {
            echo CMH_Charts::empty_note( 'Sin equipos en el alcance actual.' ) . '</div>';
            return;
        }
        if ( count( $ranking ) > count( $shown ) ) {
            echo '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Mostrando ' . count( $shown )
                . ' de ' . count( $ranking ) . '. El CSV incluye todas.</p>';
        }

        echo '<table class="widefat cmh"><thead><tr>'
            . '<th>#</th><th>' . esc_html( self::L( 'machine' ) ) . '</th><th>Equipo</th><th>Ubicación</th>'
            . '<th>Disponibilidad</th><th>MTTR</th><th>MTBF</th><th>Averías</th><th>Preventivos</th>'
            . '<th>H. parada</th><th>' . esc_html( self::L( 'costo' ) ) . '</th><th></th>'
            . '</tr></thead><tbody>';
        foreach ( $shown as $i => $r ) {
            echo '<tr>'
                . '<td style="color:#8c8f94">' . ( $i + 1 ) . '</td>'
                . '<td><strong>' . esc_html( $r['machine_code'] ) . '</strong></td>'
                . '<td>' . esc_html( $r['equipo'] ) . '</td>'
                . '<td style="font-size:12px;color:#646970">' . esc_html( $r['ubicacion'] ) . '</td>'
                . '<td>' . self::avail_badge( $r['availability'] ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $r['mttr'] ) ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $r['mtbf'] ) ) . '</td>'
                . '<td>' . intval( $r['averias'] ) . '</td>'
                . '<td>' . intval( $r['preventivos'] ) . '</td>'
                . '<td>' . esc_html( self::hours( $r['downtime'] ) ) . '</td>'
                . '<td>' . esc_html( self::money( $r['costo'] ) ) . '</td>'
                . '<td style="white-space:nowrap">'
                . '<a class="button button-small" href="' . esc_url( self::machine_url( $r['id'] ) ) . '">Ver ficha</a> '
                . '<a class="button button-small" href="' . esc_url( CMH_Admin::admin_url( self::page_slug(), [ 'machine_id' => $r['id'], 'from' => $f['from'], 'to' => $f['to'] ] ) ) . '">Reporte</a>'
                . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Averías por sistema fallado. */
    public static function render_systems( $f, $systems, $export = true ) {
        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Averías por sistema</h2>'
            . ( $export ? '<a class="button" href="' . esc_url( self::export_url( 'systems', $f ) ) . '">Exportar CSV</a>' : '' )
            . '</div>';
        if ( ! $systems ) {
            echo CMH_Charts::empty_note( 'No hay averías registradas en el periodo. Solo cuentan las intervenciones que afectan disponibilidad.' );
        } else {
            echo '<div class="cmh-chart">' . self::chart_systems( $systems ) . '</div>';
        }
        echo '</div>';
    }

    /** Detalle de intervenciones — se muestra al bajar el alcance a una máquina. */
    public static function render_interventions( $f ) {
        $rows = self::interventions( $f );
        echo '<div class="cmh-panel"><h2>Intervenciones del periodo</h2>';
        if ( ! $rows ) {
            echo CMH_Charts::empty_note( 'Sin intervenciones registradas en el rango seleccionado.' ) . '</div>';
            return;
        }
        echo '<table class="widefat cmh"><thead><tr>'
            . '<th>Fecha</th><th>' . esc_html( self::L( 'machine' ) ) . '</th><th>Tipo</th><th>Técnico</th>'
            . '<th>Sistema</th><th>H. parada</th><th>' . esc_html( self::L( 'costo' ) ) . '</th><th>Pago</th><th>PDF</th>'
            . '</tr></thead><tbody>';
        $labels = CMH_Admin::failure_systems();
        foreach ( $rows as $r ) {
            $sys = $r->failure_system ? ( $labels[ $r->failure_system ] ?? ucfirst( $r->failure_system ) ) : '—';
            echo '<tr>'
                . '<td>' . esc_html( $r->intervention_date ) . '</td>'
                . '<td><a href="' . esc_url( self::machine_url( (int) $r->machine_id ) ) . '">' . esc_html( $r->machine_code ) . '</a></td>'
                . '<td>' . esc_html( ucfirst( $r->maintenance_type ?: $r->form_type ) ) . '</td>'
                . '<td>' . esc_html( $r->technician ?: '—' ) . '</td>'
                . '<td>' . esc_html( $sys ) . '</td>'
                . '<td>' . esc_html( self::hours( $r->downtime_hours ) ) . '</td>'
                . '<td>' . esc_html( self::money( $r->cost ) ) . '</td>'
                . '<td>' . CMH_Admin::payment_badge( $r->payment_status, $r->cost, $r->paid_amount ) . '</td>'
                . '<td>' . ( $r->file_url ? '<a target="_blank" href="' . esc_url( $r->file_url ) . '">Ver</a>' : '—' ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // =========================================================================
    // Barra de filtros
    // =========================================================================

    /**
     * Selector de alcance: empresa → ciudad/sucursal → máquina, más marca,
     * rango de meses y dimensión de comparación.
     */
    public static function render_filters( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        $slug = self::page_slug();

        // Las listas se limitan al mismo alcance permitido: un cliente solo ve lo suyo.
        list( $acl_where, $acl_params ) = self::scope( [ 'company_id' => 0, 'city_id' => 0, 'machine_id' => 0, 'brand' => '' ] );

        $companies = self::q(
            "SELECT DISTINCT c.id, c.name FROM {$t['companies']} c
             JOIN {$t['machines']} m ON m.company_id=c.id WHERE 1=1 $acl_where ORDER BY c.name",
            $acl_params
        );

        $city_f = [ 'company_id' => $f['company_id'], 'city_id' => 0, 'machine_id' => 0, 'brand' => '' ];
        list( $cw, $cp ) = self::scope( $city_f );
        $cities = self::q(
            "SELECT DISTINCT ci.id, ci.name FROM {$t['cities']} ci
             JOIN {$t['machines']} m ON m.city_id=ci.id WHERE 1=1 $cw ORDER BY ci.name",
            $cp
        );

        $mach_f = [ 'company_id' => $f['company_id'], 'city_id' => $f['city_id'], 'machine_id' => 0, 'brand' => $f['brand'] ];
        list( $mw, $mp ) = self::scope( $mach_f );
        $machines = self::q(
            "SELECT m.id, m.machine_code FROM {$t['machines']} m WHERE 1=1 $mw ORDER BY m.machine_code",
            $mp
        );

        $brands = self::brands( $f );

        echo '<div class="cmh-panel"><form method="get" class="cmh-report-filters">'
            . '<input type="hidden" name="page" value="' . esc_attr( $slug ) . '">'
            . '<label>Empresa<select name="company_id"><option value="0">Todas</option>';
        foreach ( $companies as $c )
            echo '<option value="' . intval( $c->id ) . '" ' . selected( $f['company_id'], $c->id, false ) . '>' . esc_html( $c->name ) . '</option>';
        echo '</select></label>'
            . '<label>Ciudad / Sucursal<select name="city_id"><option value="0">Todas</option>';
        foreach ( $cities as $c )
            echo '<option value="' . intval( $c->id ) . '" ' . selected( $f['city_id'], $c->id, false ) . '>' . esc_html( $c->name ) . '</option>';
        echo '</select></label>'
            . '<label>' . esc_html( self::L( 'machine' ) ) . '<select name="machine_id"><option value="0">Todas</option>';
        foreach ( $machines as $mm )
            echo '<option value="' . intval( $mm->id ) . '" ' . selected( $f['machine_id'], $mm->id, false ) . '>' . esc_html( $mm->machine_code ) . '</option>';
        echo '</select></label>';

        if ( $brands ) {
            echo '<label>Marca<select name="brand"><option value="">Todas</option>';
            foreach ( $brands as $b )
                echo '<option value="' . esc_attr( $b ) . '" ' . selected( $f['brand'], $b, false ) . '>' . esc_html( $b ) . '</option>';
            echo '</select></label>';
        }

        echo '<label>Comparar por<select name="dim"><option value="">Automático</option>';
        foreach ( self::dimensions() as $k => $v )
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $f['dim'], $k, false ) . '>' . esc_html( $v ) . '</option>';
        echo '</select></label>'
            . '<label>Desde<input type="month" name="from" value="' . esc_attr( $f['from'] ) . '"></label>'
            . '<label>Hasta<input type="month" name="to" value="' . esc_attr( $f['to'] ) . '"></label>'
            . '<button class="button button-primary">Aplicar</button>'
            . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( $slug ) ) . '">Limpiar</a>'
            . '</form></div>';
    }

    /** Texto legible del alcance activo, para el encabezado. */
    private static function scope_label( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        $parts = [];
        if ( $f['company_id'] ) {
            $n = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$t['companies']} WHERE id=%d", $f['company_id'] ) );
            if ( $n ) $parts[] = $n;
        }
        if ( $f['city_id'] ) {
            $n = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$t['cities']} WHERE id=%d", $f['city_id'] ) );
            if ( $n ) $parts[] = $n;
        }
        if ( $f['machine_id'] ) {
            $n = $wpdb->get_var( $wpdb->prepare( "SELECT machine_code FROM {$t['machines']} WHERE id=%d", $f['machine_id'] ) );
            if ( $n ) $parts[] = $n;
        }
        if ( $f['brand'] ) $parts[] = 'Marca ' . $f['brand'];
        return $parts ? implode( ' › ', $parts ) : 'Toda la flota';
    }

    // =========================================================================
    // Página de reportes (admin y cliente comparten cuerpo)
    // =========================================================================

    public static function page_reports() {
        if ( ! self::is_client() && ! current_user_can( 'edit_others_posts' ) ) wp_die( 'Sin permisos.' );

        $f      = self::filters();
        $months = self::months( $f );
        $n      = max( 1, count( $months ) );
        $scope  = self::scope_totals( $f );

        $range_label = $months
            ? $months[0]['label'] . ' – ' . $months[ count( $months ) - 1 ]['label'] . ' (' . $n . ' meses)'
            : 'Sin rango';

        CMH_Admin::page_header( 'Reportes' );

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">' . ( self::is_client() ? 'Indicadores de tus equipos' : 'Analítica' ) . '</div>'
            . '<h2>' . esc_html( self::scope_label( $f ) ) . '</h2>'
            . '<p>' . esc_html( $range_label ) . ' &nbsp;·&nbsp; ' . intval( $scope['machines'] ) . ' '
            . esc_html( mb_strtolower( self::L( 'machines' ) ) ) . ' en el alcance</p>'
            . '</div><div class="cmh-hero-actions">'
            . '<a class="button cmh-btn-print" href="#">Imprimir / PDF</a>'
            . '<a class="button" href="' . esc_url( self::export_url( 'machines', $f ) ) . '">Exportar ' . esc_html( mb_strtolower( self::L( 'machines' ) ) ) . ' (CSV)</a>'
            . '</div></div>';

        self::render_filters( $f );

        if ( ! $scope['machines'] ) {
            echo '<div class="cmh-panel"><p style="margin:0;color:#646970">'
                . ( self::is_client()
                    ? 'No hay equipos disponibles con este filtro. Si crees que falta algo, pide que te asignen el acceso.'
                    : 'No hay máquinas que coincidan con el filtro.' )
                . '</p></div>';
            CMH_Admin::page_footer();
            return;
        }

        $totals = self::period_totals( $f );
        $series = self::monthly_series( $f, $scope['sched'] );
        $comp   = self::compliance( $f );
        $dim    = self::effective_dim( $f );

        self::render_kpis( $f, $scope, $totals, $comp['totals'] );
        self::render_trend( $f, $scope, $series );
        self::render_mix( $f, $totals, $series );
        self::render_costs( $f, $totals, $series );
        self::render_compliance( $f, $comp );

        if ( $dim ) {
            self::render_dimension( $f, $dim, self::by_dimension( $f, $dim ) );
        }

        if ( $f['machine_id'] ) {
            self::render_interventions( $f );
        } elseif ( $dim !== 'machine' ) {
            // Con la comparativa ya agrupada por máquina, el ranking repetiría lo mismo.
            self::render_ranking( $f, self::machine_ranking( $f ) );
        }

        self::render_systems( $f, self::failures_by_system( $f ) );

        CMH_Admin::page_footer();
    }

    // =========================================================================
    // Bloques embebidos — dashboard y hoja de vida
    // =========================================================================

    /**
     * Panel compacto de tendencia para el dashboard: disponibilidad, mezcla de
     * mantenimiento y costos de los últimos 12 meses de toda la flota.
     */
    public static function dashboard_charts() {
        $f     = self::make_filters();
        $scope = self::scope_totals( $f );
        if ( ! $scope['machines'] ) return;

        $series = self::monthly_series( $f, $scope['sched'] );
        $totals = self::period_totals( $f );

        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Tendencia de la flota <small style="font-weight:400;font-size:13px;color:#646970">— últimos 12 meses</small></h2>'
            . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-reports' ) ) . '">Ver reportería completa</a>'
            . '</div>'
            . '<div class="cmh-chart">' . self::chart_availability( $series ) . '</div>';

        echo '<div class="cmh-chart-row">'
            . '<div><h3 class="cmh-chart-title">Preventivo frente a correctivo</h3>'
            . CMH_Charts::legend( [
                [ 'label' => 'Preventivos',           'color' => CMH_Charts::GREEN ],
                [ 'label' => 'Correctivos / averías', 'color' => CMH_Charts::AMBER ],
            ] )
            . '<div class="cmh-chart">' . self::chart_mix( $series ) . '</div></div>'
            . '<div><h3 class="cmh-chart-title">Costos por mes</h3>'
            . CMH_Charts::legend( [
                [ 'label' => 'Costo',      'color' => CMH_Charts::BLUE ],
                [ 'label' => 'Cobrado',    'color' => CMH_Charts::GREEN ],
                [ 'label' => 'Por cobrar', 'color' => CMH_Charts::RED ],
            ] )
            . '<div class="cmh-chart">' . self::chart_costs( $series ) . '</div></div>'
            . '</div>';

        $systems = self::failures_by_system( $f );
        if ( $systems ) {
            echo '<h3 class="cmh-chart-title">Averías por sistema — últimos 12 meses</h3>'
                . '<div class="cmh-chart">' . self::chart_systems( $systems ) . '</div>';
        }
        echo '</div>';
    }

    /**
     * Indicadores gráficos de UNA máquina, para la hoja de vida y para la ficha
     * del portal del cliente.
     *
     * @param bool $with_costs Mostrar el bloque de dinero.
     */
    public static function machine_charts( $machine_id, $with_costs = true ) {
        $f     = self::make_filters( [ 'machine_id' => (int) $machine_id ] );
        $scope = self::scope_totals( $f );
        if ( ! $scope['machines'] ) {
            echo CMH_Charts::empty_note( 'Sin datos para graficar todavía.' );
            return;
        }

        $series = self::monthly_series( $f, $scope['sched'] );
        $totals = self::period_totals( $f );

        echo '<h3 class="cmh-chart-title">Disponibilidad mensual — últimos 12 meses</h3>'
            . '<div class="cmh-chart">' . self::chart_availability( $series ) . '</div>';

        echo '<div class="cmh-chart-row">'
            . '<div><h3 class="cmh-chart-title">Intervenciones por mes</h3>'
            . CMH_Charts::legend( [
                [ 'label' => 'Preventivos',           'color' => CMH_Charts::GREEN ],
                [ 'label' => 'Correctivos / averías', 'color' => CMH_Charts::AMBER ],
            ] )
            . '<div class="cmh-chart">' . self::chart_mix( $series ) . '</div></div>';

        if ( $with_costs ) {
            echo '<div><h3 class="cmh-chart-title">' . esc_html( self::L( 'costs_title' ) ) . ' por mes</h3>'
                . CMH_Charts::legend( [
                    [ 'label' => self::L( 'costo' ),      'color' => CMH_Charts::BLUE ],
                    [ 'label' => self::L( 'pagado' ),     'color' => CMH_Charts::GREEN ],
                    [ 'label' => self::L( 'por_cobrar' ), 'color' => CMH_Charts::RED ],
                ] )
                . '<div class="cmh-chart">' . self::chart_costs( $series ) . '</div></div>';
        }
        echo '</div>';

        $systems = self::failures_by_system( $f );
        if ( $systems ) {
            echo '<h3 class="cmh-chart-title">Averías por sistema</h3>'
                . '<div class="cmh-chart">' . self::chart_systems( $systems ) . '</div>';
        }

        $n     = max( 1, count( self::months( $f ) ) );
        $base  = $scope['sched'] * $n;
        $av    = (int) $totals->averias;
        $dt    = (float) $totals->dt_averia;
        echo '<p style="font-size:12px;color:#646970;margin-top:14px">Últimos 12 meses: '
            . intval( $totals->total ) . ' intervenciones · ' . $av . ' averías · '
            . esc_html( self::hours( $dt ) ) . ' de parada · MTBF '
            . esc_html( CMH_Metrics::fmt_mttr( $av > 0 && $base > 0 ? round( max( 0, $base - $dt ) / $av, 2 ) : null ) ) . '.</p>';
    }

    // =========================================================================
    // Export CSV
    // =========================================================================

    private static function export_url( $block, $f ) {
        return wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( [
            'action'     => 'cm_export_report',
            'block'      => $block,
            'company_id' => $f['company_id'],
            'city_id'    => $f['city_id'],
            'machine_id' => $f['machine_id'],
            'brand'      => $f['brand'],
            'dim'        => $f['dim'],
            'from'       => $f['from'],
            'to'         => $f['to'],
        ] ) ), 'cmh_action' );
    }

    public static function export_report() {
        check_admin_referer( 'cmh_action' );

        // Un cliente exporta lo mismo que ve: se le impone su ACL antes de consultar.
        if ( current_user_can( 'edit_others_posts' ) ) {
            self::reset_context();
        } elseif ( current_user_can( 'cmh_client' ) ) {
            CMH_Client::apply_report_context();
        } else {
            wp_die( 'Sin permisos.' );
        }

        $f     = self::filters();
        $block = sanitize_key( $_GET['block'] ?? '' );
        $stamp = $f['from'] . '_' . $f['to'];

        switch ( $block ) {
            case 'monthly':
                $scope = self::scope_totals( $f );
                CMH_Admin::csv_headers( 'reporte-mensual-' . $stamp . '.csv' );
                CMH_Admin::csv_row( [ 'Mes', 'Disponibilidad %', 'MTTR h', 'MTBF h', 'Intervenciones', 'Preventivos', 'Averías', 'Horas parada', 'Costo' ] );
                foreach ( self::monthly_series( $f, $scope['sched'] ) as $p ) {
                    CMH_Admin::csv_row( [
                        $p['label'],
                        $p['availability'] === null ? 'N/A' : number_format( $p['availability'], 2, ',', '' ),
                        $p['mttr'] === null ? 'N/A' : number_format( $p['mttr'], 2, ',', '' ),
                        $p['mtbf'] === null ? 'N/A' : number_format( $p['mtbf'], 2, ',', '' ),
                        $p['total'], $p['preventivos'], $p['averias'],
                        number_format( $p['downtime'], 2, ',', '' ),
                        number_format( $p['costo'], 2, ',', '' ),
                    ] );
                }
                break;

            case 'costs':
                $scope = self::scope_totals( $f );
                CMH_Admin::csv_headers( 'reporte-costos-' . $stamp . '.csv' );
                CMH_Admin::csv_row( [ 'Mes', 'Intervenciones', 'Costo', 'Pagado', 'Saldo' ] );
                foreach ( self::monthly_series( $f, $scope['sched'] ) as $p ) {
                    CMH_Admin::csv_row( [
                        $p['label'], $p['total'],
                        number_format( $p['costo'], 2, ',', '' ),
                        number_format( $p['pagado'], 2, ',', '' ),
                        number_format( $p['por_cobrar'], 2, ',', '' ),
                    ] );
                }
                break;

            case 'compliance':
                $comp = self::compliance( $f );
                CMH_Admin::csv_headers( 'reporte-cumplimiento-' . $stamp . '.csv' );
                CMH_Admin::csv_row( [ 'Mes', 'Programados', 'Ejecutados', 'A tiempo', 'Cumplimiento %' ] );
                foreach ( $comp['series'] as $p ) {
                    CMH_Admin::csv_row( [
                        $p['label'], $p['programadas'], $p['ejecutadas'], $p['a_tiempo'],
                        $p['pct'] === null ? 'N/A' : number_format( $p['pct'], 2, ',', '' ),
                    ] );
                }
                break;

            case 'dimension':
            case 'companies':
            case 'cities':
                $dim = $block === 'cities' ? 'city' : ( $block === 'companies' ? 'company' : self::effective_dim( $f ) );
                if ( ! $dim ) $dim = 'company';
                CMH_Admin::csv_headers( 'reporte-' . $dim . '-' . $stamp . '.csv' );
                CMH_Admin::csv_row( [ self::dimensions()[ $dim ], 'Máquinas', 'Disponibilidad %', 'MTTR h', 'MTBF h',
                    'Intervenciones', 'Preventivos', 'Averías', 'Horas parada', 'Costo', 'Pagado', 'Saldo' ] );
                foreach ( self::by_dimension( $f, $dim ) as $g ) {
                    CMH_Admin::csv_row( [
                        $g['name'], $g['machines'],
                        $g['availability'] === null ? 'N/A' : number_format( $g['availability'], 2, ',', '' ),
                        $g['mttr'] === null ? 'N/A' : number_format( $g['mttr'], 2, ',', '' ),
                        $g['mtbf'] === null ? 'N/A' : number_format( $g['mtbf'], 2, ',', '' ),
                        $g['total'], $g['preventivos'], $g['averias'],
                        number_format( $g['downtime'], 2, ',', '' ),
                        number_format( $g['costo'], 2, ',', '' ),
                        number_format( $g['pagado'], 2, ',', '' ),
                        number_format( $g['por_cobrar'], 2, ',', '' ),
                    ] );
                }
                break;

            case 'systems':
                CMH_Admin::csv_headers( 'reporte-sistemas-' . $stamp . '.csv' );
                CMH_Admin::csv_row( [ 'Sistema', 'Averías', 'Horas parada' ] );
                foreach ( self::failures_by_system( $f ) as $s ) {
                    CMH_Admin::csv_row( [ $s['label'], $s['n'], number_format( $s['downtime'], 2, ',', '' ) ] );
                }
                break;

            case 'machines':
            default:
                CMH_Admin::csv_headers( 'reporte-maquinas-' . $stamp . '.csv' );
                CMH_Admin::csv_row( [ 'Código', 'Equipo', 'Ubicación', 'Estado', 'Disponibilidad %', 'MTTR h', 'MTBF h',
                    'Intervenciones', 'Preventivos', 'Averías', 'Horas parada', 'Costo', 'Pagado', 'Saldo' ] );
                foreach ( self::machine_ranking( $f ) as $r ) {
                    CMH_Admin::csv_row( [
                        $r['machine_code'], $r['equipo'], $r['ubicacion'], $r['status'],
                        $r['availability'] === null ? 'N/A' : number_format( $r['availability'], 2, ',', '' ),
                        $r['mttr'] === null ? 'N/A' : number_format( $r['mttr'], 2, ',', '' ),
                        $r['mtbf'] === null ? 'N/A' : number_format( $r['mtbf'], 2, ',', '' ),
                        $r['total'], $r['preventivos'], $r['averias'],
                        number_format( $r['downtime'], 2, ',', '' ),
                        number_format( $r['costo'], 2, ',', '' ),
                        number_format( $r['pagado'], 2, ',', '' ),
                        number_format( $r['por_cobrar'], 2, ',', '' ),
                    ] );
                }
                break;
        }
        exit;
    }
}
