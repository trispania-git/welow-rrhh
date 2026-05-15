<?php
/**
 * Contrato común para tabs del dashboard frontend.
 *
 * Los módulos añaden tabs propios vía el filtro `welow_rrhh/dashboard/tabs`.
 *
 * @package Welow\RRHH\Frontend\Tabs
 */

declare( strict_types=1 );

namespace Welow\RRHH\Frontend\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * Tab del dashboard.
 */
interface TabInterface {

	/**
	 * Identificador del tab (kebab-case). Se usa en el query arg ?welow_tab.
	 */
	public function slug(): string;

	/**
	 * Etiqueta visible (traducible).
	 */
	public function label(): string;

	/**
	 * Indica si el tab debe mostrarse al usuario dado.
	 *
	 * @param \WP_User $user Usuario actual.
	 * @return bool
	 */
	public function visible_for( \WP_User $user ): bool;

	/**
	 * Renderiza el contenido del tab (imprime HTML).
	 *
	 * @param \WP_User $user Usuario actual.
	 * @return void
	 */
	public function render( \WP_User $user ): void;

	/**
	 * Posición de ordenación; menor = más a la izquierda.
	 */
	public function order(): int;
}
