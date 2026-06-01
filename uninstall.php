<?php
/**
 * CM Machine History — limpieza al desinstalar.
 * Se ejecuta cuando el usuario elimina el plugin desde el panel de WordPress.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
$prefix = $wpdb->prefix . 'cmh_';
foreach ( [ 'logs', 'files', 'interventions', 'machines', 'branches', 'cities', 'companies' ] as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}
delete_option( 'cmh_version' );
delete_option( 'cmh_machine_history_version' );
