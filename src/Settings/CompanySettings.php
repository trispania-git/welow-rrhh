<?php
/**
 * Accesores tipados a la opción `welow_rrhh_company_settings` (§4.4).
 *
 * Esta clase es la fuente única de verdad para:
 *   - El esquema por defecto (defaults()).
 *   - Lecturas con merge contra defaults (all() / section()).
 *   - Actualización por sección con sanitización (update_section()).
 *
 * El Activator semilla la opción usando defaults() en la activación; las
 * pantallas admin y el wizard consumen esta misma clase para mantener
 * coherencia.
 *
 * @package Welow\RRHH\Settings
 */

declare( strict_types=1 );

namespace Welow\RRHH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wrapper de welow_rrhh_company_settings.
 */
final class CompanySettings {

	public const OPTION_KEY = 'welow_rrhh_company_settings';

	public const SECTION_COMPANY       = 'company';
	public const SECTION_CALENDAR      = 'calendar';
	public const SECTION_VACATIONS     = 'vacations';
	public const SECTION_TIME_TRACKING = 'time_tracking';
	public const SECTION_NOTIFICATIONS = 'notifications';

	/**
	 * Esquema por defecto (replica §4.4).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			self::SECTION_COMPANY       => array(
				'name'               => '',
				'cif'                => '',
				'address'            => '',
				'logo_attachment_id' => null,
			),
			self::SECTION_CALENDAR      => array(
				'timezone'          => 'Europe/Madrid',
				'first_day_of_week' => 1,
				'ccaa'              => 'ES-MD',
			),
			self::SECTION_VACATIONS     => array(
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
			self::SECTION_TIME_TRACKING => array(
				'require_geo'                 => false,
				'require_ip_allowlist'        => false,
				'ip_allowlist'                => array(),
				'geo_radius_meters'           => 200,
				'office_locations'            => array(),
				'auto_close_month_day'        => 5,
				'max_daily_hours_warning'     => 10,
				'mandatory_break_after_hours' => 6,
			),
			self::SECTION_NOTIFICATIONS => array(
				'email_from_name'    => '',
				'email_from_address' => '',
			),
		);
	}

	/**
	 * Devuelve TODOS los settings (mergeado contra defaults para tolerar
	 * opciones legacy a las que les falta alguna clave nueva).
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::deep_merge_with_defaults( self::defaults(), $stored );
	}

	/**
	 * Devuelve una sección con sus defaults aplicados.
	 *
	 * @param string $section Slug de sección.
	 * @return array<string, mixed>
	 */
	public function section( string $section ): array {
		$all = $this->all();
		return is_array( $all[ $section ] ?? null ) ? $all[ $section ] : array();
	}

	/**
	 * Actualiza una sección concreta (las otras secciones se conservan).
	 *
	 * @param string               $section Slug de sección.
	 * @param array<string, mixed> $values  Nuevos valores.
	 * @return bool true si la opción fue modificada.
	 */
	public function update_section( string $section, array $values ): bool {
		$all             = $this->all();
		$all[ $section ] = $values;
		return update_option( self::OPTION_KEY, $all );
	}

	/**
	 * Merge recursivo: para cada clave del default, si el almacenado no la
	 * tiene se conserva la del default; si la tiene y ambas son arrays
	 * asociativos, se hace merge recursivo; si no, se usa la almacenada.
	 *
	 * Listas indexadas (approval_flow, ip_allowlist, office_locations) NO
	 * se mergean recursivamente — se reemplazan tal cual están almacenadas.
	 *
	 * @param array<mixed> $defaults Defaults.
	 * @param array<mixed> $stored   Datos almacenados.
	 * @return array<mixed>
	 */
	private static function deep_merge_with_defaults( array $defaults, array $stored ): array {
		$out = $defaults;
		foreach ( $stored as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) && self::is_assoc( $defaults[ $key ] ) ) {
				$out[ $key ] = self::deep_merge_with_defaults( $defaults[ $key ], $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * ¿Es un array asociativo?
	 *
	 * @param array<mixed> $arr Array.
	 * @return bool
	 */
	private static function is_assoc( array $arr ): bool {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
