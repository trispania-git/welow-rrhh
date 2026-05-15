<?php
/**
 * Migrator del schema Core.
 *
 * Se ejecuta en cada arranque del plugin y compara la versión del schema
 * persistida en `welow_rrhh_db_version` contra Schema::VERSION. Si difiere,
 * vuelve a ejecutar Schema::install() (dbDelta es idempotente y aplicará
 * los ALTER necesarios). Esto cubre el caso de "subir versión del plugin
 * sin re-activar".
 *
 * Los módulos gestionan su propio schema en su migrate() del ciclo de vida
 * (ver ModuleRegistry::boot_active()).
 *
 * @package Welow\RRHH\Database
 */

declare( strict_types=1 );

namespace Welow\RRHH\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Migrator del schema Core.
 */
final class Migrator {

	/**
	 * Ejecuta la migración si la versión instalada difiere de la declarada.
	 *
	 * Pensado para ser llamado en plugins_loaded. Es seguro llamarlo varias
	 * veces por request (no-op si las versiones coinciden).
	 *
	 * @return void
	 */
	public function run_if_needed(): void {
		$installed = (string) get_option( 'welow_rrhh_db_version', '' );
		if ( Schema::VERSION === $installed ) {
			return;
		}

		Schema::install();
	}
}
