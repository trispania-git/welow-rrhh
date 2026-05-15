<?php
/**
 * Dashboard frontend de Welow RRHH.
 *
 * Procesa el shortcode [welow_rrhh_dashboard] y resuelve los tabs
 * visibles para el usuario actual.
 *
 * @package Welow\RRHH\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Frontend;

use Welow\RRHH\Frontend\Tabs\TabInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard.
 */
final class Dashboard {

	/**
	 * Tabs por defecto del Core (inyectados).
	 *
	 * @var TabInterface[]
	 */
	private array $core_tabs;

	/**
	 * Constructor.
	 *
	 * @param TabInterface[] $core_tabs Tabs base (Summary, Notifications).
	 */
	public function __construct( array $core_tabs ) {
		$this->core_tabs = $core_tabs;
	}

	/**
	 * Callback del shortcode [welow_rrhh_dashboard].
	 *
	 * @return string HTML del dashboard.
	 */
	public function render_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return Templates::render(
				'login-required',
				array(
					'login_url' => wp_login_url( self::current_url() ),
				)
			);
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || 0 === $user->ID ) {
			return '';
		}

		$tabs = $this->resolve_visible_tabs( $user );
		if ( empty( $tabs ) ) {
			return Templates::render(
				'login-required',
				array(
					'message'   => __( 'No hay tabs disponibles para tu usuario.', 'welow-rrhh' ),
					'login_url' => '',
				)
			);
		}

		$active_slug = self::current_tab_slug( $tabs );

		return Templates::render(
			'dashboard',
			array(
				'user'        => $user,
				'tabs'        => $tabs,
				'active_slug' => $active_slug,
				'current_url' => self::current_url(),
			)
		);
	}

	/**
	 * Resuelve los tabs visibles para el usuario, aplicando el filtro de extensión.
	 *
	 * @param \WP_User $user Usuario.
	 * @return TabInterface[]
	 */
	private function resolve_visible_tabs( \WP_User $user ): array {
		/**
		 * Permite a los módulos añadir o quitar tabs del dashboard.
		 *
		 * @since 0.1.0
		 *
		 * @param TabInterface[] $tabs Tabs por defecto.
		 * @param \WP_User       $user Usuario.
		 */
		$tabs = apply_filters( 'welow_rrhh/dashboard/tabs', $this->core_tabs, $user );

		$valid = array();
		foreach ( (array) $tabs as $tab ) {
			if ( ! $tab instanceof TabInterface ) {
				continue;
			}
			if ( ! $tab->visible_for( $user ) ) {
				continue;
			}
			$valid[ $tab->slug() ] = $tab;
		}

		uasort(
			$valid,
			static fn( TabInterface $a, TabInterface $b ): int => $a->order() <=> $b->order()
		);

		return $valid;
	}

	/**
	 * Devuelve el slug del tab activo a partir de ?welow_tab o, en su defecto,
	 * el primer tab de la lista.
	 *
	 * @param TabInterface[] $tabs Tabs visibles.
	 * @return string
	 */
	private static function current_tab_slug( array $tabs ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['welow_tab'] ) ? sanitize_key( (string) $_GET['welow_tab'] ) : '';
		if ( '' !== $requested && isset( $tabs[ $requested ] ) ) {
			return $requested;
		}
		return (string) array_key_first( $tabs );
	}

	/**
	 * URL actual completa (para redirect_to del login y para enlaces de tabs).
	 *
	 * @return string
	 */
	public static function current_url(): string {
		global $wp;
		if ( ! $wp instanceof \WP ) {
			return home_url( '/' );
		}
		$path = $wp->request ? $wp->request : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query = ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . sanitize_text_field( wp_unslash( (string) $_SERVER['QUERY_STRING'] ) ) : '';
		return home_url( trailingslashit( $path ) ) . $query;
	}
}
