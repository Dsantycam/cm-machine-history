<?php
/**
 * Plugin Name: CM Machine History
 * Plugin URI:  https://santiagocamachomkt.com
 * Description: CMMS para gestión de historial de mantenimiento de maquinaria industrial — montacargas y equipos industriales.
 * Version:     0.7.1
 * Author:      Santiago Camacho
 * Author URI:  https://santiagocamachomkt.com
 * Text Domain: cm-machine-history
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CMH_VERSION', '0.7.0' );
define( 'CMH_SLUG',    'cm-machine-history' );
define( 'CMH_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CMH_URL',     plugin_dir_url( __FILE__ ) );

require_once CMH_DIR . 'includes/class-cmh-core.php';
require_once CMH_DIR . 'includes/class-cmh-metrics.php';
require_once CMH_DIR . 'includes/class-cmh-integration.php';
require_once CMH_DIR . 'includes/class-cmh-admin.php';

register_activation_hook( __FILE__, [ 'CMH_Core', 'activate' ] );
add_action( 'admin_init', [ 'CMH_Core', 'maybe_upgrade' ] );

CMH_Admin::init();
CMH_Integration::init();

// ─── Auto-actualizaciones vía Plugin Update Checker ──────────────────────────
// Requiere la librería en lib/plugin-update-checker/.
// Descarga: https://github.com/YahnisElsts/plugin-update-checker/releases
// El repositorio debe ser público para distribución sin autenticación.
$cmh_puc = CMH_DIR . 'lib/plugin-update-checker/load-v5p5.php';
if ( file_exists( $cmh_puc ) ) {
    require_once $cmh_puc;
    \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Dsantycam/cm-machine-history/',
        __FILE__,
        'cm-machine-history'
    );
}
