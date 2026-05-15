<?php
/**
 * Contrato común para los módulos de Welow RRHH.
 *
 * @package Welow\RRHH\Modules
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Todos los módulos del plugin (Core extendido, Fichajes, Vacaciones, etc.)
 * deben implementar este contrato.
 */
interface ModuleInterface {

	/**
	 * Identificador interno del módulo (kebab-case). Único en todo el plugin.
	 */
	public function slug(): string;

	/**
	 * Nombre visible del módulo (traducible).
	 */
	public function name(): string;

	/**
	 * Descripción breve (traducible).
	 */
	public function description(): string;

	/**
	 * Versión del módulo (semver). Se usa para detectar migraciones pendientes.
	 */
	public function version(): string;

	/**
	 * Slugs de otros módulos requeridos. Vacío si no hay dependencias.
	 *
	 * @return string[]
	 */
	public function dependencies(): array;

	/**
	 * Hook de activación del módulo. Se ejecuta una sola vez al activar.
	 * Debe ser transaccional (rollback si falla).
	 *
	 * @return void
	 */
	public function activate(): void;

	/**
	 * Hook de desactivación. No destructivo por defecto.
	 *
	 * @return void
	 */
	public function deactivate(): void;

	/**
	 * Migración idempotente. Se ejecuta automáticamente al cargar si la
	 * versión persistida difiere de la versión declarada.
	 *
	 * @return void
	 */
	public function migrate(): void;

	/**
	 * Arranque del módulo. Registra hooks, shortcodes, REST, assets, etc.
	 * No debe ejecutarse en `__construct`.
	 *
	 * @return void
	 */
	public function boot(): void;

	/**
	 * Lista de capabilities que el módulo añade.
	 *
	 * Formato: array<string $role_slug, string[] $capabilities>.
	 *
	 * @return array<string, string[]>
	 */
	public function capabilities(): array;
}
