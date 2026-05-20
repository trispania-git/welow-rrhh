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
$welow_rrhh_uninstall_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $welow_rrhh_uninstall_autoload ) ) {
	require_once $welow_rrhh_uninstall_autoload;
}
unset( $welow_rrhh_uninstall_autoload );

global $wpdb;

// Tablas a eliminar. Si el autoload está disponible usamos la fuente única
// de verdad (Schema::table_basenames()); si no, una copia hardcoded.
$welow_rrhh_table_basenames = class_exists( \Welow\RRHH\Database\Schema::class )
	? \Welow\RRHH\Database\Schema::table_basenames()
	: array(
		'welow_employees',
		'welow_departments',
		'welow_holidays',
		'welow_notifications',
		'welow_audit_log',
	);

// Tablas de los módulos (sólo si su Schema es cargable; si no, hardcoded
// como salvaguarda para que el uninstall funcione sin autoload).
if ( class_exists( \Welow\RRHH\Modules\TimeTracking\Schema\TimeTrackingSchema::class ) ) {
	$welow_rrhh_table_basenames = array_merge(
		$welow_rrhh_table_basenames,
		\Welow\RRHH\Modules\TimeTracking\Schema\TimeTrackingSchema::table_basenames()
	);
} else {
	$welow_rrhh_table_basenames[] = 'welow_time_entries';
}
if ( class_exists( \Welow\RRHH\Modules\Vacations\Schema\VacationsSchema::class ) ) {
	$welow_rrhh_table_basenames = array_merge(
		$welow_rrhh_table_basenames,
		\Welow\RRHH\Modules\Vacations\Schema\VacationsSchema::table_basenames()
	);
} else {
	$welow_rrhh_table_basenames[] = 'welow_vacation_requests';
	$welow_rrhh_table_basenames[] = 'welow_vacation_balances';
}

foreach ( $welow_rrhh_table_basenames as $welow_rrhh_table_base ) {
	$welow_rrhh_table = $wpdb->prefix . $welow_rrhh_table_base;
	// Nombre de tabla controlado (no proviene de input externo); seguro concatenar.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$welow_rrhh_table}" );
}
unset( $welow_rrhh_table_basenames, $welow_rrhh_table_base, $welow_rrhh_table );

// Roles y capabilities (Core + módulos).
if ( class_exists( \Welow\RRHH\Roles\Capabilities::class ) ) {
	\Welow\RRHH\Roles\Capabilities::uninstall();
} else {
	foreach ( array( 'welow_employee', 'welow_manager', 'welow_hr', 'welow_rrhh_admin' ) as $welow_rrhh_role ) {
		remove_role( $welow_rrhh_role );
	}
	unset( $welow_rrhh_role );
	$welow_rrhh_administrator = get_role( 'administrator' );
	if ( $welow_rrhh_administrator instanceof WP_Role ) {
		foreach ( array(
			'welow_manage_employees',
			'welow_manage_holidays',
			'welow_manage_plugin',
			'welow_export_data',
			'welow_view_audit_log',
		) as $welow_rrhh_cap ) {
			$welow_rrhh_administrator->remove_cap( $welow_rrhh_cap );
		}
		unset( $welow_rrhh_cap );
	}
	unset( $welow_rrhh_administrator );
}

// Capabilities introducidas por los módulos.
if ( class_exists( \Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities::class ) ) {
	\Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities::uninstall();
}
if ( class_exists( \Welow\RRHH\Modules\Vacations\VacationsCapabilities::class ) ) {
	\Welow\RRHH\Modules\Vacations\VacationsCapabilities::uninstall();
}

// Opciones del plugin (Core + módulos).
foreach ( array(
	// Core.
	'welow_rrhh_version',
	'welow_rrhh_db_version',
	'welow_rrhh_active_modules',
	'welow_rrhh_module_versions',
	'welow_rrhh_setup_progress',
	'welow_rrhh_company_settings',
	'welow_rrhh_remove_data_on_uninstall',
	// Módulo Fichajes.
	'welow_rrhh_time_tracking_db_version',
	'welow_rrhh_time_tracking_closed_months',
	// Módulo Vacaciones.
	'welow_rrhh_vacations_db_version',
	'welow_rrhh_vacation_years',
) as $welow_rrhh_option ) {
	delete_option( $welow_rrhh_option );
}
unset( $welow_rrhh_option );

// TODO(welow): si se publica para multisite, replicar borrado de site_options.
// TODO(welow): si en el futuro se crean directorios bajo wp-content/uploads/welow-rrhh,
// limpiarlos también aquí respetando el plazo legal de retención.
