<?php
/**
 * CMH_Reports — v1.0 Reportería cruzada y gráficas de tendencia.
 *
 * Una sola página («Máquinas → Reportes») que responde las preguntas que el
 * dashboard no puede: cómo se comporta la flota en un PERIODO (no solo el mes
 * actual) y cómo se comparan entre sí empresas, ciudades y máquinas.
 *
 * Filtros: empresa, ciudad y rango de meses (por defecto, los últimos 12).
 *
 * Bloques:
 *   1. KPIs consolidados del periodo
 *   2. Tendencia mensual de disponibilidad (SVG inline)
 *   3. Comparativa por empresa y por ciudad
 *   4. Ranking de máquinas (peor disponibilidad primero)
 *   5. Distribución de averías por sistema (SVG inline)
 *   6. Preventivo vs. correctivo mes a mes
 *
 * FÓRMULA DE DISPONIBILIDAD EN PERIODO — extiende la de CMH_Metrics sin cambiarla:
 *   base  = Σ scheduled_hours_monthly (máquinas del filtro) × nº de meses del rango
 *   avail = (base − horas parada por AVERÍAS en el rango) / base × 100, acotado a 0–100
 * Con un rango de un mes da exactamente lo mismo que CMH_Metrics::fleet_availability().
 *
 * Salvedad conocida (heredada, ver PLAN.md 16.5): la base usa el
 * scheduled_hours_monthly ACTUAL de cada máquina para todos los meses del rango,
 * y asume que la máquina existió durante todo el periodo.
 *
 * Sin librerías de gráficas: las visualizaciones son SVG generado en PHP (regla R4).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Reports {

    /** Tope de meses del rango, para que una fecha absurda no dispare el coste de la página. */
    const MAX_MONTHS = 36;

    // =========================================================================
    // Init
    // =========================================================================

    public static function init() {
        add_action( 'admin_post_cm_export_report', [ __CLASS__, 'export_report' ] );
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
            'from'       => $from,
            'to'         => $to,
        ];
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
     * Cláusula WHERE de alcance sobre el alias `m` (machines).
     * @return array [ string $sql, array $params ]
     */
    private static function scope( $f, $alias = 'm' ) {
        $sql = []; $params = [];
        if ( $f['company_id'] ) { $sql[] = "$alias.company_id=%d"; $params[] = $f['company_id']; }
        if ( $f['city_id']    ) { $sql[] = "$alias.city_id=%d";    $params[] = $f['city_id'];    }
        return [ $sql ? ' AND ' . implode( ' AND ', $sql ) : '', $params ];
    }

    // =========================================================================
    // Consultas
    // =========================================================================

    /** Horas programadas/mes y nº de máquinas dentro del filtro. */
    private static function scope_totals( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $params ) = self::scope( $f );
        $sql = "SELECT COALESCE(SUM(m.scheduled_hours_monthly),0) sched, COUNT(*) machines
                FROM {$t['machines']} m WHERE 1=1 $where";
        $row = $params ? $wpdb->get_row( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_row( $sql );
        return [ 'sched' => (float) $row->sched, 'machines' => (int) $row->machines ];
    }

    /** Totales de intervenciones del periodo dentro del filtro. */
    private static function period_totals( $f ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(i.affects_availability=1),0) averias,
                    COALESCE(SUM(i.maintenance_type='preventivo'),0) preventivos,
                    COALESCE(SUM(i.maintenance_type IN('correctivo','averia')),0) correctivos,
                    COALESCE(SUM(CASE WHEN i.affects_availability=1 THEN i.downtime_hours ELSE 0 END),0) dt_averia,
                    COALESCE(SUM(i.cost),0) costo,
                    COALESCE(SUM(CASE WHEN i.cost>i.paid_amount THEN i.cost-i.paid_amount ELSE 0 END),0) por_cobrar
             FROM {$t['interventions']} i
             JOIN {$t['machines']} m ON m.id=i.machine_id
             WHERE i.intervention_date BETWEEN %s AND %s $where",
            $params
        ) );
    }

    /** Serie mensual del periodo: disponibilidad, averías, preventivos y costo por mes. */
    public static function monthly_series( $f, $sched ) {
        global $wpdb; $t = CMH_Core::tables();
        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT YEAR(i.intervention_date) y, MONTH(i.intervention_date) m,
                    COALESCE(SUM(CASE WHEN i.affects_availability=1 THEN i.downtime_hours ELSE 0 END),0) dt,
                    COALESCE(SUM(i.affects_availability=1),0) averias,
                    COALESCE(SUM(i.maintenance_type='preventivo'),0) preventivos,
                    COUNT(*) total,
                    COALESCE(SUM(i.cost),0) costo
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
            $out[] = [
                'label'       => $mo['label'],
                // Base de un mes: las horas programadas de las máquinas del filtro.
                'availability' => $sched > 0 ? min( 100.0, max( 0.0, ( $sched - $dt ) / $sched * 100 ) ) : null,
                'downtime'    => $dt,
                'averias'     => $r ? (int)   $r->averias     : 0,
                'preventivos' => $r ? (int)   $r->preventivos : 0,
                'total'       => $r ? (int)   $r->total       : 0,
                'costo'       => $r ? (float) $r->costo       : 0.0,
            ];
        }
        return $out;
    }

    /**
     * Comparativa agrupada por empresa o por ciudad.
     * @param string $dim 'company' | 'city'
     */
    public static function by_dimension( $f, $dim ) {
        global $wpdb; $t = CMH_Core::tables();
        $n_months = max( 1, count( self::months( $f ) ) );

        if ( $dim === 'city' ) {
            $id_col = 'm.city_id'; $name_tbl = $t['cities']; $join_alias = 'ci';
        } else {
            $id_col = 'm.company_id'; $name_tbl = $t['companies']; $join_alias = 'co';
        }

        list( $where, $sparams ) = self::scope( $f );
        $params = array_merge( [ self::date_start( $f ), self::date_end( $f ) ], $sparams );

        // El filtro de fechas va en el ON del LEFT JOIN: así los grupos sin
        // intervenciones en el periodo siguen apareciendo (con 0), en vez de
        // desaparecer de la comparativa.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT $id_col gid, $join_alias.name gname,
                    COUNT(DISTINCT m.id) machines,
                    COALESCE(SUM(m.scheduled_hours_monthly),0) sched_dup,
                    COALESCE(SUM(CASE WHEN i.affects_availability=1 THEN i.downtime_hours ELSE 0 END),0) dt,
                    COALESCE(SUM(i.affects_availability=1),0) averias,
                    COALESCE(SUM(i.maintenance_type='preventivo'),0) preventivos,
                    COUNT(i.id) total,
                    COALESCE(SUM(i.cost),0) costo,
                    COALESCE(SUM(CASE WHEN i.cost>i.paid_amount THEN i.cost-i.paid_amount ELSE 0 END),0) por_cobrar
             FROM {$t['machines']} m
             JOIN $name_tbl $join_alias ON $join_alias.id=$id_col
             LEFT JOIN {$t['interventions']} i
                    ON i.machine_id=m.id AND i.intervention_date BETWEEN %s AND %s
             WHERE 1=1 $where
             GROUP BY gid, gname
             ORDER BY gname",
            $params
        ) );

        // sched_dup viene inflado por el LEFT JOIN (una fila por intervención),
        // así que las horas programadas reales se consultan aparte.
        $sched_by_gid = [];
        $sql2 = "SELECT $id_col gid, COALESCE(SUM(m.scheduled_hours_monthly),0) sched
                 FROM {$t['machines']} m WHERE 1=1 $where GROUP BY gid";
        $rows2 = $sparams ? $wpdb->get_results( $wpdb->prepare( $sql2, $sparams ) ) : $wpdb->get_results( $sql2 );
        foreach ( $rows2 as $r2 ) $sched_by_gid[ (int) $r2->gid ] = (float) $r2->sched;

        $out = [];
        foreach ( $rows as $r ) {
            $sched = $sched_by_gid[ (int) $r->gid ] ?? 0.0;
            $base  = $sched * $n_months;
            $dt    = (float) $r->dt;
            $av    = (int) $r->averias;
            $out[] = [
                'id'           => (int) $r->gid,
                'name'         => $r->gname,
                'machines'     => (int) $r->machines,
                'availability' => $base > 0 ? min( 100.0, max( 0.0, ( $base - $dt ) / $base * 100 ) ) : null,
                'mttr'         => $av > 0 ? round( $dt / $av, 2 ) : null,
                'averias'      => $av,
                'preventivos'  => (int)   $r->preventivos,
                'total'        => (int)   $r->total,
                'downtime'     => $dt,
                'costo'        => (float) $r->costo,
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
                'averias'      => $av,
                'preventivos'  => (int)   $r->preventivos,
                'total'        => (int)   $r->total,
                'downtime'     => $dt,
                'costo'        => (float) $r->costo,
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

    // =========================================================================
    // Gráficas SVG (sin librerías externas — regla R4)
    // =========================================================================

    /** Color de disponibilidad según umbral, alineado con el resto de la UI. */
    private static function avail_color( $pct ) {
        if ( $pct === null ) return '#c3c4c7';
        if ( $pct >= 90 ) return '#00a32a';
        if ( $pct >= 70 ) return '#dba617';
        return '#d63638';
    }

    /**
     * Gráfica de barras verticales de disponibilidad mensual (0–100%).
     * SVG con viewBox — escala solo al ancho del contenedor.
     */
    public static function chart_availability( $series ) {
        if ( ! $series ) return '';

        $n     = count( $series );
        $slot  = 62;                 // ancho por mes
        $padL  = 46; $padR = 12; $padT = 14; $padB = 40;
        $plotH = 210;
        $w     = $padL + $padR + $n * $slot;
        $h     = $padT + $plotH + $padB;
        $barW  = min( 34, $slot - 16 );

        $y = function ( $pct ) use ( $padT, $plotH ) { return $padT + $plotH * ( 1 - $pct / 100 ); };

        $svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto;max-height:300px" '
              . 'role="img" aria-label="Disponibilidad mensual de la flota filtrada">';

        // Líneas guía y eje Y.
        foreach ( [ 0, 50, 70, 90, 100 ] as $g ) {
            $gy    = $y( $g );
            $dash  = in_array( $g, [ 70, 90 ], true ) ? ' stroke-dasharray="4 3"' : '';
            $color = in_array( $g, [ 70, 90 ], true ) ? '#dcdcde' : '#f0f0f1';
            $svg .= '<line x1="' . $padL . '" y1="' . $gy . '" x2="' . ( $w - $padR ) . '" y2="' . $gy . '" stroke="' . $color . '"' . $dash . '/>';
            $svg .= '<text x="' . ( $padL - 8 ) . '" y="' . ( $gy + 4 ) . '" text-anchor="end" font-size="11" fill="#646970">' . $g . '%</text>';
        }

        foreach ( array_values( $series ) as $i => $p ) {
            $cx = $padL + $i * $slot + $slot / 2;
            $bx = $cx - $barW / 2;

            if ( $p['availability'] === null ) {
                // Sin base de horas programadas: se marca el hueco, no se dibuja un 0 engañoso.
                $svg .= '<text x="' . $cx . '" y="' . ( $padT + $plotH - 6 ) . '" text-anchor="middle" font-size="10" fill="#a7aaad">N/A</text>';
            } else {
                $by = $y( $p['availability'] );
                $bh = max( 1, $padT + $plotH - $by );
                $svg .= '<rect x="' . $bx . '" y="' . $by . '" width="' . $barW . '" height="' . $bh . '" rx="3" fill="' . self::avail_color( $p['availability'] ) . '">'
                      . '<title>' . esc_html( $p['label'] . ' — ' . CMH_Metrics::fmt_pct( $p['availability'] )
                        . ' · ' . $p['averias'] . ' averías · ' . number_format( $p['downtime'], 1, ',', '.' ) . ' h parada' ) . '</title>'
                      . '</rect>'
                      . '<text x="' . $cx . '" y="' . ( $by - 5 ) . '" text-anchor="middle" font-size="10" fill="#3c434a">'
                      . number_format( $p['availability'], 0 ) . '</text>';
            }

            // Etiqueta del mes (dos líneas: mes / año) para que quepa sin rotar.
            $parts = explode( ' ', $p['label'] );
            $svg .= '<text x="' . $cx . '" y="' . ( $padT + $plotH + 16 ) . '" text-anchor="middle" font-size="11" fill="#3c434a">' . esc_html( $parts[0] ) . '</text>'
                  . '<text x="' . $cx . '" y="' . ( $padT + $plotH + 29 ) . '" text-anchor="middle" font-size="10" fill="#8c8f94">' . esc_html( $parts[1] ?? '' ) . '</text>';
        }

        $svg .= '<line x1="' . $padL . '" y1="' . ( $padT + $plotH ) . '" x2="' . ( $w - $padR ) . '" y2="' . ( $padT + $plotH ) . '" stroke="#c3c4c7"/>';
        return $svg . '</svg>';
    }

    /** Barras horizontales para la distribución de averías por sistema. */
    public static function chart_systems( $data ) {
        if ( ! $data ) return '';

        $data  = array_slice( $data, 0, 12 );
        $max   = max( array_map( function ( $d ) { return $d['n']; }, $data ) ) ?: 1;
        $rowH  = 26;
        $padL  = 130; $padR = 60; $padT = 8;
        $w     = 700;
        $h     = $padT * 2 + count( $data ) * $rowH;
        $barMax = $w - $padL - $padR;

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto" '
             . 'role="img" aria-label="Averías por sistema fallado">';

        foreach ( array_values( $data ) as $i => $d ) {
            $ty = $padT + $i * $rowH + $rowH / 2;
            $bw = max( 2, $barMax * $d['n'] / $max );
            $svg .= '<text x="' . ( $padL - 10 ) . '" y="' . ( $ty + 4 ) . '" text-anchor="end" font-size="12" fill="#3c434a">' . esc_html( $d['label'] ) . '</text>'
                  . '<rect x="' . $padL . '" y="' . ( $ty - 8 ) . '" width="' . $bw . '" height="16" rx="3" fill="#2271b1">'
                  . '<title>' . esc_html( $d['label'] . ' — ' . $d['n'] . ' averías · '
                    . number_format( $d['downtime'], 1, ',', '.' ) . ' h parada' ) . '</title></rect>'
                  . '<text x="' . ( $padL + $bw + 8 ) . '" y="' . ( $ty + 4 ) . '" font-size="12" fill="#646970">' . $d['n'] . '</text>';
        }
        return $svg . '</svg>';
    }

    // =========================================================================
    // Página
    // =========================================================================

    private static function money( $v ) { return '$' . number_format( (float) $v, 0, ',', '.' ); }
    private static function hours( $v ) { return number_format( (float) $v, 1, ',', '.' ) . ' h'; }

    private static function avail_badge( $pct ) {
        if ( $pct === null ) return '<span style="color:#8c8f94">N/A</span>';
        $cls = $pct >= 90 ? 'cmh-avail-ok' : ( $pct >= 70 ? 'cmh-avail-warn' : 'cmh-avail-danger' );
        return '<span class="cmh-avail-badge ' . $cls . '">' . esc_html( CMH_Metrics::fmt_pct( $pct ) ) . '</span>';
    }

    public static function page_reports() {
        global $wpdb; $t = CMH_Core::tables();
        $f      = self::filters();
        $months = self::months( $f );
        $n      = max( 1, count( $months ) );

        $scope   = self::scope_totals( $f );
        $totals  = self::period_totals( $f );
        $base    = $scope['sched'] * $n;
        $avail   = $base > 0 ? min( 100.0, max( 0.0, ( $base - (float) $totals->dt_averia ) / $base * 100 ) ) : null;
        $averias = (int) $totals->averias;
        $mttr    = $averias > 0 ? round( (float) $totals->dt_averia / $averias, 2 ) : null;

        $range_label = $months
            ? $months[0]['label'] . ' – ' . $months[ count( $months ) - 1 ]['label'] . ' (' . $n . ' meses)'
            : 'Sin rango';

        CMH_Admin::page_header( 'Reportes' );

        echo '<div class="cmh-hero-block"><div>'
            . '<div class="cmh-kicker">Analítica</div>'
            . '<h2>Reportería cruzada</h2>'
            . '<p>' . esc_html( $range_label ) . ' &nbsp;·&nbsp; ' . intval( $scope['machines'] ) . ' máquina(s) en el filtro</p>'
            . '</div><div class="cmh-hero-actions">'
            . '<a class="button cmh-btn-print" href="#">Imprimir reporte</a>'
            . '<a class="button" href="' . esc_url( self::export_url( 'machines', $f ) ) . '">Exportar máquinas (CSV)</a>'
            . '</div></div>';

        // ── Filtros ───────────────────────────────────────────────────────────
        $companies = $wpdb->get_results( "SELECT id, name FROM {$t['companies']} ORDER BY name" );
        $cities    = $f['company_id']
            ? $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$t['cities']} WHERE company_id=%d ORDER BY name", $f['company_id'] ) )
            : $wpdb->get_results( "SELECT id, name FROM {$t['cities']} ORDER BY name" );

        echo '<div class="cmh-panel"><form method="get" class="cmh-report-filters">'
            . '<input type="hidden" name="page" value="' . esc_attr( CMH_SLUG . '-reports' ) . '">'
            . '<label>Empresa<select name="company_id"><option value="0">Todas</option>';
        foreach ( $companies as $c )
            echo '<option value="' . intval( $c->id ) . '" ' . selected( $f['company_id'], $c->id, false ) . '>' . esc_html( $c->name ) . '</option>';
        echo '</select></label>'
            . '<label>Ciudad / Sucursal<select name="city_id"><option value="0">Todas</option>';
        foreach ( $cities as $c )
            echo '<option value="' . intval( $c->id ) . '" ' . selected( $f['city_id'], $c->id, false ) . '>' . esc_html( $c->name ) . '</option>';
        echo '</select></label>'
            . '<label>Desde<input type="month" name="from" value="' . esc_attr( $f['from'] ) . '"></label>'
            . '<label>Hasta<input type="month" name="to" value="' . esc_attr( $f['to'] ) . '"></label>'
            . '<button class="button button-primary">Aplicar</button>'
            . '<a class="button" href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-reports' ) ) . '">Limpiar</a>'
            . '</form></div>';

        if ( ! $scope['machines'] ) {
            echo '<div class="cmh-panel"><p style="margin:0;color:#646970">No hay máquinas que coincidan con el filtro.</p></div>';
            CMH_Admin::page_footer();
            return;
        }

        // ── KPIs del periodo ──────────────────────────────────────────────────
        $avail_acc = $avail === null ? 'blue' : ( $avail >= 90 ? 'ok' : ( $avail >= 70 ? 'warn' : 'danger' ) );
        echo '<div class="cmh-grid">';
        CMH_Admin::metric_card( 'Disponibilidad',   CMH_Metrics::fmt_pct( $avail ),                 'periodo completo',  $avail_acc );
        CMH_Admin::metric_card( 'MTTR',             CMH_Metrics::fmt_mttr( $mttr ),                 'solo averías',      'warn' );
        CMH_Admin::metric_card( 'Intervenciones',   (int) $totals->total,                           'en el periodo',     'blue' );
        CMH_Admin::metric_card( 'Averías',          $averias,                                       'en el periodo',     'danger' );
        CMH_Admin::metric_card( 'Preventivos',      (int) $totals->preventivos,                     'en el periodo',     'ok' );
        CMH_Admin::metric_card( 'Horas parada',     self::hours( $totals->dt_averia ),              'por averías',       'danger' );
        CMH_Admin::metric_card( 'Costo total',      self::money( $totals->costo ),                  'en el periodo',     'blue' );
        CMH_Admin::metric_card( 'Por cobrar',       self::money( $totals->por_cobrar ),             'saldo pendiente',   (float) $totals->por_cobrar > 0 ? 'warn' : 'ok' );
        echo '</div>';

        // ── Tendencia mensual ─────────────────────────────────────────────────
        $series = self::monthly_series( $f, $scope['sched'] );
        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Tendencia de disponibilidad</h2>'
            . '<a class="button" href="' . esc_url( self::export_url( 'monthly', $f ) ) . '">Exportar CSV</a></div>'
            . '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Base mensual: '
            . esc_html( number_format( $scope['sched'], 0, ',', '.' ) ) . ' h programadas entre '
            . intval( $scope['machines'] ) . ' máquina(s). Solo las averías descuentan disponibilidad.</p>'
            . '<div class="cmh-chart">' . self::chart_availability( $series ) . '</div>';

        // Preventivo vs correctivo mes a mes, debajo de la gráfica.
        echo '<table class="widefat cmh" style="margin-top:16px"><thead><tr>'
            . '<th>Mes</th><th>Disponibilidad</th><th>Intervenciones</th><th>Preventivos</th><th>Averías</th><th>H. parada</th><th>Costo</th>'
            . '</tr></thead><tbody>';
        foreach ( $series as $p ) {
            echo '<tr>'
                . '<td><strong>' . esc_html( $p['label'] ) . '</strong></td>'
                . '<td>' . self::avail_badge( $p['availability'] ) . '</td>'
                . '<td>' . intval( $p['total'] ) . '</td>'
                . '<td>' . intval( $p['preventivos'] ) . '</td>'
                . '<td>' . intval( $p['averias'] ) . '</td>'
                . '<td>' . esc_html( self::hours( $p['downtime'] ) ) . '</td>'
                . '<td>' . esc_html( self::money( $p['costo'] ) ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';

        // ── Comparativa por empresa / ciudad ──────────────────────────────────
        // Si ya se filtró una empresa, comparar empresas no aporta: se compara por ciudad.
        $dim       = $f['company_id'] ? 'city' : 'company';
        $dim_label = $dim === 'city' ? 'ciudad / sucursal' : 'empresa';
        $groups    = self::by_dimension( $f, $dim );

        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Comparativa por ' . esc_html( $dim_label ) . '</h2>'
            . '<a class="button" href="' . esc_url( self::export_url( $dim === 'city' ? 'cities' : 'companies', $f ) ) . '">Exportar CSV</a></div>';
        if ( ! $groups ) {
            echo '<p style="margin:0;color:#646970">Sin datos para el filtro actual.</p>';
        } else {
            echo '<table class="widefat cmh"><thead><tr>'
                . '<th>' . esc_html( ucfirst( $dim_label ) ) . '</th><th>Máquinas</th><th>Disponibilidad</th><th>MTTR</th>'
                . '<th>Intervenciones</th><th>Preventivos</th><th>Averías</th><th>H. parada</th><th>Costo</th><th>Por cobrar</th>'
                . '</tr></thead><tbody>';
            foreach ( $groups as $g ) {
                $link = $dim === 'city'
                    ? CMH_Admin::admin_url( CMH_SLUG . '-reports', [ 'city_id' => $g['id'], 'company_id' => $f['company_id'], 'from' => $f['from'], 'to' => $f['to'] ] )
                    : CMH_Admin::admin_url( CMH_SLUG . '-reports', [ 'company_id' => $g['id'], 'from' => $f['from'], 'to' => $f['to'] ] );
                echo '<tr>'
                    . '<td><a href="' . esc_url( $link ) . '"><strong>' . esc_html( $g['name'] ) . '</strong></a></td>'
                    . '<td>' . intval( $g['machines'] ) . '</td>'
                    . '<td>' . self::avail_badge( $g['availability'] ) . '</td>'
                    . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $g['mttr'] ) ) . '</td>'
                    . '<td>' . intval( $g['total'] ) . '</td>'
                    . '<td>' . intval( $g['preventivos'] ) . '</td>'
                    . '<td>' . intval( $g['averias'] ) . '</td>'
                    . '<td>' . esc_html( self::hours( $g['downtime'] ) ) . '</td>'
                    . '<td>' . esc_html( self::money( $g['costo'] ) ) . '</td>'
                    . '<td>' . esc_html( self::money( $g['por_cobrar'] ) ) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // ── Ranking de máquinas ───────────────────────────────────────────────
        $ranking = self::machine_ranking( $f );
        $shown   = array_slice( $ranking, 0, 20 );
        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Máquinas — peor disponibilidad del periodo</h2>'
            . '<a class="button" href="' . esc_url( self::export_url( 'machines', $f ) ) . '">Exportar CSV</a></div>';
        if ( count( $ranking ) > count( $shown ) ) {
            echo '<p style="font-size:12px;color:#646970;margin:-8px 0 12px">Mostrando ' . count( $shown )
                . ' de ' . count( $ranking ) . ' máquinas. El CSV incluye todas.</p>';
        }
        echo '<table class="widefat cmh"><thead><tr>'
            . '<th>#</th><th>Máquina</th><th>Equipo</th><th>Ubicación</th><th>Disponibilidad</th><th>MTTR</th>'
            . '<th>Averías</th><th>Preventivos</th><th>H. parada</th><th>Costo</th><th></th>'
            . '</tr></thead><tbody>';
        foreach ( $shown as $i => $r ) {
            echo '<tr>'
                . '<td style="color:#8c8f94">' . ( $i + 1 ) . '</td>'
                . '<td><strong>' . esc_html( $r['machine_code'] ) . '</strong></td>'
                . '<td>' . esc_html( $r['equipo'] ) . '</td>'
                . '<td style="font-size:12px;color:#646970">' . esc_html( $r['ubicacion'] ) . '</td>'
                . '<td>' . self::avail_badge( $r['availability'] ) . '</td>'
                . '<td>' . esc_html( CMH_Metrics::fmt_mttr( $r['mttr'] ) ) . '</td>'
                . '<td>' . intval( $r['averias'] ) . '</td>'
                . '<td>' . intval( $r['preventivos'] ) . '</td>'
                . '<td>' . esc_html( self::hours( $r['downtime'] ) ) . '</td>'
                . '<td>' . esc_html( self::money( $r['costo'] ) ) . '</td>'
                . '<td><a class="button button-small" href="' . esc_url( CMH_Admin::admin_url( CMH_SLUG . '-machines', [ 'machine_id' => $r['id'] ] ) ) . '">Ver</a></td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';

        // ── Distribución de averías por sistema ───────────────────────────────
        $systems = self::failures_by_system( $f );
        echo '<div class="cmh-panel"><div class="cmh-toolbar">'
            . '<h2>Averías por sistema</h2>'
            . '<a class="button" href="' . esc_url( self::export_url( 'systems', $f ) ) . '">Exportar CSV</a></div>';
        if ( ! $systems ) {
            echo '<p style="margin:0;color:#646970">No hay averías registradas en el periodo. '
                . 'Solo cuentan las intervenciones que afectan disponibilidad.</p>';
        } else {
            echo '<div class="cmh-chart">' . self::chart_systems( $systems ) . '</div>';
        }
        echo '</div>';

        CMH_Admin::page_footer();
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
            'from'       => $f['from'],
            'to'         => $f['to'],
        ] ) ), 'cmh_action' );
    }

    public static function export_report() {
        if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'cmh_action' );

        $f     = self::filters();
        $block = sanitize_key( $_GET['block'] ?? '' );
        $stamp = $f['from'] . '_' . $f['to'];

        switch ( $block ) {
            case 'monthly':
                $scope = self::scope_totals( $f );
                CMH_Admin::csv_headers( 'reporte-mensual-' . $stamp . '.csv' );
                CMH_Admin::csv_row( [ 'Mes', 'Disponibilidad %', 'Intervenciones', 'Preventivos', 'Averías', 'Horas parada', 'Costo' ] );
                foreach ( self::monthly_series( $f, $scope['sched'] ) as $p ) {
                    CMH_Admin::csv_row( [
                        $p['label'],
                        $p['availability'] === null ? 'N/A' : number_format( $p['availability'], 2, ',', '' ),
                        $p['total'], $p['preventivos'], $p['averias'],
                        number_format( $p['downtime'], 2, ',', '' ),
                        number_format( $p['costo'], 2, ',', '' ),
                    ] );
                }
                break;

            case 'companies':
            case 'cities':
                $dim = $block === 'cities' ? 'city' : 'company';
                CMH_Admin::csv_headers( 'reporte-' . $block . '-' . $stamp . '.csv' );
                CMH_Admin::csv_row( [ $dim === 'city' ? 'Ciudad/Sucursal' : 'Empresa', 'Máquinas', 'Disponibilidad %', 'MTTR h',
                    'Intervenciones', 'Preventivos', 'Averías', 'Horas parada', 'Costo', 'Por cobrar' ] );
                foreach ( self::by_dimension( $f, $dim ) as $g ) {
                    CMH_Admin::csv_row( [
                        $g['name'], $g['machines'],
                        $g['availability'] === null ? 'N/A' : number_format( $g['availability'], 2, ',', '' ),
                        $g['mttr'] === null ? 'N/A' : number_format( $g['mttr'], 2, ',', '' ),
                        $g['total'], $g['preventivos'], $g['averias'],
                        number_format( $g['downtime'], 2, ',', '' ),
                        number_format( $g['costo'], 2, ',', '' ),
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
                CMH_Admin::csv_row( [ 'Código', 'Equipo', 'Ubicación', 'Estado', 'Disponibilidad %', 'MTTR h',
                    'Intervenciones', 'Preventivos', 'Averías', 'Horas parada', 'Costo', 'Por cobrar' ] );
                foreach ( self::machine_ranking( $f ) as $r ) {
                    CMH_Admin::csv_row( [
                        $r['machine_code'], $r['equipo'], $r['ubicacion'], $r['status'],
                        $r['availability'] === null ? 'N/A' : number_format( $r['availability'], 2, ',', '' ),
                        $r['mttr'] === null ? 'N/A' : number_format( $r['mttr'], 2, ',', '' ),
                        $r['total'], $r['preventivos'], $r['averias'],
                        number_format( $r['downtime'], 2, ',', '' ),
                        number_format( $r['costo'], 2, ',', '' ),
                        number_format( $r['por_cobrar'], 2, ',', '' ),
                    ] );
                }
                break;
        }
        exit;
    }
}
