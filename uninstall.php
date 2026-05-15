<?php
/**
 * Rutina de desinstalación de Welow RRHH.
 *
 * Por defecto NO borra datos del cliente — los registros de fichaje y
 * auditoría tienen plazos de conservación legales (4 años, §7.6 / §14).
 *
 * Para borrado completo el administrador debe marcar explícitamente la
 * opción `welow_rrhh_remove_data_on_uninstall = true` antes de desinstalar.
 *
 * @package Welow\RRHH
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Salvaguarda por defecto: conservar datos.
if ( true !== (bool) get_option( 'welow_rrhh_remove_data_on_uninstall', false ) ) {
	return;
}

// Si el autoload de Composer está disponible, podemos reutilizar nuestras
// clases para la limpieza. En caso contrario, hacemos limpieza directa con
// las constantes y nombres de tabla codificados aquí — el plugin debe ser
// capaz de desinstalarse incluso con vendor/ ausente.
$welow_uninstall_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $welow_uninstall_autoload ) ) {
	require_once $welow_uninstall_autoload;
}
unset( $welow_uninstall_autoload );

global $wpdb;

// Tablas a eliminar. Si el autoload está disponible usamos la fuente única
// de verdad (Schema::table_basenames()); si no, una copia hardcoded.
$welow_table_basenames = class_exists( \Welow\RRHH\Database\Schema::class )
	? \Welow\RRHH\Database\Schema::table_basenames()
	: array(
		'welow_employees',
		'welow_departments',
		'welow_holidays',
		'welow_notifications',
		'welow_audit_log',
	);

foreach ( $welow_table_basenames as $welow_table_base ) {
	$welow_table = $wpdb->prefix . $welow_table_base;
	// Nombre de tabla controlado (no proviene de input externo); seguro concatenar.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$welow_table}" );
}
unset( $welow_table_basenames, $welow_table_base, $welow_table );

// Roles y capabilities.
if ( class_exists( \Welow\RRHH\Roles\Capabilities::class ) ) {
	\Welow\RRHH\Roles\Capabilities::uninstall();
} else {
	foreach ( array( 'welow_employee', 'welow_manager', 'welow_hr', 'welow_rrhh_admin' ) as $welow_role ) {
		remove_role( $welow_role );
	}
	unset( $welow_role );
	$administrator = get_role( 'administrator' );
	if ( $administrator instanceof WP_Role ) {
		foreach ( array(
			'welow_manage_employees',
			'welow_manage_holidays',
			'welow_manage_plugin',
			'welow_export_data',
			'welow_view_audit_log',
		) as $welow_cap ) {
			$administrator->remove_cap( $welow_cap );
		}
		unset( $welow_cap );
	}
	unset( $administrator );
}

// Opciones del plugin.
foreach ( array(
	'welow_rrhh_version',
	'welow_rrhh_db_version',
	'welow_rrhh_active_modules',
	'welow_rrhh_module_versions',
	'welow_rrhh_setup_progress',
	'welow_rrhh_company_settings',
	'welow_rrhh_remove_data_on_uninstall',
) as $welow_option ) {
	delete_option( $welow_option );
}
unset( $welow_option );

// TODO(welow): si se publica para multisite, replicar borrado de site_options.
// TODO(welow): si en el futuro se crean directorios bajo wp-content/uploads/welow-rrhh,
// limpiarlos también aquí respetando el plazo legal de retención.
