<?php
/**
 * Stubs mínimos para que los tests "unit" puros (sin WordPress cargado)
 * puedan ejecutarse cuando el código bajo prueba toca una función de WP
 * trivial (por ejemplo `wp_timezone()` o `wp_rand()`).
 *
 * Estos stubs sólo se definen si la función real no existe — cuando los
 * tests se ejecutan tras cargar el framework de tests de WP, las
 * implementaciones reales toman precedencia.
 *
 * @package Welow\RRHH\Tests\Unit
 */

declare( strict_types=1 );

// ABSPATH se define en la suite "unit" para que los archivos del plugin con
// el guard `defined( 'ABSPATH' ) || exit;` puedan cargarse sin WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Stub: devuelve UTC. Suficiente para tests deterministas.
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone(): \DateTimeZone {
		return new \DateTimeZone( 'UTC' );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Stub: pasa a mt_rand.
	 *
	 * @param int $min Min.
	 * @param int $max Max.
	 * @return int
	 */
	function wp_rand( int $min = 0, int $max = 0 ): int {
		return mt_rand( $min, $max );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stub muy básico: arr in-memory.
	 *
	 * @param string $k Key.
	 * @return mixed
	 */
	function get_transient( string $k ) {
		return $GLOBALS['__welow_transients'][ $k ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $k       Key.
	 * @param mixed  $v       Value.
	 * @param int    $expires Expires (ignorado).
	 * @return bool
	 */
	function set_transient( string $k, $v, int $expires = 0 ): bool {
		$GLOBALS['__welow_transients'][ $k ] = $v;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $k Key.
	 * @return bool
	 */
	function delete_transient( string $k ): bool {
		unset( $GLOBALS['__welow_transients'][ $k ] );
		return true;
	}
}
