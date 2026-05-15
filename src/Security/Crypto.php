<?php
/**
 * Cifrado simétrico y hashing keyed para datos sensibles.
 *
 * Implementa los requisitos de §12:
 *   - Cifrado de DNI/NIE en reposo con AES-256-GCM.
 *   - Clave derivada vía HKDF-SHA256 a partir de AUTH_KEY (wp-config.php).
 *   - Hash determinista (HMAC-SHA256) para columnas de búsqueda/uniqueness
 *     sobre datos cifrados (p. ej. `welow_employees.dni_nie_hash`).
 *
 * Formato del ciphertext devuelto por encrypt():
 *   base64( iv(12 bytes) || tag(16 bytes) || ciphertext )
 *
 * @package Welow\RRHH\Security
 */

declare( strict_types=1 );

namespace Welow\RRHH\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Cifrado y hashing keyed.
 */
final class Crypto {

	private const CIPHER     = 'aes-256-gcm';
	private const IV_LENGTH  = 12;
	private const TAG_LENGTH = 16;
	private const HKDF_HASH  = 'sha256';
	private const HMAC_HASH  = 'sha256';

	/**
	 * Cifra un texto plano.
	 *
	 * @param string $plaintext Texto a cifrar.
	 * @return string Ciphertext codificado en base64 (iv + tag + payload).
	 *
	 * @throws \RuntimeException Si OpenSSL no está disponible o falla.
	 */
	public static function encrypt( string $plaintext ): string {
		self::ensure_openssl();

		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';
		$ct  = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $ct ) {
			throw new \RuntimeException( 'Welow RRHH Crypto: fallo al cifrar.' );
		}

		// base64 aquí codifica payload binario para almacenarlo en VARCHAR; no es ofuscación.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $tag . $ct );
	}

	/**
	 * Descifra un valor previamente generado por encrypt().
	 *
	 * @param string $ciphertext Valor base64 producido por encrypt().
	 * @return string Texto plano original.
	 *
	 * @throws \RuntimeException Si el ciphertext es inválido o el tag no encaja.
	 */
	public static function decrypt( string $ciphertext ): string {
		self::ensure_openssl();

		// base64 aquí decodifica payload binario propio; no es ofuscación.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( $ciphertext, true );
		if ( false === $raw || strlen( $raw ) <= self::IV_LENGTH + self::TAG_LENGTH ) {
			throw new \RuntimeException( 'Welow RRHH Crypto: ciphertext inválido.' );
		}

		$iv  = substr( $raw, 0, self::IV_LENGTH );
		$tag = substr( $raw, self::IV_LENGTH, self::TAG_LENGTH );
		$ct  = substr( $raw, self::IV_LENGTH + self::TAG_LENGTH );

		$plain = openssl_decrypt( $ct, self::CIPHER, self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $plain ) {
			throw new \RuntimeException( 'Welow RRHH Crypto: tag de autenticación inválido o clave incorrecta.' );
		}

		return $plain;
	}

	/**
	 * Hash determinista keyed para usar como índice/UNIQUE sobre datos cifrados.
	 *
	 * Normaliza el input (uppercase + trim) y devuelve hex de 64 chars.
	 *
	 * @param string $value Valor a hashear.
	 * @return string Hex (CHAR(64)).
	 */
	public static function lookup_hash( string $value ): string {
		$normalized = strtoupper( trim( $value ) );
		return hash_hmac( self::HMAC_HASH, $normalized, self::lookup_key() );
	}

	/**
	 * Verifica que OpenSSL está disponible para los algoritmos requeridos.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException Si falta ext-openssl o el cipher.
	 */
	private static function ensure_openssl(): void {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new \RuntimeException( 'Welow RRHH Crypto: extensión ext-openssl requerida.' );
		}
		if ( ! in_array( self::CIPHER, openssl_get_cipher_methods( true ), true ) ) {
			throw new \RuntimeException( 'Welow RRHH Crypto: cipher AES-256-GCM no soportado.' );
		}
	}

	/**
	 * Clave maestra raw para derivar subclaves. Toma AUTH_KEY si existe; en
	 * caso contrario cae a wp_salt('auth'). Si la salt resultante está vacía,
	 * lanza excepción (no podemos cifrar de forma segura).
	 *
	 * @return string Material de clave binario.
	 *
	 * @throws \RuntimeException Si no hay material de clave disponible.
	 */
	private static function master_key(): string {
		$source = ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) ? AUTH_KEY : wp_salt( 'auth' );
		if ( '' === $source ) {
			throw new \RuntimeException(
				'Welow RRHH Crypto: AUTH_KEY/auth salt vacía; revisa wp-config.php.'
			);
		}
		return (string) $source;
	}

	/**
	 * Subclave para cifrado AES (HKDF derivada).
	 *
	 * @return string 32 bytes.
	 */
	private static function encryption_key(): string {
		return hash_hkdf( self::HKDF_HASH, self::master_key(), 32, 'welow-rrhh|encrypt|v1' );
	}

	/**
	 * Subclave para HMAC de lookup (HKDF derivada, distinta de la de cifrado).
	 *
	 * @return string 32 bytes.
	 */
	private static function lookup_key(): string {
		return hash_hkdf( self::HKDF_HASH, self::master_key(), 32, 'welow-rrhh|lookup|v1' );
	}
}
