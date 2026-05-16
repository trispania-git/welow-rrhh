<?php
/**
 * Helpers de formateo de tiempos para el módulo Fichajes.
 *
 * @package Welow\RRHH\Modules\TimeTracking\Support
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Support;

defined( 'ABSPATH' ) || exit;

/**
 * TimeFormat.
 */
final class TimeFormat {

	/**
	 * Convierte segundos a "HHh MMm" (con minutos siempre a 2 dígitos).
	 *
	 * @param int $seconds Segundos.
	 * @return string
	 */
	public static function duration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '0h 00m';
		}
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		return sprintf( '%dh %02dm', $h, $m );
	}
}
