<?php
/**
 * Rate limiter ligero por (bucket, user_id), basado en transients (§12).
 *
 * Compartido por todos los controllers REST del plugin para imponer
 * límites por usuario/endpoint sin requerir backend externo (Redis,
 * Memcached). La ventana es de tipo "fixed window" — sencilla y
 * suficiente para defensa básica anti-flood.
 *
 * @package Welow\RRHH\Support
 */

declare( strict_types=1 );

namespace Welow\RRHH\Support;

defined( 'ABSPATH' ) || exit;

/**
 * RateLimiter.
 */
final class RateLimiter {

	/**
	 * Slug del endpoint protegido (ej. 'punch', 'vacation_request').
	 *
	 * @var string
	 */
	private string $bucket;

	/**
	 * Peticiones permitidas en la ventana.
	 *
	 * @var int
	 */
	private int $max_requests;

	/**
	 * Ventana en segundos.
	 *
	 * @var int
	 */
	private int $window_seconds;

	/**
	 * Constructor.
	 *
	 * @param string $bucket         Slug del endpoint protegido.
	 * @param int    $max_requests   Peticiones permitidas en la ventana.
	 * @param int    $window_seconds Ventana en segundos.
	 */
	public function __construct( string $bucket, int $max_requests = 10, int $window_seconds = 60 ) {
		$this->bucket         = $bucket;
		$this->max_requests   = $max_requests;
		$this->window_seconds = $window_seconds;
	}

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
	 * Resetea el contador (útil en tests o acciones admin).
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
