<?php
/**
 * Controlador REST base para los endpoints internos del plugin.
 *
 * Responses uniformes (§10):
 *   { ok: bool, data: ..., error: { code, message } }
 *
 * @package Welow\RRHH\REST
 */

declare( strict_types=1 );

namespace Welow\RRHH\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Controlador REST base.
 */
abstract class AbstractController {

	/**
	 * Namespace REST del plugin (§10).
	 */
	public const NAMESPACE = 'welow-rrhh/v1';

	/**
	 * Registra las rutas del controlador.
	 *
	 * Llamado desde rest_api_init.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Construye una respuesta de éxito uniforme.
	 *
	 * @param mixed $data   Datos.
	 * @param int   $status HTTP status (default 200).
	 * @return \WP_REST_Response
	 */
	protected function ok( $data = null, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'   => true,
				'data' => $data,
			),
			$status
		);
	}

	/**
	 * Construye una respuesta de error uniforme.
	 *
	 * @param string $code    Slug del error.
	 * @param string $message Mensaje legible.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	protected function error( string $code, string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}

	/**
	 * Convierte un WP_Error en respuesta REST.
	 *
	 * @param \WP_Error $err    Error.
	 * @param int       $status HTTP status (default 400).
	 * @return \WP_REST_Response
	 */
	protected function from_wp_error( \WP_Error $err, int $status = 400 ): \WP_REST_Response {
		$raw_code = $err->get_error_code();
		$raw_msg  = $err->get_error_message();
		$code     = ( is_string( $raw_code ) && '' !== $raw_code ) ? $raw_code : 'unknown_error';
		$message  = ( is_string( $raw_msg ) && '' !== $raw_msg ) ? $raw_msg : __( 'Error desconocido.', 'welow-rrhh' );
		return $this->error( $code, $message, $status );
	}

	/**
	 * Permission callback: requiere usuario logueado.
	 *
	 * @return bool
	 */
	public function check_logged_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * Permission callback fábrica: requiere una capability concreta.
	 *
	 * @param string $cap Capability requerida.
	 * @return callable
	 */
	protected function require_cap( string $cap ): callable {
		return static function () use ( $cap ): bool {
			return is_user_logged_in() && current_user_can( $cap );
		};
	}
}
