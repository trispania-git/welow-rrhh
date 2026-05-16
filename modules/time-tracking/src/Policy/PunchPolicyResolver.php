<?php
/**
 * Resuelve la PunchPolicy aplicable a un usuario mergeando la política de
 * empresa con el override del empleado (§4.1 / §7.2).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Policy
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Policy;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Settings\CompanySettings;

defined( 'ABSPATH' ) || exit;

/**
 * PunchPolicyResolver.
 */
final class PunchPolicyResolver {

	/**
	 * Settings.
	 *
	 * @var CompanySettings
	 */
	private CompanySettings $settings;

	/**
	 * Repositorio de empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Constructor.
	 *
	 * @param CompanySettings    $settings  Settings.
	 * @param EmployeeRepository $employees Repo empleados.
	 */
	public function __construct( CompanySettings $settings, EmployeeRepository $employees ) {
		$this->settings  = $settings;
		$this->employees = $employees;
	}

	/**
	 * Política aplicable al usuario indicado.
	 *
	 * @param int $user_id WP user id.
	 * @return PunchPolicy
	 */
	public function for_user( int $user_id ): PunchPolicy {
		$company_tt = $this->settings->section( CompanySettings::SECTION_TIME_TRACKING );
		$employee   = $this->employees->find_by_user_id( $user_id );

		$override = null !== $employee ? $employee->geo_policy_override : null;
		$mode     = is_array( $override ) && isset( $override['mode'] ) ? (string) $override['mode'] : 'inherit';

		if ( 'relaxed' === $mode ) {
			return PunchPolicy::relaxed();
		}

		if ( 'custom' === $mode && is_array( $override ) ) {
			return new PunchPolicy(
				(bool) ( $override['require_geo'] ?? false ),
				(bool) ( $override['require_ip_allowlist'] ?? false ),
				self::array_of_strings( $override['ip_allowlist'] ?? array() ),
				self::array_of_offices( $override['office_locations'] ?? array() ),
				(int) ( $override['geo_radius_meters'] ?? ( $company_tt['geo_radius_meters'] ?? 200 ) )
			);
		}

		// inherit.
		return new PunchPolicy(
			(bool) ( $company_tt['require_geo'] ?? false ),
			(bool) ( $company_tt['require_ip_allowlist'] ?? false ),
			self::array_of_strings( $company_tt['ip_allowlist'] ?? array() ),
			self::array_of_offices( $company_tt['office_locations'] ?? array() ),
			(int) ( $company_tt['geo_radius_meters'] ?? 200 )
		);
	}

	/**
	 * Normaliza a string[] (descarta valores no escalares).
	 *
	 * @param mixed $raw Valor.
	 * @return string[]
	 */
	private static function array_of_strings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	/**
	 * Normaliza office_locations a array de structs con lat/lng (float) + opcionalmente name/radius.
	 *
	 * @param mixed $raw Valor crudo.
	 * @return array<int, array{name:string,lat:float,lng:float,radius:int}>
	 */
	private static function array_of_offices( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $office ) {
			if ( ! is_array( $office ) || ! isset( $office['lat'], $office['lng'] ) ) {
				continue;
			}
			$out[] = array(
				'name'   => isset( $office['name'] ) ? (string) $office['name'] : '',
				'lat'    => (float) $office['lat'],
				'lng'    => (float) $office['lng'],
				'radius' => isset( $office['radius'] ) ? (int) $office['radius'] : 200,
			);
		}
		return $out;
	}
}
