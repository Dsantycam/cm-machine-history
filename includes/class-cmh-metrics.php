<?php
/**
 * CMH_Metrics — cálculo de KPIs: disponibilidad, MTTR y criticidad.
 *
 * Fórmulas (alineadas con plantilla INDICADORES INHOUSE):
 *   Disponibilidad = (Horas programadas − Horas parada por AVERÍAS) / Horas programadas × 100
 *   Solo intervenciones con affects_availability = 1 descuentan disponibilidad.
 *   El mantenimiento (preventivo/correctivo sin parada) NO afecta la disponibilidad.
 *
 *   MTTR = Suma horas parada de AVERÍAS / Cantidad de AVERÍAS
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Metrics {

    // -------------------------------------------------------------------------
    // Disponibilidad
    // -------------------------------------------------------------------------

    /**
     * Disponibilidad de una máquina en un mes/año dado (o todo el historial si omites mes/año).
     * Retorna null si la máquina no tiene horas programadas configuradas.
     *
     * @param int      $machine_id
     * @param int|null $month  1–12
     * @param int|null $year   ej. 2025
     * @return float|null  Porcentaje 0–100 o null si N/A.
     */
    public static function availability( $machine_id, $month = null, $year = null ) {
        global $wpdb;
        $t = CMH_Core::tables();

        $scheduled = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT scheduled_hours_monthly FROM {$t['machines']} WHERE id = %d", $machine_id
        ) );
        if ( $scheduled <= 0 ) return null;

        $sql    = "SELECT COALESCE(SUM(downtime_hours),0) FROM {$t['interventions']} WHERE machine_id=%d AND affects_availability=1";
        $params = [ $machine_id ];

        if ( $month && $year ) {
            $sql    .= " AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d";
            $params  = [ $machine_id, (int) $month, (int) $year ];
        }

        $downtime = (float) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        return min( 100.0, max( 0.0, ( $scheduled - $downtime ) / $scheduled * 100 ) );
    }

    /**
     * Desglose mensual de disponibilidad para los últimos N meses con actividad.
     * Cada elemento: [ year, month, label, scheduled, downtime_averia, downtime_maintenance,
     *                  real_operation, availability, averia_count, mttr ]
     *
     * @param int $machine_id
     * @param int $months  Máximo de meses a retornar.
     * @return array
     */
    public static function monthly_breakdown( $machine_id, $months = 13 ) {
        global $wpdb;
        $t = CMH_Core::tables();

        $scheduled = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT scheduled_hours_monthly FROM {$t['machines']} WHERE id = %d", $machine_id
        ) );
        if ( $scheduled <= 0 ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                YEAR(intervention_date)   AS yr,
                MONTH(intervention_date)  AS mo,
                SUM(CASE WHEN affects_availability=1 THEN downtime_hours ELSE 0 END) AS da,
                SUM(CASE WHEN affects_availability=0 THEN downtime_hours ELSE 0 END) AS dm,
                SUM(CASE WHEN affects_availability=1 THEN 1 ELSE 0 END)              AS ac
             FROM {$t['interventions']}
             WHERE machine_id = %d
             GROUP BY yr, mo
             ORDER BY yr DESC, mo DESC
             LIMIT %d",
            $machine_id, $months
        ) );

        $out = [];
        foreach ( $rows as $r ) {
            $da    = (float) $r->da;
            $dm    = (float) $r->dm;
            $ac    = (int)   $r->ac;
            $avail = min( 100.0, max( 0.0, ( $scheduled - $da ) / $scheduled * 100 ) );
            $out[] = [
                'year'                 => (int) $r->yr,
                'month'                => (int) $r->mo,
                'label'                => self::month_label( (int) $r->mo, (int) $r->yr ),
                'scheduled'            => $scheduled,
                'downtime_averia'      => $da,
                'downtime_maintenance' => $dm,
                'real_operation'       => max( 0, $scheduled - $da ),
                'availability'         => $avail,
                'averia_count'         => $ac,
                'mttr'                 => $ac > 0 ? round( $da / $ac, 2 ) : null,
            ];
        }
        return $out;
    }

    /**
     * Disponibilidad global de la flota en un mes/año (por defecto: mes actual).
     * Usa scheduled_hours_monthly de cada máquina como base.
     *
     * @param int|null $month
     * @param int|null $year
     * @return float|null
     */
    public static function fleet_availability( $month = null, $year = null ) {
        global $wpdb;
        $t     = CMH_Core::tables();
        $month = $month ?: (int) current_time( 'n' );
        $year  = $year  ?: (int) current_time( 'Y' );

        $total_scheduled = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(scheduled_hours_monthly),0) FROM {$t['machines']}"
        );
        if ( $total_scheduled <= 0 ) return null;

        $downtime = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(downtime_hours),0) FROM {$t['interventions']}
             WHERE affects_availability=1
               AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d",
            $month, $year
        ) );

        return min( 100.0, max( 0.0, ( $total_scheduled - $downtime ) / $total_scheduled * 100 ) );
    }

    // -------------------------------------------------------------------------
    // MTTR
    // -------------------------------------------------------------------------

    /**
     * MTTR = horas parada de averías / cantidad de averías.
     * Acepta scope por máquina y/o mes+año.
     *
     * @param int      $machine_id  0 = flota completa.
     * @param int|null $month
     * @param int|null $year
     * @return float|null  Horas o null si no hay averías.
     */
    public static function mttr( $machine_id = 0, $month = null, $year = null ) {
        global $wpdb;
        $t = CMH_Core::tables();

        $where  = 'affects_availability=1';
        $params = [];

        if ( $machine_id ) {
            $where   .= ' AND machine_id=%d';
            $params[] = (int) $machine_id;
        }
        if ( $month && $year ) {
            $where   .= ' AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d';
            $params[] = (int) $month;
            $params[] = (int) $year;
        }

        $sql = "SELECT COALESCE(SUM(downtime_hours),0) AS dt, COUNT(*) AS cnt
                FROM {$t['interventions']} WHERE $where";
        $row = $params
            ? $wpdb->get_row( $wpdb->prepare( $sql, $params ) )
            : $wpdb->get_row( $sql );

        if ( ! $row || (int) $row->cnt === 0 ) return null;
        return round( (float) $row->dt / (int) $row->cnt, 2 );
    }

    // -------------------------------------------------------------------------
    // MTBF
    // -------------------------------------------------------------------------

    /**
     * v2.0 — MTBF: horas de operación real entre fallas.
     *
     *   operación = horas programadas del periodo − horas de parada por averías
     *   MTBF      = operación / cantidad de averías
     *
     * Se mide sobre una ventana móvil de meses (por defecto los últimos 12) para
     * que el indicador tenga una base temporal explícita; sin averías no hay
     * media entre fallas y devuelve null.
     *
     * @param int $machine_id 0 = flota completa.
     * @param int $months     Meses hacia atrás, contando el mes actual.
     * @return float|null Horas o null si no aplica.
     */
    public static function mtbf( $machine_id = 0, $months = 12 ) {
        global $wpdb;
        $t      = CMH_Core::tables();
        $months = max( 1, (int) $months );

        $scheduled = $machine_id
            ? (float) $wpdb->get_var( $wpdb->prepare( "SELECT scheduled_hours_monthly FROM {$t['machines']} WHERE id=%d", $machine_id ) )
            : (float) $wpdb->get_var( "SELECT COALESCE(SUM(scheduled_hours_monthly),0) FROM {$t['machines']}" );
        if ( $scheduled <= 0 ) return null;

        $from = date( 'Y-m-01', strtotime( '-' . ( $months - 1 ) . ' months', strtotime( current_time( 'Y-m-01' ) ) ) );
        $to   = date( 'Y-m-t',  strtotime( current_time( 'Y-m-01' ) ) );

        $where  = 'affects_availability=1 AND intervention_date BETWEEN %s AND %s';
        $params = [ $from, $to ];
        if ( $machine_id ) { $where .= ' AND machine_id=%d'; $params[] = (int) $machine_id; }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(SUM(downtime_hours),0) dt, COUNT(*) cnt FROM {$t['interventions']} WHERE $where",
            $params
        ) );
        if ( ! $row || (int) $row->cnt === 0 ) return null;

        $base = $scheduled * $months;
        return round( max( 0, $base - (float) $row->dt ) / (int) $row->cnt, 2 );
    }

    // -------------------------------------------------------------------------
    // Criticidad
    // -------------------------------------------------------------------------

    /**
     * Una máquina es crítica si en el mes actual:
     *   – Disponibilidad < 70 %, o
     *   – 3 o más averías registradas.
     *
     * @param int $machine_id
     * @return bool
     */
    public static function is_critical( $machine_id ) {
        $m = (int) current_time( 'n' );
        $y = (int) current_time( 'Y' );
        $a = self::availability( $machine_id, $m, $y );
        if ( $a !== null && $a < 70 ) return true;
        return self::averia_count( $machine_id, $m, $y ) >= 3;
    }

    /**
     * Lista de máquinas críticas con sus métricas del mes actual.
     *
     * @return array
     */
    public static function critical_machines() {
        global $wpdb;
        $t = CMH_Core::tables();
        $m = (int) current_time( 'n' );
        $y = (int) current_time( 'Y' );

        $machines = $wpdb->get_results(
            "SELECT m.id, m.machine_code, m.brand, m.model, m.status, m.scheduled_hours_monthly,
                    c.name  AS company_name,
                    ci.name AS city_name
             FROM {$t['machines']} m
             JOIN {$t['companies']} c  ON c.id  = m.company_id
             JOIN {$t['cities']}   ci ON ci.id  = m.city_id
             ORDER BY m.machine_code"
        );

        $critical = [];
        foreach ( $machines as $machine ) {
            $scheduled = (float) $machine->scheduled_hours_monthly;
            if ( $scheduled <= 0 ) continue;

            $dt = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(downtime_hours),0) FROM {$t['interventions']}
                 WHERE machine_id=%d AND affects_availability=1
                   AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d",
                $machine->id, $m, $y
            ) );
            $ac = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$t['interventions']}
                 WHERE machine_id=%d AND affects_availability=1
                   AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d",
                $machine->id, $m, $y
            ) );

            $avail   = min( 100.0, max( 0.0, ( $scheduled - $dt ) / $scheduled * 100 ) );
            $reasons = [];
            if ( $avail < 70 )  $reasons[] = 'disponibilidad ' . self::fmt_pct( $avail );
            if ( $ac >= 3 )     $reasons[] = $ac . ' averías';

            if ( $reasons ) {
                $critical[] = [
                    'id'           => (int)  $machine->id,
                    'machine_code' => $machine->machine_code,
                    'brand_model'  => trim( $machine->brand . ' ' . $machine->model ),
                    'company_city' => $machine->company_name . ' / ' . $machine->city_name,
                    'availability' => $avail,
                    'averia_count' => $ac,
                    'reason'       => implode( ', ', $reasons ),
                ];
            }
        }
        return $critical;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function averia_count( $machine_id, $month, $year ) {
        global $wpdb;
        $t = CMH_Core::tables();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['interventions']}
             WHERE machine_id=%d AND affects_availability=1
               AND MONTH(intervention_date)=%d AND YEAR(intervention_date)=%d",
            $machine_id, $month, $year
        ) );
    }

    /**
     * Determina automáticamente si una intervención afecta la disponibilidad
     * según su tipo de mantenimiento.
     *
     * Regla: Solo las AVERÍAS descuentan disponibilidad.
     *
     * @param string   $maintenance_type
     * @param int|null $manual_value  Valor del formulario (para 'correctivo').
     * @return int  0 o 1.
     */
    public static function auto_affects_availability( $maintenance_type, $manual_value = null ) {
        switch ( $maintenance_type ) {
            case 'averia':
                return 1;
            case 'preventivo':
            case 'evaluacion':
                return 0;
            case 'correctivo':
                return ( $manual_value !== null ) ? (int) $manual_value : 1;
            default:
                return ( $manual_value !== null ) ? (int) $manual_value : 0;
        }
    }

    /** Formatea un porcentaje de disponibilidad para mostrar en UI. */
    public static function fmt_pct( $pct, $decimals = 1 ) {
        if ( $pct === null ) return 'N/A';
        return number_format( (float) $pct, $decimals, ',', '.' ) . '%';
    }

    /** Formatea un valor MTTR para mostrar en UI. */
    public static function fmt_mttr( $hours ) {
        if ( $hours === null ) return 'N/A';
        return number_format( (float) $hours, 2, ',', '.' ) . ' h';
    }

    /** Nombre corto del mes + año. */
    public static function month_label( $month, $year ) {
        static $names = [ '', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
                               'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic' ];
        return $names[ (int) $month ] . ' ' . $year;
    }

    /**
     * Días hasta el próximo mantenimiento.
     * Retorna entero positivo (días restantes), negativo (días vencido) o null si no hay fecha.
     */
    public static function maintenance_days( $next_date ) {
        if ( ! $next_date ) return null;
        $today = new DateTime( current_time( 'Y-m-d' ) );
        $next  = new DateTime( $next_date );
        $diff  = (int) $today->diff( $next )->days;
        return $next >= $today ? $diff : -$diff;
    }
}
