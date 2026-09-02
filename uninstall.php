<?php
/**
 * CM Machine History — limpieza al desinstalar.
 * Se ejecuta cuando el usuario elimina el plugin desde el panel de WordPress.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
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
foreach ( [ 215, 225, 226 ] as $cmh_form ) {
    delete_transient( 'cmh_form_url_' . $cmh_form );
}
wp_clear_scheduled_hook( 'cmh_daily_maintenance_event' );

// v0.9 — eliminar rol de técnico y su capacidad.
remove_role( 'cmh_technician' );
// v0.10 — eliminar rol de cliente y su capacidad.
remove_role( 'cmh_client' );
$admin = get_role( 'administrator' );
if ( $admin ) {
    $admin->remove_cap( 'cmh_tech' );
    $admin->remove_cap( 'cmh_client' );
}
