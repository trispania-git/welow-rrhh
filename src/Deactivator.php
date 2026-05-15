<?php
/**
 * Hook de desactivación del plugin Welow RRHH.
 *
 * No destructivo por defecto: los datos del cliente se preservan.
 * El borrado real (si se solicita) se realiza en uninstall.php.
 *
 * @package Welow\RRHH
 */

declare( strict_types=1 );

namespace Welow\RRHH;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la desactivación del plugin.
 */
final class Deactivator {

	/**
	 * Punto de entrada del hook register_deactivation_hook().
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		flush_rewrite_rules( false );
	}
}
