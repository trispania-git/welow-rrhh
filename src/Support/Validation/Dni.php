<?php
/**
 * Validación y normalización de DNI / NIE españoles.
 *
 * Soporta:
 *   - DNI: 8 dígitos + letra de control (módulo 23).
 *   - NIE: prefijo X/Y/Z + 7 dígitos + letra (X→0, Y→1, Z→2).
 *
 * @package Welow\RRHH\Support\Validation
 */

declare( strict_types=1 );

namespace Welow\RRHH\Support\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Helper de validación de DNI/NIE.
 */
final class Dni {

	/**
	 * Letra de control en función del módulo 23 del número.
	 */
	private const CONTROL_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

	/**
	 * Devuelve la versión normalizada (uppercase + sin espacios) si es válida; null si no.
	 *
	 * @param string $raw Cadena cruda.
	 * @return string|null
	 */
	public static function normalize( string $raw ): ?string {
		$value = strtoupper( trim( $raw ) );
		$value = preg_replace( '/[\s\-]/', '', $value );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		return self::is_valid( $value ) ? $value : null;
	}

	/**
	 * Comprueba si una cadena es un DNI o NIE válido.
	 *
	 * Espera input ya normalizado (uppercase sin espacios) — si no, llama a
	 * normalize() antes.
	 *
	 * @param string $value Cadena candidata.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		if ( 1 !== preg_match( '/^[XYZ]?\d{7,8}[A-Z]$/', $value ) ) {
			return false;
		}

		$body   = substr( $value, 0, -1 );
		$letter = substr( $value, -1 );

		// Normalizar prefijo NIE a número.
		$prefix = substr( $body, 0, 1 );
		if ( in_array( $prefix, array( 'X', 'Y', 'Z' ), true ) ) {
			$map  = array(
				'X' => '0',
				'Y' => '1',
				'Z' => '2',
			);
			$body = $map[ $prefix ] . substr( $body, 1 );
		}

		if ( 1 !== preg_match( '/^\d{8}$/', $body ) ) {
			return false;
		}

		$expected = self::CONTROL_LETTERS[ ( (int) $body ) % 23 ];
		return $letter === $expected;
	}
}
