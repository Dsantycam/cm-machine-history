<?php
/**
 * CM Machine History — limpieza al desinstalar.
 * Se ejecuta cuando el usuario elimina el plugin desde el panel de WordPress.
 *
 * v2.2 — POR DEFECTO NO BORRA NADA.
 *
 * Hasta la v2.1 este archivo arrasaba con todas las tablas sin preguntar, así que
 * eliminar el plugin —aunque fuera para reinstalarlo, o para quitar una copia
 * duplicada— se llevaba por delante máquinas, intervenciones, tareas y accesos,
 * sin vuelta atrás salvo copia de seguridad. Un botón de la pantalla de Plugins
 * no debería poder hacer eso.
 *
 * Ahora el borrado solo ocurre si el administrador lo pidió explícitamente en
 * «Máquinas → Ajustes». Sin esa marca, los datos sobreviven a la desinstalación y
 * vuelven a aparecer si el plugin se instala de nuevo.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// El evento de cron se limpia siempre: sin plugin no hay quién lo atienda.
wp_clear_scheduled_hook( 'cmh_daily_maintenance_event' );

$cmh_settings = get_option( 'cmh_settings', [] );
$cmh_wipe     = is_array( $cmh_settings ) && ! empty( $cmh_settings['delete_data_on_uninstall'] );

if ( ! $cmh_wipe ) {
    // Se conserva todo: tablas, opciones y roles. Volver a instalar el plugin
    // encuentra los datos intactos.
    return;
}

$prefix = $wpdb->prefix . 'cmh_';
foreach ( [ 'task_time', 'client_cities', 'client_companies', 'tasks', 'assignments', 'logs', 'files', 'interventions', 'machines', 'branches', 'cities', 'companies' ] as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}
delete_option( 'cmh_version' );
delete_option( 'cmh_machine_history_version' );

// v0.11 — ajustes de alertas y job diario de mantenimiento.
delete_option( 'cmh_settings' );
// v1.0.1 — marca de la migración de montos abonados.
delete_option( 'cmh_migrated_paid_amount' );
// v2.0 — configuración de formatos vinculados.
delete_option( 'cmh_forms' );
// v2.0 — páginas de los formatos de Forminator y su caché de autodetección.
delete_option( 'cmh_form_urls' );
// v2.3 — listas configurables de tipos de mantenimiento y estados de pago.
delete_option( 'cmh_mtypes' );
delete_option( 'cmh_pstates' );
foreach ( [ 215, 225, 226 ] as $cmh_form ) {
    delete_transient( 'cmh_form_url_' . $cmh_form );
}

// v0.9 — eliminar rol de técnico y su capacidad.
remove_role( 'cmh_technician' );
// v0.10 — eliminar rol de cliente y su capacidad.
remove_role( 'cmh_client' );
$admin = get_role( 'administrator' );
if ( $admin ) {
    $admin->remove_cap( 'cmh_tech' );
    $admin->remove_cap( 'cmh_client' );
}
