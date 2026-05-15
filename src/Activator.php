<?php
/**
 * Hook de activación del plugin Welow RRHH.
 *
 * Crea las tablas Core, registra roles y capabilities y semilla las opciones
 * base del plugin (idempotente — seguro de re-ejecutar).
 *
 * @package Welow\RRHH
 */

declare( strict_types=1 );

namespace Welow\RRHH;

use Welow\RRHH\Database\Schema;
use Welow\RRHH\Frontend\Frontend;
use Welow\RRHH\Roles\Capabilities;
use Welow\RRHH\Settings\CompanySettings;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la activación del plugin.
 */
final class Activator {

	/**
	 * Punto de entrada del hook register_activation_hook().
	 *
	 * No comprobamos current_user_can() aquí: register_activation_hook ya
	 * garantiza que el callback sólo se invoca cuando WP activa el plugin
	 * (no es invocable externamente). Añadir el check rompe escenarios como
	 * wp-cli sin --user, WP-Cron y tests de integración.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::install();
		Capabilities::install();
		self::seed_options();
		Frontend::ensure_dashboard_page();

		flush_rewrite_rules( false );
	}

	/**
	 * Semilla las opciones base con valores conservadores.
	 *
	 * - Usa add_option() para inicialización idempotente (no-op si existe).
	 * - update_option() solo para `welow_rrhh_version`, que sí queremos rolar
	 *   en cada activación tras un upgrade del plugin.
	 *
	 * @return void
	 */
	private static function seed_options(): void {
		update_option( 'welow_rrhh_version', WELOW_RRHH_VERSION );

		add_option( 'welow_rrhh_active_modules', array(), '', 'yes' );
		add_option( 'welow_rrhh_module_versions', array(), '', 'yes' );
		add_option(
			'welow_rrhh_setup_progress',
			array(
				'completed' => false,
				'step'      => 1,
			),
			'',
			'yes'
		);
		add_option( CompanySettings::OPTION_KEY, CompanySettings::defaults(), '', 'yes' );
		add_option( 'welow_rrhh_remove_data_on_uninstall', false, '', 'no' );

		// Marca para redirigir al wizard en el siguiente request admin (§6.1).
		// El transient se autoborra en WizardScreen::maybe_redirect_after_activation()
		// tras la primera lectura. TTL corto para no atrapar al admin más adelante.
		set_transient( 'welow_rrhh_activation_redirect', '1', 5 * MINUTE_IN_SECONDS );
	}
}
