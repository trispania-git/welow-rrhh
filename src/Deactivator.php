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
	 * Mismo razonamiento que Activator::activate(): no añadimos check de
	 * capability — register_deactivation_hook ya garantiza la invocación
	 * desde el contexto correcto y el check rompe wp-cli / WP-Cron.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}
}
