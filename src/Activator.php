<?php
/**
 * Hook de activación del plugin Welow RRHH.
 *
 * Stub mínimo. En futuras iteraciones aquí se crearán tablas, roles y
 * capabilities del Core, y se inicializará la opción de módulos activos.
 *
 * @package Welow\RRHH
 */

declare( strict_types=1 );

namespace Welow\RRHH;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la activación del plugin.
 */
final class Activator {

	/**
	 * Punto de entrada del hook register_activation_hook().
	 *
	 * TODO(welow): en próximas iteraciones inicializar:
	 *   - tablas custom (Schema::install_core)
	 *   - roles + capabilities
	 *   - opción `welow_rrhh_active_modules` (array vacío inicial)
	 *   - opción `welow_rrhh_setup_progress`
	 *   - redirección al wizard tras activar (welow_rrhh_do_redirect_after_activation).
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Guarda la versión instalada como marca para futuras migraciones.
		update_option( 'welow_rrhh_version', WELOW_RRHH_VERSION, false );

		// Inicializa la lista de módulos activos si aún no existe.
		if ( false === get_option( 'welow_rrhh_active_modules', false ) ) {
			update_option( 'welow_rrhh_active_modules', array(), false );
		}

		// Vacía el cache de permalinks porque módulos futuros añadirán endpoints.
		flush_rewrite_rules( false );
	}
}
