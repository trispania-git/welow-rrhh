<?php
/**
 * Política de fichaje aplicable a un empleado concreto.
 *
 * Inmutable. Resultado de mergear la política de empresa
 * (welow_rrhh_company_settings.time_tracking) con el override de
 * welow_employees.geo_policy_override según el modo:
 *   - inherit: usa la política de empresa.
 *   - relaxed: sin restricciones (teletrabajadores, comerciales, …).
 *   - custom : valores propios del empleado.
 *
 * @package Welow\RRHH\Modules\TimeTracking\Policy
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * PunchPolicy DTO.
 */
final class PunchPolicy {

	/**
	 * Constructor.
	 *
	 * @param bool                                                            $require_geo            Geolocalización obligatoria.
	 * @param bool                                                            $require_ip_allowlist   IP debe estar en la allowlist.
	 * @param string[]                                                        $ip_allowlist           IPs o CIDRs IPv4 permitidos.
	 * @param array<int, array{name?:string,lat:float,lng:float,radius?:int}> $office_locations Oficinas (con lat/lng/radio).
	 * @param int                                                             $default_radius_meters  Radio por defecto si la oficina no lo trae.
	 */
	public function __construct(
		public readonly bool $require_geo,
		public readonly bool $require_ip_allowlist,
		public readonly array $ip_allowlist,
		public readonly array $office_locations,
		public readonly int $default_radius_meters = 200
	) {}

	/**
	 * Política sin restricciones.
	 *
	 * @return self
	 */
	public static function relaxed(): self {
		return new self( false, false, array(), array(), 200 );
	}
}
