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
    const OPTION_SYSTEMS = 'cmh_systems';

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
        return self::read_list( 'mtypes' );
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
        return self::read_list( 'pstates' );
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
    // Sistemas / fallas (v2.3.1)
    // =========================================================================

    /**
     * La taxonomía de fallas que estaba fija en el código. Alimenta la gráfica
     * «Averías por sistema» y la traducción de valores de cada formato.
     */
    public static function system_seed() {
        return [
            'frenos'        => [ 'label' => 'Frenos',           'color' => 'danger' ],
            'potencia'      => [ 'label' => 'Potencia',         'color' => 'warn'   ],
            'traccion'      => [ 'label' => 'Tracción',         'color' => 'warn'   ],
            'seguridad'     => [ 'label' => 'Seguridad',        'color' => 'danger' ],
            'encendido'     => [ 'label' => 'Encendido',        'color' => 'blue'   ],
            'refrigeracion' => [ 'label' => 'Refrigeración',    'color' => 'blue'   ],
            'mastil'        => [ 'label' => 'Mástil',           'color' => 'warn'   ],
            'direccion'     => [ 'label' => 'Dirección',        'color' => 'warn'   ],
            'combustible'   => [ 'label' => 'Combustible',      'color' => 'blue'   ],
            'hidraulico'    => [ 'label' => 'Sist. Hidráulico', 'color' => 'blue'   ],
            'electronico'   => [ 'label' => 'Electrónico',      'color' => 'blue'   ],
            'otro'          => [ 'label' => 'Otro',             'color' => 'gray'   ],
        ];
    }

    public static function systems() {
        return self::read_list( 'systems' );
    }

    public static function system_labels() {
        $out = [];
        foreach ( self::systems() as $slug => $cfg ) $out[ $slug ] = $cfg['label'];
        return $out;
    }

    public static function system_label( $slug ) {
        $slug = strtolower( (string) $slug );
        $all  = self::systems();
        return $all[ $slug ]['label'] ?? ( $slug !== '' ? ucfirst( str_replace( '_', ' ', $slug ) ) : '—' );
    }

    public static function system_badge( $slug ) {
        return self::badge( self::system_label( $slug ), self::systems()[ strtolower( (string) $slug ) ]['color'] ?? 'gray' );
    }

    // =========================================================================
    // Plumbing común a las tres listas
    // =========================================================================

    /**
     * Qué distingue a cada lista: dónde se guarda, de dónde sale su siembra, y
     * qué campo propio tiene además del nombre y el color.
     */
    private static function list_config( $which ) {
        switch ( $which ) {
            case 'mtypes':
                return [
                    'option' => self::OPTION_MTYPES,  'seed' => 'mtype_seed',  'extra' => 'affects',
                    'title'  => 'Tipos de mantenimiento',
                    'column' => 'maintenance_type',
                    'head'   => '¿Descuenta disponibilidad?',
                    'ph'     => 'Garantía, Siniestro, Instalación…',
                    'intro'  => 'Cada tipo decide si <strong>descuenta disponibilidad</strong>, que es lo único con consecuencias sobre los indicadores: lo que descuenta se calcula como una avería. Puedes renombrar los de siempre y agregar los tuyos.',
                    'foot'   => 'Ojo: cambiar si un tipo descuenta disponibilidad altera los indicadores ya calculados de todas las máquinas que lo usen.',
                ];
            case 'pstates':
                return [
                    'option' => self::OPTION_PSTATES, 'seed' => 'pstate_seed', 'extra' => 'money',
                    'title'  => 'Estados de pago',
                    'column' => 'payment_status',
                    'head'   => 'Frente al dinero',
                    'ph'     => 'Pendiente de cotización…',
                    'intro'  => 'Son las etapas del proceso de cobro —pendiente de cotización, de orden de compra, de formato…—. El dinero se sigue calculando como costo menos abonado; la etapa solo dice en qué punto va. Marca «Anulado» solo en lo que ya no se piensa cobrar.',
                    'foot'   => 'La clave interna no cambia aunque renombres la etapa, así que no se pierde nada de lo ya guardado.',
                ];
            default:
                return [
                    'option' => self::OPTION_SYSTEMS, 'seed' => 'system_seed', 'extra' => '',
                    'title'  => 'Sistemas y fallas',
                    'column' => 'failure_system',
                    'head'   => '',
                    'ph'     => 'Transmisión, Cadenas, Neumáticos…',
                    'intro'  => 'La lista que alimenta la gráfica «Averías por sistema» y la traducción de valores de cada formato. Agrega los sistemas que manejes; el plugin intenta reconocer solos los que lleguen escritos parecido.',
                    'foot'   => 'Si retiras un sistema, las intervenciones que ya lo usaban lo conservan y se siguen viendo en los reportes.',
                ];
        }
    }

    /** Lectura genérica de cualquiera de las tres listas. */
    private static function read_list( $which ) {
        $c     = self::list_config( $which );
        $saved = get_option( $c['option'], null );
        $seed  = call_user_func( [ __CLASS__, $c['seed'] ] );
        if ( ! is_array( $saved ) || ! $saved ) return $seed;

        $out = [];
        foreach ( $saved as $slug => $cfg ) {
            $slug = self::clean_slug( $slug );
            if ( $slug === '' || ! is_array( $cfg ) ) continue;

            $row = [
                'label' => (string) ( $cfg['label'] ?? ucfirst( $slug ) ),
                'color' => isset( self::COLORS[ $cfg['color'] ?? '' ] ) ? $cfg['color'] : 'gray',
            ];
            if ( $c['extra'] === 'affects' ) {
                $row['affects'] = ! empty( $cfg['affects'] ) ? 1 : 0;
            } elseif ( $c['extra'] === 'money' ) {
                $row['money'] = isset( self::MONEY_MODES[ $cfg['money'] ?? '' ] ) ? $cfg['money'] : 'pending';
            }
            $out[ $slug ] = $row;
        }
        return $out ?: $seed;
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
    /**
     * Guarda una lista tal como llega del formulario de ajustes.
     *
     * Una fila sin nombre se descarta —así se borra una entrada— y una clave
     * repetida se ignora en vez de pisar a la primera. Nunca se guarda una lista
     * vacía: dejaría al plugin sin taxonomía.
     */
    public static function save_list( $which, $rows ) {
        $c    = self::list_config( $which );
        $seed = call_user_func( [ __CLASS__, $c['seed'] ] );
        $out  = [];

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

            $entry = [
                'label' => $label,
                'color' => isset( self::COLORS[ $row['color'] ?? '' ] ) ? $row['color'] : 'gray',
            ];
            if ( $c['extra'] === 'affects' ) {
                $entry['affects'] = ! empty( $row['affects'] ) ? 1 : 0;
            } elseif ( $c['extra'] === 'money' ) {
                $entry['money'] = isset( self::MONEY_MODES[ $row['money'] ?? '' ] ) ? $row['money'] : 'pending';
            }
            $out[ $slug ] = $entry;
        }

        if ( ! $out ) $out = $seed;

        update_option( $c['option'], $out, true );
        return $out;
    }

    /** Cuántas intervenciones usan una clave. Sirve para avisar antes de borrar. */
    public static function usage_counts( $column ) {
        global $wpdb; $t = CMH_Core::tables();
        $allowed = [ 'maintenance_type', 'payment_status', 'failure_system' ];
        if ( ! in_array( $column, $allowed, true ) ) $column = 'maintenance_type';

        $rows = $wpdb->get_results(
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
        foreach ( [ 'mtypes', 'pstates', 'systems' ] as $which ) self::render_list_panel( $which );
    }

    private static function render_list_panel( $which ) {
        $c     = self::list_config( $which );
        $rows  = self::read_list( $which );
        $usage = self::usage_counts( $c['column'] );

        echo '<div class="cmh-panel"><h2>' . esc_html( $c['title'] ) . '</h2>'
            . '<p class="cmh-hint">' . $c['intro'] . '</p>';

        CMH_Admin::form_start( 'cm_save_taxonomy' );
        echo '<input type="hidden" name="which" value="' . esc_attr( $which ) . '">';

        echo '<div class="cmh-table-scroll"><table class="widefat cmh cmh-taxonomy-table"><thead><tr>'
            . '<th>Nombre</th><th style="width:120px">Color</th>'
            . ( $c['head'] ? '<th style="width:230px">' . esc_html( $c['head'] ) . '</th>' : '' )
            . '<th style="width:150px">En uso</th>'
            . '</tr></thead><tbody>';

        $i = 0;
        foreach ( $rows as $slug => $cfg ) self::render_list_row( $which, $i++, $slug, $cfg, $usage[ $slug ] ?? 0 );
        // Filas en blanco de sobra: agregar entradas es justo el punto de esta pantalla.
        for ( $n = 0; $n < 12; $n++ ) self::render_list_row( $which, $i++, '', [], 0 );

        echo '</tbody></table></div>'
            . '<p class="cmh-hint" style="margin-top:10px">Para <strong>quitar</strong> una fila, borra su nombre y guarda. '
            . 'Lo que ya esté registrado con esa clave se sigue viendo. ' . $c['foot'] . '</p>'
            . '<button class="button button-primary">Guardar ' . esc_html( mb_strtolower( $c['title'] ) ) . '</button></form></div>';
    }

    private static function render_list_row( $which, $i, $slug, $cfg, $used ) {
        $c     = self::list_config( $which );
        $name  = 'rows[' . $i . ']';
        $label = $cfg['label'] ?? '';

        echo '<tr>'
            . '<td><input type="hidden" name="' . esc_attr( $name ) . '[slug]" value="' . esc_attr( $slug ) . '">'
            . '<input type="text" name="' . esc_attr( $name ) . '[label]" value="' . esc_attr( $label ) . '" '
            . 'placeholder="' . esc_attr( $c['ph'] ) . '" style="width:100%">'
            . ( $slug ? '<br><code class="cmh-slug">' . esc_html( $slug ) . '</code>' : '' )
            . '</td>'
            . '<td><select name="' . esc_attr( $name ) . '[color]">';
        foreach ( self::color_options() as $ck => $cl )
            echo '<option value="' . esc_attr( $ck ) . '" ' . selected( $cfg['color'] ?? 'gray', $ck, false ) . '>' . esc_html( $cl ) . '</option>';
        echo '</select></td>';

        if ( $c['extra'] === 'affects' ) {
            echo '<td><label class="cmh-inline-check"><input type="checkbox" name="' . esc_attr( $name ) . '[affects]" value="1" '
                . checked( ! empty( $cfg['affects'] ), true, false ) . '> Sí, cuenta como avería</label></td>';
        } elseif ( $c['extra'] === 'money' ) {
            echo '<td><select name="' . esc_attr( $name ) . '[money]">';
            foreach ( self::MONEY_MODES as $mk => $ml )
                echo '<option value="' . esc_attr( $mk ) . '" ' . selected( $cfg['money'] ?? 'pending', $mk, false ) . '>' . esc_html( $ml ) . '</option>';
            echo '</select></td>';
        }

        echo '<td>' . ( $used
            ? '<span class="cmh-badge" style="background:#e7f0fb;color:#1c4d80">' . intval( $used ) . ' registro(s)</span>'
            : '<span class="cmh-muted">—</span>' ) . '</td></tr>';
    }

    public static function save_taxonomy() {
        CMH_Admin::check();
        $which = sanitize_key( $_POST['which'] ?? '' );
        if ( ! in_array( $which, [ 'mtypes', 'pstates', 'systems' ], true ) ) $which = 'mtypes';

        $c     = self::list_config( $which );
        $saved = self::save_list( $which, $_POST['rows'] ?? [] );

        CMH_Admin::redirect_to(
            CMH_Admin::admin_url( CMH_SLUG . '-settings' ),
            sprintf( '%s guardados: %d en la lista.', $c['title'], count( $saved ) )
        );
    }
}
