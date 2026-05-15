<?php
/**
 * Tab "Notificaciones" del dashboard frontend.
 *
 * @package Welow\RRHH\Frontend\Tabs
 */

declare( strict_types=1 );

namespace Welow\RRHH\Frontend\Tabs;

use Welow\RRHH\Frontend\Templates;
use Welow\RRHH\Notifications\NotificationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Tab Notificaciones.
 */
final class NotificationsTab implements TabInterface {

	/**
	 * Repositorio de notificaciones.
	 *
	 * @var NotificationRepository
	 */
	private NotificationRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param NotificationRepository $repository Repo.
	 */
	public function __construct( NotificationRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'notifications';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Notificaciones', 'welow-rrhh' );
	}

	/**
	 * Indica si el tab es visible para el usuario.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function visible_for( \WP_User $user ): bool {
		unset( $user );
		return true;
	}

	/**
	 * Posición.
	 *
	 * @return int
	 */
	public function order(): int {
		return 900;
	}

	/**
	 * Renderiza.
	 *
	 * @param \WP_User $user Usuario.
	 * @return void
	 */
	public function render( \WP_User $user ): void {
		// Procesa "Marcar todo como leído" antes de renderizar la lista.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['welow_action'] ) ? sanitize_key( (string) $_GET['welow_action'] ) : '';
		if ( 'mark_all_read' === $action && check_admin_referer( 'welow_rrhh_mark_all_read', '_wpnonce' ) ) {
			$this->repository->mark_all_read_for_user( $user->ID );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['welow_filter'] ) ? sanitize_key( (string) $_GET['welow_filter'] ) : 'all';
		$unread = 'unread' === $filter;

		$items        = $this->repository->find_for_user( $user->ID, $unread, 50, 0 );
		$unread_count = $this->repository->count_unread( $user->ID );

		$mark_all_url = wp_nonce_url(
			add_query_arg(
				array(
					'welow_tab'    => $this->slug(),
					'welow_action' => 'mark_all_read',
				),
				self::current_url()
			),
			'welow_rrhh_mark_all_read'
		);

		$filter_all_url    = add_query_arg(
			array(
				'welow_tab'    => $this->slug(),
				'welow_filter' => 'all',
			),
			self::current_url()
		);
		$filter_unread_url = add_query_arg(
			array(
				'welow_tab'    => $this->slug(),
				'welow_filter' => 'unread',
			),
			self::current_url()
		);

		$html = Templates::render(
			'tab-notifications',
			array(
				'user'              => $user,
				'items'             => $items,
				'unread_count'      => $unread_count,
				'filter'            => $filter,
				'mark_all_url'      => $mark_all_url,
				'filter_all_url'    => $filter_all_url,
				'filter_unread_url' => $filter_unread_url,
			)
		);
		// La plantilla ya escapa con esc_html/esc_url internamente.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * URL actual sin parámetros internos del wizard de notificaciones.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		global $wp;
		$url = home_url( add_query_arg( array(), $wp ? $wp->request : '' ) );
		// Quitamos params transitorios.
		return remove_query_arg( array( 'welow_action', '_wpnonce', 'welow_filter' ), $url );
	}
}
