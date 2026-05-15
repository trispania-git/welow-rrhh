<?php
/**
 * Plugin Name:       Welow RRHH
 * Plugin URI:        https://github.com/trispania-git/welow-rrhh
 * Description:       Plugin white-label para centralizar procesos de RRHH en PYMES (núcleo modular + Fichajes + Vacaciones).
 * Version:           0.1.0
 * Requires at least: 6.4
 * Tested up to:      6.4
 * Requires PHP:      8.1
 * Author:            Trispania
 * Author URI:        https://github.com/trispania-git
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       welow-rrhh
 * Domain Path:       /languages
 *
 * @package Welow\RRHH
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Identidad del plugin.
define( 'WELOW_RRHH_VERSION', '0.1.0' );
define( 'WELOW_RRHH_PLUGIN_FILE', __FILE__ );
define( 'WELOW_RRHH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WELOW_RRHH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WELOW_RRHH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Versión mínima requerida (informativa; el header marca la regla para WP).
define( 'WELOW_RRHH_MIN_PHP', '8.1' );
define( 'WELOW_RRHH_MIN_WP', '6.4' );

/**
 * Carga el autoload de Composer si está disponible.
 *
 * Si no existe vendor/autoload.php (instalación corrupta o sin "composer install"),
 * el plugin se desactiva mostrando un aviso al administrador.
 */
$welow_rrhh_autoload = WELOW_RRHH_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! is_readable( $welow_rrhh_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Welow RRHH no puede arrancar: falta el autoload de Composer. Ejecuta "composer install --no-dev" en la carpeta del plugin.',
				'welow-rrhh'
			);
			echo '</p></div>';
		}
	);
	return;
}
require_once $welow_rrhh_autoload;
unset( $welow_rrhh_autoload );

/**
 * Helper global para acceder a la instancia del plugin.
 *
 * @return \Welow\RRHH\Plugin
 */
function welow_rrhh(): \Welow\RRHH\Plugin {
	return \Welow\RRHH\Plugin::instance();
}

// Hooks de ciclo de vida.
register_activation_hook( __FILE__, array( \Welow\RRHH\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Welow\RRHH\Deactivator::class, 'deactivate' ) );

// Bootstrap diferido a plugins_loaded para garantizar que WP esté listo.
add_action(
	'plugins_loaded',
	static function (): void {
		welow_rrhh()->boot();
	},
	10
);
