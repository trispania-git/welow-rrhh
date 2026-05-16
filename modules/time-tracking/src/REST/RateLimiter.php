<?php
/**
 * Rate limiter ligero por usuario, basado en transients (§12).
 *
 * Política: máx. N peticiones por ventana móvil de M segundos por
 * (namespace, user_id). No persiste históricos; sólo cuenta y caduca.
 *
 * @package Welow\RRHH\Modules\TimeTracking\REST
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\REST;

defined( 'ABSPATH' ) || exit;

/**
 * RateLimiter.
 */
final class RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param string $bucket         Slug del endpoint protegido (ej. 'punch').
	 * @param int    $max_requests   Peticiones permitidas en la ventana.
	 * @param int    $window_seconds Ventana en segundos.
	 */
	public function __construct(
		private string $bucket,
		private int $max_requests = 10,
		private int $window_seconds = 60
	) {}

	/**
	 * Intenta consumir una petición.
	 *
	 * @param int $user_id Usuario.
	 * @return bool true si se permite; false si se ha excedido el límite.
	 */
	public function consume( int $user_id ): bool {
		$key   = $this->key_for( $user_id );
		$count = (int) get_transient( $key );
		if ( $count >= $this->max_requests ) {
			return false;
		}
		set_transient( $key, $count + 1, $this->window_seconds );
		return true;
	}

	/**
	 * Resetea el contador (útil en tests / acciones admin).
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	public function reset( int $user_id ): void {
		delete_transient( $this->key_for( $user_id ) );
	}

	/**
	 * Construye la clave del transient.
	 *
	 * @param int $user_id Usuario.
	 * @return string
	 */
	private function key_for( int $user_id ): string {
		return 'welow_rrhh_rl_' . $this->bucket . '_' . $user_id;
	}
}
