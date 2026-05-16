<?php
/**
 * Localizador de templates del módulo Fichajes (mismo patrón que el helper
 * Core, pero con su propia carpeta + override desde el tema).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * ModuleTemplates.
 */
final class ModuleTemplates {

	/**
	 * Resuelve la ruta absoluta de una plantilla del módulo.
	 *
	 * Override desde el tema: wp-content/themes/<tema>/welow-rrhh/time-tracking/<relative>.php
	 * Fallback al plugin:    modules/time-tracking/templates/<relative>.php
	 *
	 * @param string $relative Ruta relativa con o sin .php.
	 * @return string|null
	 */
	public static function locate( string $relative ): ?string {
		$relative = ltrim( $relative, '/' );
		if ( ! str_ends_with( $relative, '.php' ) ) {
			$relative .= '.php';
		}
		$theme = trailingslashit( get_stylesheet_directory() ) . 'welow-rrhh/time-tracking/' . $relative;
		if ( is_readable( $theme ) ) {
			return $theme;
		}
		$plugin = WELOW_RRHH_PLUGIN_DIR . 'modules/time-tracking/templates/' . $relative;
		if ( is_readable( $plugin ) ) {
			return $plugin;
		}
		return null;
	}

	/**
	 * Renderiza una plantilla y devuelve su output.
	 *
	 * @param string               $relative Ruta relativa.
	 * @param array<string, mixed> $vars     Variables (`$vars` dentro de la plantilla).
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
