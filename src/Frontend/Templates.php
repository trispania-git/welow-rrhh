<?php
/**
 * Localizador de plantillas frontend con override desde el tema activo.
 *
 * Convención (replica WooCommerce):
 *   1. wp-content/themes/<tema>/welow-rrhh/<relative>.php  (override)
 *   2. <plugin>/src/Frontend/templates/<relative>.php       (default)
 *
 * Cada plantilla recibe `$vars` como variable única (un array). Para
 * mantener las plantillas simples, NO usamos extract() ni globales.
 *
 * @package Welow\RRHH\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Helper de templates frontend.
 */
final class Templates {

	/**
	 * Resuelve la ruta absoluta de una plantilla.
	 *
	 * @param string $relative Ruta relativa (sin extensión opcional, con .php).
	 * @return string|null
	 */
	public static function locate( string $relative ): ?string {
		$relative = ltrim( $relative, '/' );
		if ( ! str_ends_with( $relative, '.php' ) ) {
			$relative .= '.php';
		}

		$theme = trailingslashit( get_stylesheet_directory() ) . 'welow-rrhh/' . $relative;
		if ( is_readable( $theme ) ) {
			return $theme;
		}

		$plugin = WELOW_RRHH_PLUGIN_DIR . 'src/Frontend/templates/' . $relative;
		if ( is_readable( $plugin ) ) {
			return $plugin;
		}

		return null;
	}

	/**
	 * Renderiza una plantilla y devuelve su output.
	 *
	 * @param string               $relative Ruta relativa.
	 * @param array<string, mixed> $vars     Variables disponibles como `$vars`.
	 * @return string
	 */
	public static function render( string $relative, array $vars = array() ): string {
		$path = self::locate( $relative );
		if ( null === $path ) {
			return '';
		}
		$render = static function () use ( $path, $vars ): string {
			ob_start();
			include $path;
			return (string) ob_get_clean();
		};
		return $render();
	}
}
