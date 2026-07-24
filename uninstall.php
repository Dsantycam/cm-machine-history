<?php
/**
 * CM Machine History — limpieza al desinstalar.
 * Se ejecuta cuando el usuario elimina el plugin desde el panel de WordPress.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
$prefix = $wpdb->prefix . 'cmh_';
foreach ( [ 'tasks', 'assignments', 'logs', 'files', 'interventions', 'machines', 'branches', 'cities', 'companies' ] as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}
delete_option( 'cmh_version' );
delete_option( 'cmh_machine_history_version' );

// v0.9 — eliminar rol de técnico y su capacidad.
remove_role( 'cmh_technician' );
$admin = get_role( 'administrator' );
if ( $admin ) $admin->remove_cap( 'cmh_tech' );
