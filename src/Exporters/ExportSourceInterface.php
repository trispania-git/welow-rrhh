<?php
/**
 * Contrato común para fuentes exportables.
 *
 * Los módulos registran fuentes propias vía el filtro
 * `welow_rrhh/exporters/sources` (§6.6 / §16).
 *
 * @package Welow\RRHH\Exporters
 */

declare( strict_types=1 );

namespace Welow\RRHH\Exporters;

defined( 'ABSPATH' ) || exit;

/**
 * Export source.
 */
interface ExportSourceInterface {

	/**
	 * Slug único de la fuente (employees|time_entries|vacations|holidays|...).
	 */
	public function slug(): string;

	/**
	 * Nombre humano para títulos/listas (traducible).
	 */
	public function name(): string;

	/**
	 * Indica si el usuario actual puede ejecutar este export.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function can_export( \WP_User $user ): bool;

	/**
	 * Cabecera de columnas.
	 *
	 * @return string[]
	 */
	public function headers(): array;

	/**
	 * Iterable de filas (cada fila = array alineado con headers).
	 *
	 * Recomendado usar generadores para no cargar todo en memoria.
	 *
	 * @return iterable<int, string[]>
	 */
	public function rows(): iterable;

	/**
	 * Formato sugerido por defecto (csv|pdf|...).
	 *
	 * @return string
	 */
	public function default_format(): string;
}
