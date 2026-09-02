<?php
/**
 * CMH_Taxonomy — v2.3 Fuente única de las dos listas que antes estaban cableadas:
 * los tipos de mantenimiento y los estados de pago.
 *
 * Hasta la v2.2 ambas vivían repetidas en media docena de sitios (etiquetas,
 * colores, badges, filtros del timeline, la regla de disponibilidad, el CSV…).
 * Agregar un tipo obligaba a tocar todos. Ahora salen de aquí y se configuran
 * desde «Máquinas → Ajustes».
 *
 * TIPOS DE MANTENIMIENTO. Cada uno decide si **descuenta disponibilidad**, que
 * es la única propiedad con consecuencias sobre los indicadores: lo que descuenta
 * se comporta como una avería en el cálculo. Los cuatro de siempre se pueden
 * renombrar y reconfigurar (decisión del usuario), pero su clave interna no
 * cambia nunca: es la que está escrita en las intervenciones ya registradas.
 *
 * ESTADOS DE PAGO. Son etapas del proceso de cobro —pendiente de cotización, de
 * orden de compra, de formato…—, no formas de pago. El dinero se sigue calculando
 * igual que siempre, costo menos abonado; el estado solo cuenta en qué punto del
 * proceso está. Tres comportamientos:
 *   · pendiente → el saldo sigue contando en «Por cobrar» (el de casi todos).
 *   · pagado    → al guardar, lo abonado se iguala al costo.
 *   · anulado   → el saldo deja de contar en «Por cobrar», sin tocar lo abonado.
 *
 * Las claves internas nunca se renumeran ni se reutilizan: si se borra un estado
 * que ya está en uso, las intervenciones que lo tenían lo conservan y se muestran
 * con la clave cruda, en vez de quedar mudas.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Taxonomy {

    const OPTION_MTYPES  = 'cmh_mtypes';
    const OPTION_PSTATES = 'cmh_pstates';

    /** Paleta compartida por ambas listas, para no inventar colores sueltos. */
    const COLORS = [
        'ok'     => [ 'label' => 'Verde',    'bg' => '#e6f4ea', 'fg' => '#1a6630' ],
        'warn'   => [ 'label' => 'Ámbar',    'bg' => '#fff3cd', 'fg' => '#7a4f00' ],
        'danger' => [ 'label' => 'Rojo',     'bg' => '#fce8e8', 'fg' => '#d63638' ],
        'blue'   => [ 'label' => 'Azul',     'bg' => '#e7f0fb', 'fg' => '#1c4d80' ],
        'gray'   => [ 'label' => 'Gris',     'bg' => '#f0f0f1', 'fg' => '#3c434a' ],
    ];

    /** Comportamientos posibles de un estado de pago frente al dinero. */
    const MONEY_MODES = [
        'pending' => 'Sigue por cobrar',
        'paid'    => 'Cobrado (iguala lo abonado al costo)',
        'void'    => 'Anulado (deja de contar en Por cobrar)',
    ];

    // =========================================================================
    // Tipos de mantenimiento
    // =========================================================================

    /** Los cuatro de siempre. Es la siembra y también el respaldo. */
    public static function mtype_seed() {
        return [
            'preventivo' => [ 'label' => 'Preventivo', 'affects' => 0, 'color' => 'ok'     ],
            'correctivo' => [ 'label' => 'Correctivo', 'affects' => 0, 'color' => 'warn'   ],
            'averia'     => [ 'label' => 'Avería',     'affects' => 1, 'color' => 'danger' ],
            'evaluacion' => [ 'label' => 'Evaluación', 'affects' => 0, 'color' => 'gray'   ],
        ];
    }

    public static function mtypes() {
        $saved = get_option( self::OPTION_MTYPES, null );
        if ( ! is_array( $saved ) || ! $saved ) return self::mtype_seed();

        $out = [];
        foreach ( $saved as $slug => $cfg ) {
            $slug = self::clean_slug( $slug );
            if ( $slug === '' || ! is_array( $cfg ) ) continue;
            $out[ $slug ] = [
                'label'   => (string) ( $cfg['label'] ?? ucfirst( $slug ) ),
                'affects' => ! empty( $cfg['affects'] ) ? 1 : 0,
                'color'   => isset( self::COLORS[ $cfg['color'] ?? '' ] ) ? $cfg['color'] : 'gray',
            ];
        }
        return $out ?: self::mtype_seed();
    }

    /** [ slug => etiqueta ], que es lo que consumen los desplegables. */
    public static function mtype_labels() {
        $out = [];
        foreach ( self::mtypes() as $slug => $cfg ) $out[ $slug ] = $cfg['label'];
        return $out;
    }

    public static function mtype_label( $slug ) {
        $slug = strtolower( (string) $slug );
        $all  = self::mtypes();
        // Un tipo borrado que aún vive en intervenciones viejas se muestra crudo
        // en vez de desaparecer del informe.
        return $all[ $slug ]['label'] ?? ( $slug !== '' ? ucfirst( $slug ) : '—' );
    }

    /** ¿Este tipo descuenta disponibilidad? */
    public static function mtype_affects( $slug ) {
        $all  = self::mtypes();
        $slug = strtolower( (string) $slug );
        return ! empty( $all[ $slug ]['affects'] );
    }

    /** Claves de los tipos que descuentan disponibilidad. */
    public static function affecting_mtypes() {
        $out = [];
        foreach ( self::mtypes() as $slug => $cfg ) if ( ! empty( $cfg['affects'] ) ) $out[] = $slug;
        return $out;
    }

    public static function mtype_badge( $slug ) {
        return self::badge( self::mtype_label( $slug ), self::mtypes()[ strtolower( (string) $slug ) ]['color'] ?? 'gray' );
    }

    // =========================================================================
    // Estados de pago
    // =========================================================================

    public static function pstate_seed() {
        return [
            'pendiente' => [ 'label' => 'Pendiente', 'money' => 'pending', 'color' => 'danger' ],
            'parcial'   => [ 'label' => 'Parcial',   'money' => 'pending', 'color' => 'warn'   ],
            'pagado'    => [ 'label' => 'Pagado',    'money' => 'paid',    'color' => 'ok'     ],
        ];
    }

    public static function pstates() {
        $saved = get_option( self::OPTION_PSTATES, null );
        if ( ! is_array( $saved ) || ! $saved ) return self::pstate_seed();

        $out = [];
        foreach ( $saved as $slug => $cfg ) {
            $slug = self::clean_slug( $slug );
            if ( $slug === '' || ! is_array( $cfg ) ) continue;
            $out[ $slug ] = [
                'label' => (string) ( $cfg['label'] ?? ucfirst( $slug ) ),
                'money' => isset( self::MONEY_MODES[ $cfg['money'] ?? '' ] ) ? $cfg['money'] : 'pending',
                'color' => isset( self::COLORS[ $cfg['color'] ?? '' ] ) ? $cfg['color'] : 'gray',
            ];
        }
        return $out ?: self::pstate_seed();
    }

    public static function pstate_labels() {
        $out = [];
        foreach ( self::pstates() as $slug => $cfg ) $out[ $slug ] = $cfg['label'];
        return $out;
    }

    public static function pstate_label( $slug ) {
        $slug = strtolower( (string) $slug );
        $all  = self::pstates();
        return $all[ $slug ]['label'] ?? ( $slug !== '' ? ucfirst( $slug ) : '—' );
    }

    /** Comportamiento frente al dinero: 'pending' | 'paid' | 'void'. */
    public static function pstate_money( $slug ) {
        $all = self::pstates();
        return $all[ strtolower( (string) $slug ) ]['money'] ?? 'pending';
    }

    /** Claves de los estados anulados: su saldo no cuenta en «Por cobrar». */
    public static function void_pstates() {
        $out = [];
        foreach ( self::pstates() as $slug => $cfg ) if ( $cfg['money'] === 'void' ) $out[] = $slug;
        return $out;
    }

    /**
     * Fragmento SQL que excluye los estados anulados de una suma de saldo.
     * Devuelve '' si no hay ninguno, para no ensuciar la consulta.
     *
     * @param string $alias Prefijo de la tabla de intervenciones ('i.' o '').
     */
    public static function not_void_sql( $alias = '' ) {
        $void = self::void_pstates();
        if ( ! $void ) return '';
        $list = implode( ',', array_map( function ( $s ) {
            return "'" . esc_sql( $s ) . "'";
        }, $void ) );
        return " AND ( {$alias}payment_status IS NULL OR {$alias}payment_status NOT IN ($list) )";
    }


    /**
     * Suma del saldo por cobrar, excluyendo los estados anulados.
     * Un único sitio para que el KPI de la ficha, el dashboard y los reportes no
     * puedan divergir.
     *
     * @param string $alias Prefijo de la tabla de intervenciones ('i.' o '').
     */
    public static function balance_sum_sql( $alias = '' ) {
        $a = $alias;
        return "COALESCE(SUM(CASE WHEN {$a}cost>{$a}paid_amount" . self::not_void_sql( $a )
            . " THEN {$a}cost-{$a}paid_amount ELSE 0 END),0)";
    }
    public static function pstate_badge( $slug ) {
        return self::badge( self::pstate_label( $slug ), self::pstates()[ strtolower( (string) $slug ) ]['color'] ?? 'gray' );
    }

    // =========================================================================
    // Guardado
    // =========================================================================

    /**
     * Guarda una lista tal como llega del formulario de ajustes.
     *
     * `$extra` nombra el campo propio de cada lista ('affects' o 'money'). Una
     * fila sin nombre se descarta; una clave repetida se ignora en vez de pisar
     * a la primera.
     */
    public static function save_list( $which, $rows ) {
        $is_mtype = ( $which === 'mtypes' );
        $seed     = $is_mtype ? self::mtype_seed() : self::pstate_seed();
        $out      = [];

        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $label = trim( sanitize_text_field( $row['label'] ?? '' ) );
            if ( $label === '' ) continue;

            // La clave viene del formulario para lo ya existente y se deriva del
            // nombre para lo nuevo. Nunca se recalcula para lo existente: es la
            // que está guardada en las intervenciones.
            $slug = self::clean_slug( $row['slug'] ?? '' );
            if ( $slug === '' ) $slug = self::slugify( $label );
            if ( $slug === '' || isset( $out[ $slug ] ) ) continue;

            $color = isset( self::COLORS[ $row['color'] ?? '' ] ) ? $row['color'] : 'gray';

            if ( $is_mtype ) {
                $out[ $slug ] = [ 'label' => $label, 'affects' => ! empty( $row['affects'] ) ? 1 : 0, 'color' => $color ];
            } else {
                $money = isset( self::MONEY_MODES[ $row['money'] ?? '' ] ) ? $row['money'] : 'pending';
                $out[ $slug ] = [ 'label' => $label, 'money' => $money, 'color' => $color ];
            }
        }

        // Nunca se guarda una lista vacía: dejaría el plugin sin taxonomía.
        if ( ! $out ) $out = $seed;

        update_option( $is_mtype ? self::OPTION_MTYPES : self::OPTION_PSTATES, $out, true );
        return $out;
    }

    /** Cuántas intervenciones usan una clave. Sirve para avisar antes de borrar. */
    public static function usage_counts( $column ) {
        global $wpdb; $t = CMH_Core::tables();
        $column = ( $column === 'payment_status' ) ? 'payment_status' : 'maintenance_type';
        $rows   = $wpdb->get_results(
            "SELECT $column AS k, COUNT(*) AS n FROM {$t['interventions']} GROUP BY $column"
        );
        $out = [];
        foreach ( $rows as $r ) $out[ strtolower( (string) $r->k ) ] = (int) $r->n;
        return $out;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public static function badge( $label, $color = 'gray' ) {
        $c = self::COLORS[ $color ] ?? self::COLORS['gray'];
        return '<span class="cmh-badge" style="background:' . $c['bg'] . ';color:' . $c['fg'] . '">'
            . esc_html( $label ) . '</span>';
    }

    public static function color_options() {
        $out = [];
        foreach ( self::COLORS as $k => $c ) $out[ $k ] = $c['label'];
        return $out;
    }

    /** Clave interna: minúsculas, sin acentos, solo letras, números y guion bajo. */
    public static function clean_slug( $v ) {
        $v = strtolower( remove_accents( trim( (string) $v ) ) );
        return preg_replace( '/[^a-z0-9_]/', '', $v );
    }

    /** Clave derivada de un nombre escrito por una persona. */
    public static function slugify( $label ) {
        $v = strtolower( remove_accents( trim( (string) $label ) ) );
        $v = preg_replace( '/[^a-z0-9]+/', '_', $v );
        return trim( substr( $v, 0, 40 ), '_' );
    }
    // =========================================================================
    // Pantalla — se pinta dentro de «Máquinas → Ajustes»
    // =========================================================================

    public static function init() {
        add_action( 'admin_post_cm_save_taxonomy', [ __CLASS__, 'save_taxonomy' ] );
    }

    public static function render_settings_panels() {
        self::render_list_panel( 'mtypes' );
        self::render_list_panel( 'pstates' );
    }

    private static function render_list_panel( $which ) {
        $is_mtype = ( $which === 'mtypes' );
        $rows     = $is_mtype ? self::mtypes() : self::pstates();
        $usage    = self::usage_counts( $is_mtype ? 'maintenance_type' : 'payment_status' );

        $title = $is_mtype ? 'Tipos de mantenimiento' : 'Estados de pago';
        $intro = $is_mtype
            ? 'Cada tipo decide si <strong>descuenta disponibilidad</strong>, que es lo único con consecuencias sobre los indicadores: lo que descuenta se calcula como una avería. Puedes renombrar los de siempre y agregar los tuyos.'
            : 'Son las etapas del proceso de cobro —pendiente de cotización, de orden de compra, de formato…—. El dinero se sigue calculando como costo menos abonado; la etapa solo dice en qué punto va. Marca «Anulado» solo en lo que ya no se piensa cobrar.';

        echo '<div class="cmh-panel"><h2>' . esc_html( $title ) . '</h2>'
            . '<p class="cmh-hint">' . $intro . '</p>';

        CMH_Admin::form_start( 'cm_save_taxonomy' );
        echo '<input type="hidden" name="which" value="' . esc_attr( $which ) . '">';

        echo '<div class="cmh-table-scroll"><table class="widefat cmh cmh-taxonomy-table"><thead><tr>'
            . '<th>Nombre</th>'
            . '<th style="width:120px">Color</th>'
            . '<th style="width:230px">' . ( $is_mtype ? '¿Descuenta disponibilidad?' : 'Frente al dinero' ) . '</th>'
            . '<th style="width:150px">En uso</th>'
            . '</tr></thead><tbody>';

        $i = 0;
        foreach ( $rows as $slug => $cfg ) {
            self::render_list_row( $which, $i++, $slug, $cfg, $usage[ $slug ] ?? 0 );
        }
        // Filas en blanco para agregar. Generosas: agregar tipos es justo el punto.
        for ( $n = 0; $n < 10; $n++ ) {
            self::render_list_row( $which, $i++, '', [], 0 );
        }

        echo '</tbody></table></div>'
            . '<p class="cmh-hint" style="margin-top:10px">Para <strong>quitar</strong> una fila, borra su nombre y guarda. '
            . 'Lo que ya esté registrado con esa clave se sigue viendo, con su nombre interno. '
            . ( $is_mtype
                ? 'Ojo: cambiar si un tipo descuenta disponibilidad altera los indicadores ya calculados de todas las máquinas que lo usen.'
                : 'La clave interna no cambia aunque renombres la etapa, así que no se pierde nada de lo ya guardado.' )
            . '</p>'
            . '<button class="button button-primary">Guardar ' . ( $is_mtype ? 'tipos' : 'estados' ) . '</button></form></div>';
    }

    private static function render_list_row( $which, $i, $slug, $cfg, $used ) {
        $is_mtype = ( $which === 'mtypes' );
        $name     = 'rows[' . $i . ']';
        $label    = $cfg['label'] ?? '';

        echo '<tr>'
            . '<td><input type="hidden" name="' . esc_attr( $name ) . '[slug]" value="' . esc_attr( $slug ) . '">'
            . '<input type="text" name="' . esc_attr( $name ) . '[label]" value="' . esc_attr( $label ) . '" '
            . 'placeholder="' . ( $is_mtype ? 'Garantía, Siniestro, Instalación…' : 'Pendiente de cotización…' ) . '" style="width:100%">'
            . ( $slug ? '<br><code class="cmh-slug">' . esc_html( $slug ) . '</code>' : '' )
            . '</td>'
            . '<td><select name="' . esc_attr( $name ) . '[color]">';
        foreach ( self::color_options() as $ck => $cl )
            echo '<option value="' . esc_attr( $ck ) . '" ' . selected( $cfg['color'] ?? 'gray', $ck, false ) . '>' . esc_html( $cl ) . '</option>';
        echo '</select></td><td>';

        if ( $is_mtype ) {
            echo '<label class="cmh-inline-check"><input type="checkbox" name="' . esc_attr( $name ) . '[affects]" value="1" '
                . checked( ! empty( $cfg['affects'] ), true, false ) . '> Sí, cuenta como avería</label>';
        } else {
            echo '<select name="' . esc_attr( $name ) . '[money]">';
            foreach ( self::MONEY_MODES as $mk => $ml )
                echo '<option value="' . esc_attr( $mk ) . '" ' . selected( $cfg['money'] ?? 'pending', $mk, false ) . '>' . esc_html( $ml ) . '</option>';
            echo '</select>';
        }

        echo '</td><td>' . ( $used
            ? '<span class="cmh-badge" style="background:#e7f0fb;color:#1c4d80">' . intval( $used ) . ' registro(s)</span>'
            : '<span style="color:#a7aaad;font-size:12px">—</span>' ) . '</td></tr>';
    }

    public static function save_taxonomy() {
        CMH_Admin::check();
        $which = ( ( $_POST['which'] ?? '' ) === 'mtypes' ) ? 'mtypes' : 'pstates';
        $saved = self::save_list( $which, $_POST['rows'] ?? [] );

        CMH_Admin::redirect_to(
            CMH_Admin::admin_url( CMH_SLUG . '-settings' ),
            sprintf( '%s guardados: %d en la lista.',
                $which === 'mtypes' ? 'Tipos de mantenimiento' : 'Estados de pago', count( $saved ) )
        );
    }
}
