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
use Welow\RRHH\Roles\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la activación del plugin.
 */
final class Activator {

	/**
	 * Punto de entrada del hook register_activation_hook().
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		Schema::install();
		Capabilities::install();
		self::seed_options();

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
		add_option( 'welow_rrhh_company_settings', self::default_company_settings(), '', 'yes' );
		add_option( 'welow_rrhh_remove_data_on_uninstall', false, '', 'no' );
	}

	/**
	 * Esquema por defecto de `welow_rrhh_company_settings`.
	 *
	 * Refleja la estructura JSON descrita en §4.4 de la especificación.
	 * Las pantallas de ajustes (hito Settings) iterarán sobre esta forma.
	 *
	 * @return array<string, mixed>
	 */
	private static function default_company_settings(): array {
		return array(
			'company'        => array(
				'name'                => '',
				'cif'                 => '',
				'address'             => '',
				'logo_attachment_id'  => null,
			),
			'calendar'       => array(
				'timezone'          => 'Europe/Madrid',
				'first_day_of_week' => 1,
				'ccaa'              => 'ES-MD',
			),
			'vacations'      => array(
				'default_days_per_year'   => 22,
				'computation'             => 'working_days',
				'allow_carry_over'        => true,
				'carry_over_max_days'     => 5,
				'carry_over_deadline'     => '03-31',
				'approval_flow'           => array(
					array(
						'level' => 1,
						'role'  => 'manager_direct',
					),
					array(
						'level' => 2,
						'role'  => 'hr',
					),
				),
				'min_request_notice_days' => 7,
				'max_consecutive_days'    => 30,
			),
			'time_tracking'  => array(
				'require_geo'                  => false,
				'require_ip_allowlist'         => false,
				'ip_allowlist'                 => array(),
				'geo_radius_meters'            => 200,
				'office_locations'             => array(),
				'auto_close_month_day'         => 5,
				'max_daily_hours_warning'      => 10,
				'mandatory_break_after_hours'  => 6,
			),
			'notifications'  => array(
				'email_from_name'    => '',
				'email_from_address' => '',
			),
		);
	}
}
