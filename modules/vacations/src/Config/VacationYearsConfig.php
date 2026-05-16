<?php
/**
 * Configuración de años de vacaciones (apertura + carry-over).
 *
 * Encapsula la opción `welow_rrhh_vacation_years` que mantiene un array
 * indexado por año (cadena "YYYY"). Permite a HR definir, año a año, si se
 * pueden solicitar vacaciones, cuántos días anuales aplican (override del
 * default empresa) y la política de arrastre al año siguiente con su
 * fecha límite.
 *
 * @package Welow\RRHH\Modules\Vacations\Config
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Config;

use Welow\RRHH\Modules\Vacations\Data\VacationYear;

defined( 'ABSPATH' ) || exit;

/**
 * VacationYearsConfig.
 */
final class VacationYearsConfig {

	public const OPTION_KEY = 'welow_rrhh_vacation_years';

	/**
	 * Devuelve todos los años configurados indexados por año (ordenados desc).
	 *
	 * @return array<int, VacationYear>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$year = VacationYear::from_array( $raw );
			if ( null !== $year ) {
				$out[ $year->year ] = $year;
			}
		}
		krsort( $out );
		return $out;
	}

	/**
	 * Recupera la configuración de un año concreto.
	 *
	 * @param int $year Año.
	 * @return VacationYear|null
	 */
	public function get( int $year ): ?VacationYear {
		$all = $this->all();
		return $all[ $year ] ?? null;
	}

	/**
	 * Indica si el año está abierto a nuevas solicitudes.
	 *
	 * Si no hay configuración explícita, devuelve false: HR debe declarar el
	 * año como abierto antes de que los empleados puedan solicitar.
	 *
	 * @param int $year Año.
	 * @return bool
	 */
	public function is_open( int $year ): bool {
		$cfg = $this->get( $year );
		return null !== $cfg && $cfg->is_open;
	}

	/**
	 * Persiste (crea o actualiza) la configuración de un año.
	 *
	 * @param VacationYear $year_cfg Configuración.
	 * @return bool true si update_option modificó el valor.
	 */
	public function save( VacationYear $year_cfg ): bool {
		$all                    = $this->all();
		$all[ $year_cfg->year ] = $year_cfg;
		$serialized             = array();
		foreach ( $all as $cfg ) {
			$serialized[] = $cfg->to_array();
		}
		return update_option( self::OPTION_KEY, $serialized, false );
	}

	/**
	 * Elimina la configuración de un año.
	 *
	 * @param int $year Año.
	 * @return bool
	 */
	public function remove( int $year ): bool {
		$all = $this->all();
		if ( ! isset( $all[ $year ] ) ) {
			return false;
		}
		unset( $all[ $year ] );
		$serialized = array();
		foreach ( $all as $cfg ) {
			$serialized[] = $cfg->to_array();
		}
		return update_option( self::OPTION_KEY, $serialized, false );
	}
}
