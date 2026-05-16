<?php
/**
 * Registrador central de controladores REST del plugin.
 *
 * Engancha en `rest_api_init` y llama a `register_routes()` de cada
 * controller inyectado.
 *
 * @package Welow\RRHH\REST
 */

declare( strict_types=1 );

namespace Welow\RRHH\REST;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes registrar.
 */
final class RestRoutes {

	/**
	 * Controladores a registrar.
	 *
	 * @var AbstractController[]
	 */
	private array $controllers;

	/**
	 * Constructor.
	 *
	 * @param AbstractController[] $controllers Controladores.
	 */
	public function __construct( array $controllers ) {
		$this->controllers = $controllers;
	}

	/**
	 * Engancha rest_api_init.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Registra todos los controladores.
	 *
	 * Se filtra la lista vía `welow_rrhh/rest/controllers` para que los
	 * módulos puedan añadir los suyos.
	 *
	 * @return void
	 */
	public function register(): void {
		/**
		 * Permite añadir / quitar controllers REST.
		 *
		 * @since 0.1.0
		 *
		 * @param AbstractController[] $controllers Controladores.
		 */
		$controllers = apply_filters( 'welow_rrhh/rest/controllers', $this->controllers );

		foreach ( (array) $controllers as $controller ) {
			if ( $controller instanceof AbstractController ) {
				$controller->register_routes();
			}
		}
	}
}
