<?php
/**
 * Contenedor de servicios ligero para Welow RRHH.
 *
 * Inyección manual (no PSR-11 completo). Soporta factorías perezosas (closures)
 * o instancias compartidas pre-resueltas. Las factorías se resuelven la primera
 * vez que se solicita el servicio y el resultado se cachea como singleton.
 *
 * @package Welow\RRHH
 */

declare( strict_types=1 );

namespace Welow\RRHH;

defined( 'ABSPATH' ) || exit;

/**
 * Contenedor de servicios.
 */
class Container {

	/**
	 * Mapa id → factoría (callable) o instancia ya resuelta.
	 *
	 * @var array<string, mixed>
	 */
	private array $bindings = array();

	/**
	 * Instancias compartidas resueltas a partir de factorías.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = array();

	/**
	 * Registra un servicio en el contenedor.
	 *
	 * Si $concrete es un callable, se invoca perezosamente la primera vez que
	 * se solicite el servicio (pasando el contenedor como argumento). En caso
	 * contrario se considera una instancia ya resuelta y se almacena tal cual.
	 *
	 * @param string $id       Identificador del servicio.
	 * @param mixed  $concrete Factoría (callable) o instancia ya resuelta.
	 * @return void
	 */
	public function set( string $id, $concrete ): void {
		$this->bindings[ $id ] = $concrete;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Indica si el contenedor tiene registrado un servicio con ese identificador.
	 *
	 * @param string $id Identificador del servicio.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return array_key_exists( $id, $this->bindings );
	}

	/**
	 * Recupera (y si es necesario resuelve) un servicio.
	 *
	 * @param string $id Identificador del servicio.
	 * @return mixed
	 *
	 * @throws \RuntimeException Si el servicio no está registrado.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! array_key_exists( $id, $this->bindings ) ) {
			throw new \RuntimeException(
				sprintf( 'Servicio no registrado en el contenedor: "%s".', esc_html( $id ) )
			);
		}

		$concrete = $this->bindings[ $id ];

		if ( is_callable( $concrete ) ) {
			$instance = $concrete( $this );
		} else {
			$instance = $concrete;
		}

		$this->resolved[ $id ] = $instance;

		return $instance;
	}
}
