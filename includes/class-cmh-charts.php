<?php
/**
 * CMH_Charts — v2.0 Librería de gráficas SVG generadas en PHP.
 *
 * Sin librerías externas ni CDN (regla R4): cada gráfica es un <svg> con viewBox
 * que escala al ancho del contenedor, tooltips nativos vía <title> y colores
 * alineados con la paleta de la UI del plugin.
 *
 * Antes de la v2.0 las dos únicas gráficas del plugin vivían embebidas en
 * CMH_Reports. Aquí quedan como primitivas reutilizables para que el dashboard,
 * la hoja de vida, los reportes y el portal del cliente dibujen lo mismo.
 *
 * Primitivas:
 *   bars()   — barras verticales, una serie, color por punto (disponibilidad)
 *   groups() — barras verticales agrupadas, N series (preventivo vs correctivo)
 *   line()   — línea con área, una o varias series (costos, tendencias)
 *   hbars()  — barras horizontales (averías por sistema, comparativas)
 *   donut()  — anillo de composición (mezcla de mantenimiento)
 *   legend() — leyenda compartida por las anteriores
 *
 * Convención de datos: un punto con `value === null` es un hueco real (sin base
 * de cálculo) y se dibuja como «N/A», nunca como un 0 engañoso.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Charts {

    /** Paleta base — los mismos tonos que usa el resto de la UI. */
    const BLUE   = '#2271b1';
    const GREEN  = '#00a32a';
    const AMBER  = '#dba617';
    const RED    = '#d63638';
    const GRAY   = '#8c8f94';
    const PURPLE = '#7a4bb5';
    const TEAL   = '#0f766e';

    /** Serie de colores para dimensiones sin color propio. */
    public static function palette( $i ) {
        static $p = [ self::BLUE, self::GREEN, self::AMBER, self::PURPLE, self::TEAL, self::RED, self::GRAY ];
        return $p[ $i % count( $p ) ];
    }

    /** Color de disponibilidad según umbral (≥90 ok, ≥70 alerta, resto crítico). */
    public static function avail_color( $pct ) {
        if ( $pct === null ) return '#c3c4c7';
        if ( $pct >= 90 ) return self::GREEN;
        if ( $pct >= 70 ) return self::AMBER;
        return self::RED;
    }

    // =========================================================================
    // Helpers de escala y formato
    // =========================================================================

    /**
     * Tope «redondo» inmediatamente superior a $v, para que el eje no quede en
     * 137.482 sino en 150. Devuelve al menos 1.
     */
    public static function nice_max( $v ) {
        $v = (float) $v;
        if ( $v <= 0 ) return 1.0;
        $mag = pow( 10, floor( log10( $v ) ) );
        foreach ( [ 1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10 ] as $m ) {
            if ( $m * $mag >= $v ) return $m * $mag;
        }
        return 10 * $mag;
    }

    /** Número corto para ejes y etiquetas: 1.250.000 → 1,3 M. */
    public static function short_num( $v ) {
        $v   = (float) $v;
        $abs = abs( $v );
        if ( $abs >= 1000000 ) return number_format( $v / 1000000, $abs >= 10000000 ? 0 : 1, ',', '.' ) . ' M';
        if ( $abs >= 1000 )    return number_format( $v / 1000,    $abs >= 10000    ? 0 : 1, ',', '.' ) . ' k';
        if ( $abs >= 10 || $v == (int) $v ) return number_format( $v, 0, ',', '.' );
        return number_format( $v, 1, ',', '.' );
    }

    /** Coordenada segura para SVG (evita notación científica y comas locales). */
    private static function n( $v ) {
        $s = rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' );
        return $s === '' || $s === '-' ? '0' : $s;
    }

    /** Etiqueta de mes en dos líneas («Ene 2026» → Ene / 2026) para que quepa sin rotar. */
    private static function two_line_label( $label, $x, $y, $size = 11 ) {
        $parts = explode( ' ', (string) $label );
        $out   = '<text x="' . self::n( $x ) . '" y="' . self::n( $y ) . '" text-anchor="middle" font-size="' . $size . '" fill="#3c434a">'
               . esc_html( self::truncate( $parts[0], 10 ) ) . '</text>';
        if ( isset( $parts[1] ) ) {
            $out .= '<text x="' . self::n( $x ) . '" y="' . self::n( $y + 13 ) . '" text-anchor="middle" font-size="' . ( $size - 1 ) . '" fill="#8c8f94">'
                  . esc_html( $parts[1] ) . '</text>';
        }
        return $out;
    }

    /** Apertura estándar del SVG. */
    private static function open( $w, $h, $label, $max_h = 320 ) {
        return '<svg viewBox="0 0 ' . self::n( $w ) . ' ' . self::n( $h ) . '" '
             . 'style="width:100%;height:auto;max-height:' . intval( $max_h ) . 'px" '
             . 'role="img" aria-label="' . esc_attr( $label ) . '">';
    }

    /** Mensaje uniforme cuando un bloque no tiene datos que dibujar. */
    public static function empty_note( $msg ) {
        return '<p style="margin:0;color:#646970;font-size:13px">' . esc_html( $msg ) . '</p>';
    }

    /**
     * Rejilla horizontal + etiquetas del eje Y.
     *
     * @param array    $guides Valores donde dibujar línea.
     * @param callable $y      Valor → coordenada Y.
     * @param callable $fmt    Valor → etiqueta.
     * @param array    $dashed Valores que van punteados (umbrales).
     */
    private static function grid( $guides, $y, $fmt, $padL, $w, $padR, $dashed = [] ) {
        $out = '';
        foreach ( $guides as $g ) {
            $gy    = call_user_func( $y, $g );
            $is_th = in_array( $g, $dashed, true );
            $out .= '<line x1="' . self::n( $padL ) . '" y1="' . self::n( $gy ) . '" x2="' . self::n( $w - $padR ) . '" y2="' . self::n( $gy ) . '" '
                  . 'stroke="' . ( $is_th ? '#dcdcde' : '#f0f0f1' ) . '"' . ( $is_th ? ' stroke-dasharray="4 3"' : '' ) . '/>'
                  . '<text x="' . self::n( $padL - 8 ) . '" y="' . self::n( $gy + 4 ) . '" text-anchor="end" font-size="11" fill="#646970">'
                  . esc_html( call_user_func( $fmt, $g ) ) . '</text>';
        }
        return $out;
    }

    // =========================================================================
    // Leyenda
    // =========================================================================

    /**
     * @param array $items [ ['label'=>'Preventivos','color'=>'#00a32a'], ... ]
     */
    public static function legend( $items ) {
        if ( ! $items ) return '';
        $out = '<div class="cmh-chart-legend">';
        foreach ( $items as $it ) {
            $out .= '<span><i style="background:' . esc_attr( $it['color'] ) . '"></i>' . esc_html( $it['label'] ) . '</span>';
        }
        return $out . '</div>';
    }

    // =========================================================================
    // Barras verticales — una serie
    // =========================================================================

    /**
     * @param array $points [ ['label'=>'Ene 2026','value'=>92.4|null,'color'=>'#..','tip'=>'...'] ]
     * @param array $opts   pct(bool) · max · height · slot · barw · value_fmt · axis_fmt · color · label
     */
    public static function bars( $points, $opts = [] ) {
        if ( ! $points ) return '';

        $o = array_merge( [
            'pct'         => false,
            'max'         => null,
            'height'      => 200,
            'slot'        => 58,
            'barw'        => 32,
            'color'       => self::BLUE,
            'label'       => 'Gráfica de barras',
            'value_fmt'   => null,
            'axis_fmt'    => null,
            'padL'        => 48,
            'show_values' => true,
        ], $opts );

        $n     = count( $points );
        $padL  = $o['padL']; $padR = 14; $padT = 16; $padB = 40;
        $plotH = $o['height'];
        $slot  = $o['slot'];
        $w     = $padL + $padR + $n * $slot;
        $h     = $padT + $plotH + $padB;
        $barW  = min( $o['barw'], $slot - 14 );

        $values = [];
        foreach ( $points as $p ) if ( $p['value'] !== null ) $values[] = (float) $p['value'];

        if ( $o['pct'] ) {
            $max    = 100;
            $guides = [ 0, 50, 70, 90, 100 ];
            $dashed = [ 70, 90 ];
            $afmt   = $o['axis_fmt'] ?: function ( $v ) { return $v . '%'; };
        } else {
            $max    = $o['max'] !== null ? (float) $o['max'] : self::nice_max( $values ? max( $values ) : 0 );
            if ( $max <= 0 ) $max = 1;
            $guides = [ 0, $max / 4, $max / 2, $max * 3 / 4, $max ];
            $dashed = [];
            $afmt   = $o['axis_fmt'] ?: [ __CLASS__, 'short_num' ];
        }

        $y = function ( $v ) use ( $padT, $plotH, $max ) {
            return $padT + $plotH * ( 1 - min( 1, max( 0, $v / $max ) ) );
        };

        $svg  = self::open( $w, $h, $o['label'] );
        $svg .= self::grid( $guides, $y, $afmt, $padL, $w, $padR, $dashed );

        foreach ( array_values( $points ) as $i => $p ) {
            $cx = $padL + $i * $slot + $slot / 2;

            if ( $p['value'] === null ) {
                $svg .= '<text x="' . self::n( $cx ) . '" y="' . self::n( $padT + $plotH - 6 ) . '" text-anchor="middle" font-size="10" fill="#a7aaad">N/A</text>';
            } else {
                $val = (float) $p['value'];
                $by  = $y( $val );
                $bh  = max( $val > 0 ? 2 : 0, $padT + $plotH - $by );
                $col = isset( $p['color'] ) ? $p['color'] : $o['color'];
                $tip = isset( $p['tip'] ) ? $p['tip'] : ( $p['label'] . ' — ' . self::short_num( $val ) );

                $svg .= '<rect x="' . self::n( $cx - $barW / 2 ) . '" y="' . self::n( $by ) . '" width="' . self::n( $barW ) . '" height="' . self::n( $bh ) . '" rx="3" fill="' . esc_attr( $col ) . '">'
                      . '<title>' . esc_html( $tip ) . '</title></rect>';

                if ( $o['show_values'] ) {
                    $lab  = $o['value_fmt'] ? call_user_func( $o['value_fmt'], $val ) : self::short_num( $val );
                    $svg .= '<text x="' . self::n( $cx ) . '" y="' . self::n( $by - 5 ) . '" text-anchor="middle" font-size="10" fill="#3c434a">' . esc_html( $lab ) . '</text>';
                }
            }

            $svg .= self::two_line_label( $p['label'], $cx, $padT + $plotH + 16 );
        }

        $svg .= '<line x1="' . self::n( $padL ) . '" y1="' . self::n( $padT + $plotH ) . '" x2="' . self::n( $w - $padR ) . '" y2="' . self::n( $padT + $plotH ) . '" stroke="#c3c4c7"/>';
        return $svg . '</svg>';
    }

    // =========================================================================
    // Barras verticales agrupadas — N series
    // =========================================================================

    /**
     * @param array $points [ ['label'=>'Ene 2026','values'=>[3,1],'tips'=>['..','..']] ]
     * @param array $series [ ['label'=>'Preventivos','color'=>'#00a32a'], ... ]
     */
    public static function groups( $points, $series, $opts = [] ) {
        if ( ! $points || ! $series ) return '';

        $o = array_merge( [
            'height'   => 190,
            'slot'     => 58,
            'label'    => 'Gráfica comparativa',
            'axis_fmt' => null,
            'max'      => null,
        ], $opts );

        $ns    = count( $series );
        $n     = count( $points );
        $padL  = 48; $padR = 14; $padT = 16; $padB = 40;
        $plotH = $o['height'];
        $slot  = $o['slot'];
        $w     = $padL + $padR + $n * $slot;
        $h     = $padT + $plotH + $padB;
        $gap   = 3;
        $barW  = max( 5, ( min( $slot - 14, 40 ) - $gap * ( $ns - 1 ) ) / $ns );

        $vals = [];
        foreach ( $points as $p ) foreach ( $p['values'] as $v ) $vals[] = (float) $v;
        $max = $o['max'] !== null ? (float) $o['max'] : self::nice_max( $vals ? max( $vals ) : 0 );
        if ( $max <= 0 ) $max = 1;

        $y = function ( $v ) use ( $padT, $plotH, $max ) {
            return $padT + $plotH * ( 1 - min( 1, max( 0, $v / $max ) ) );
        };
        $afmt = $o['axis_fmt'] ?: [ __CLASS__, 'short_num' ];

        $svg  = self::open( $w, $h, $o['label'] );
        $svg .= self::grid( [ 0, $max / 2, $max ], $y, $afmt, $padL, $w, $padR );

        foreach ( array_values( $points ) as $i => $p ) {
            $cx    = $padL + $i * $slot + $slot / 2;
            $total = $barW * $ns + $gap * ( $ns - 1 );
            $x0    = $cx - $total / 2;

            foreach ( array_values( $series ) as $s => $def ) {
                $v   = isset( $p['values'][ $s ] ) ? (float) $p['values'][ $s ] : 0;
                $by  = $y( $v );
                $bh  = max( $v > 0 ? 2 : 0, $padT + $plotH - $by );
                $bx  = $x0 + $s * ( $barW + $gap );
                $tip = isset( $p['tips'][ $s ] ) ? $p['tips'][ $s ] : ( $p['label'] . ' — ' . $def['label'] . ': ' . self::short_num( $v ) );

                $svg .= '<rect x="' . self::n( $bx ) . '" y="' . self::n( $by ) . '" width="' . self::n( $barW ) . '" height="' . self::n( $bh ) . '" rx="2" fill="' . esc_attr( $def['color'] ) . '">'
                      . '<title>' . esc_html( $tip ) . '</title></rect>';
            }

            $svg .= self::two_line_label( $p['label'], $cx, $padT + $plotH + 16 );
        }

        $svg .= '<line x1="' . self::n( $padL ) . '" y1="' . self::n( $padT + $plotH ) . '" x2="' . self::n( $w - $padR ) . '" y2="' . self::n( $padT + $plotH ) . '" stroke="#c3c4c7"/>';
        return $svg . '</svg>';
    }

    // =========================================================================
    // Línea con área — tendencias continuas
    // =========================================================================

    /**
     * @param array $points [ ['label'=>'Ene 2026','values'=>[120000, 80000],'tips'=>[...]] ]
     * @param array $series [ ['label'=>'Costo','color'=>'#2271b1','fill'=>true], ... ]
     */
    public static function line( $points, $series, $opts = [] ) {
        if ( ! $points || ! $series ) return '';

        $o = array_merge( [
            'height'   => 190,
            'slot'     => 58,
            'label'    => 'Gráfica de tendencia',
            'axis_fmt' => null,
            'max'      => null,
        ], $opts );

        $n     = count( $points );
        $padL  = 56; $padR = 16; $padT = 16; $padB = 40;
        $plotH = $o['height'];
        $slot  = $o['slot'];
        $w     = $padL + $padR + max( 1, $n ) * $slot;
        $h     = $padT + $plotH + $padB;

        $vals = [];
        foreach ( $points as $p ) foreach ( $p['values'] as $v ) if ( $v !== null ) $vals[] = (float) $v;
        $max = $o['max'] !== null ? (float) $o['max'] : self::nice_max( $vals ? max( $vals ) : 0 );
        if ( $max <= 0 ) $max = 1;

        $y = function ( $v ) use ( $padT, $plotH, $max ) {
            return $padT + $plotH * ( 1 - min( 1, max( 0, $v / $max ) ) );
        };
        $x    = function ( $i ) use ( $padL, $slot ) { return $padL + $i * $slot + $slot / 2; };
        $afmt = $o['axis_fmt'] ?: [ __CLASS__, 'short_num' ];

        $svg  = self::open( $w, $h, $o['label'] );
        $svg .= self::grid( [ 0, $max / 4, $max / 2, $max * 3 / 4, $max ], $y, $afmt, $padL, $w, $padR );

        $points = array_values( $points );

        foreach ( array_values( $series ) as $s => $def ) {
            $pts = [];
            foreach ( $points as $i => $p ) {
                $v = isset( $p['values'][ $s ] ) ? $p['values'][ $s ] : null;
                if ( $v === null ) continue;
                $pts[] = [ $x( $i ), $y( (float) $v ), $i, (float) $v ];
            }
            if ( ! $pts ) continue;

            $poly = [];
            foreach ( $pts as $pt ) $poly[] = self::n( $pt[0] ) . ',' . self::n( $pt[1] );

            if ( ! empty( $def['fill'] ) && count( $pts ) > 1 ) {
                $area = self::n( $pts[0][0] ) . ',' . self::n( $padT + $plotH ) . ' '
                      . implode( ' ', $poly ) . ' '
                      . self::n( $pts[ count( $pts ) - 1 ][0] ) . ',' . self::n( $padT + $plotH );
                $svg .= '<polygon points="' . $area . '" fill="' . esc_attr( $def['color'] ) . '" opacity="0.10"/>';
            }

            $svg .= '<polyline points="' . implode( ' ', $poly ) . '" fill="none" stroke="' . esc_attr( $def['color'] ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

            foreach ( $pts as $pt ) {
                $tip = isset( $points[ $pt[2] ]['tips'][ $s ] )
                    ? $points[ $pt[2] ]['tips'][ $s ]
                    : ( $points[ $pt[2] ]['label'] . ' — ' . $def['label'] . ': ' . self::short_num( $pt[3] ) );
                $svg .= '<circle cx="' . self::n( $pt[0] ) . '" cy="' . self::n( $pt[1] ) . '" r="3.5" fill="#fff" stroke="' . esc_attr( $def['color'] ) . '" stroke-width="2">'
                      . '<title>' . esc_html( $tip ) . '</title></circle>';
            }
        }

        foreach ( $points as $i => $p ) {
            $svg .= self::two_line_label( $p['label'], $x( $i ), $padT + $plotH + 16 );
        }

        $svg .= '<line x1="' . self::n( $padL ) . '" y1="' . self::n( $padT + $plotH ) . '" x2="' . self::n( $w - $padR ) . '" y2="' . self::n( $padT + $plotH ) . '" stroke="#c3c4c7"/>';
        return $svg . '</svg>';
    }

    // =========================================================================
    // Barras horizontales — rankings y comparativas
    // =========================================================================

    /**
     * @param array $rows [ ['label'=>'Frenos','value'=>7,'color'=>'#..','tip'=>'..','note'=>'7'] ]
     */
    public static function hbars( $rows, $opts = [] ) {
        if ( ! $rows ) return '';

        $o = array_merge( [
            'width' => 720,
            'rowh'  => 26,
            'padL'  => 150,
            'padR'  => 70,
            'color' => self::BLUE,
            'label' => 'Gráfica comparativa',
            'max'   => null,
            'limit' => 15,
        ], $opts );

        $rows = array_slice( array_values( $rows ), 0, $o['limit'] );
        $vals = [];
        foreach ( $rows as $r ) if ( $r['value'] !== null ) $vals[] = (float) $r['value'];
        $max = $o['max'] !== null ? (float) $o['max'] : ( $vals ? max( $vals ) : 0 );
        if ( $max <= 0 ) $max = 1;

        $padT   = 8;
        $w      = $o['width'];
        $h      = $padT * 2 + count( $rows ) * $o['rowh'];
        $barMax = $w - $o['padL'] - $o['padR'];

        $svg = self::open( $w, $h, $o['label'], 9999 );

        foreach ( $rows as $i => $r ) {
            $ty  = $padT + $i * $o['rowh'] + $o['rowh'] / 2;
            $val = $r['value'];
            $col = isset( $r['color'] ) ? $r['color'] : $o['color'];

            $svg .= '<text x="' . self::n( $o['padL'] - 10 ) . '" y="' . self::n( $ty + 4 ) . '" text-anchor="end" font-size="12" fill="#3c434a">'
                  . esc_html( self::truncate( $r['label'], 24 ) ) . '</text>';

            if ( $val === null ) {
                $svg .= '<text x="' . self::n( $o['padL'] + 4 ) . '" y="' . self::n( $ty + 4 ) . '" font-size="11" fill="#a7aaad">N/A</text>';
                continue;
            }

            $bw  = max( 2, $barMax * (float) $val / $max );
            $tip = isset( $r['tip'] ) ? $r['tip'] : ( $r['label'] . ' — ' . self::short_num( $val ) );
            $svg .= '<rect x="' . self::n( $o['padL'] ) . '" y="' . self::n( $ty - 8 ) . '" width="' . self::n( $bw ) . '" height="16" rx="3" fill="' . esc_attr( $col ) . '">'
                  . '<title>' . esc_html( $tip ) . '</title></rect>'
                  . '<text x="' . self::n( $o['padL'] + $bw + 8 ) . '" y="' . self::n( $ty + 4 ) . '" font-size="12" fill="#646970">'
                  . esc_html( isset( $r['note'] ) ? $r['note'] : self::short_num( $val ) ) . '</text>';
        }
        return $svg . '</svg>';
    }

    // =========================================================================
    // Anillo de composición
    // =========================================================================

    /**
     * @param array $slices [ ['label'=>'Preventivos','value'=>12,'color'=>'#00a32a'] ]
     */
    public static function donut( $slices, $opts = [] ) {
        $o = array_merge( [
            'size'   => 180,
            'label'  => 'Composición',
            'center' => '',
            'hint'   => '',
        ], $opts );

        $total = 0;
        foreach ( $slices as $s ) $total += (float) $s['value'];
        if ( $total <= 0 ) return '';

        $size = $o['size'];
        $cx   = $size / 2; $cy = $size / 2;
        $rOut = $size / 2 - 4;
        $rIn  = $rOut * 0.62;

        $svg   = self::open( $size, $size, $o['label'], $size );
        $angle = -M_PI / 2;   // arranca arriba

        foreach ( $slices as $s ) {
            $v = (float) $s['value'];
            if ( $v <= 0 ) continue;
            $frac = $v / $total;
            $end  = $angle + $frac * 2 * M_PI;
            $pct  = number_format( $frac * 100, 1, ',', '.' ) . '%';

            if ( $frac >= 0.999 ) {
                // Un único sector: un arco de 360° no se dibuja, así que va como círculo trazado.
                $svg .= '<circle cx="' . self::n( $cx ) . '" cy="' . self::n( $cy ) . '" r="' . self::n( ( $rOut + $rIn ) / 2 ) . '" '
                      . 'fill="none" stroke="' . esc_attr( $s['color'] ) . '" stroke-width="' . self::n( $rOut - $rIn ) . '">'
                      . '<title>' . esc_html( $s['label'] . ' — ' . self::short_num( $v ) . ' (100%)' ) . '</title></circle>';
            } else {
                $large = $frac > 0.5 ? 1 : 0;
                $p1 = [ $cx + $rOut * cos( $angle ), $cy + $rOut * sin( $angle ) ];
                $p2 = [ $cx + $rOut * cos( $end ),   $cy + $rOut * sin( $end ) ];
                $p3 = [ $cx + $rIn  * cos( $end ),   $cy + $rIn  * sin( $end ) ];
                $p4 = [ $cx + $rIn  * cos( $angle ), $cy + $rIn  * sin( $angle ) ];

                $d = 'M ' . self::n( $p1[0] ) . ' ' . self::n( $p1[1] )
                   . ' A ' . self::n( $rOut ) . ' ' . self::n( $rOut ) . ' 0 ' . $large . ' 1 ' . self::n( $p2[0] ) . ' ' . self::n( $p2[1] )
                   . ' L ' . self::n( $p3[0] ) . ' ' . self::n( $p3[1] )
                   . ' A ' . self::n( $rIn )  . ' ' . self::n( $rIn )  . ' 0 ' . $large . ' 0 ' . self::n( $p4[0] ) . ' ' . self::n( $p4[1] ) . ' Z';

                $svg .= '<path d="' . $d . '" fill="' . esc_attr( $s['color'] ) . '">'
                      . '<title>' . esc_html( $s['label'] . ' — ' . self::short_num( $v ) . ' (' . $pct . ')' ) . '</title></path>';
            }
            $angle = $end;
        }

        if ( $o['center'] !== '' ) {
            $svg .= '<text x="' . self::n( $cx ) . '" y="' . self::n( $cy + ( $o['hint'] !== '' ? 0 : 5 ) ) . '" text-anchor="middle" font-size="20" font-weight="600" fill="#1d2327">'
                  . esc_html( $o['center'] ) . '</text>';
            if ( $o['hint'] !== '' ) {
                $svg .= '<text x="' . self::n( $cx ) . '" y="' . self::n( $cy + 16 ) . '" text-anchor="middle" font-size="10" fill="#646970">' . esc_html( $o['hint'] ) . '</text>';
            }
        }
        return $svg . '</svg>';
    }

    /** Recorta etiquetas largas para que no invadan el área de la barra. */
    public static function truncate( $s, $len ) {
        $s = (string) $s;
        return mb_strlen( $s ) > $len ? mb_substr( $s, 0, $len - 1 ) . '…' : $s;
    }
}
