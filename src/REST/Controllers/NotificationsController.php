<?php
/**
 * Endpoints REST de notificaciones in-app (§10).
 *
 *   GET    /welow-rrhh/v1/notifications
 *   POST   /welow-rrhh/v1/notifications/{id}/mark-read
 *   POST   /welow-rrhh/v1/notifications/mark-all-read
 *
 * Todos requieren usuario logueado (X-WP-Nonce vía REST API estándar).
 *
 * @package Welow\RRHH\REST\Controllers
 */

declare( strict_types=1 );

namespace Welow\RRHH\REST\Controllers;

use Welow\RRHH\Notifications\Notification;
use Welow\RRHH\Notifications\NotificationRepository;
use Welow\RRHH\REST\AbstractController;

defined( 'ABSPATH' ) || exit;

/**
 * NotificationsController.
 */
final class NotificationsController extends AbstractController {

	/**
	 * Repositorio.
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
	 * Registra las rutas.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/notifications',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_notifications' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'unread_only' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'limit'       => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
					'offset'      => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/notifications/(?P<id>\d+)/mark-read',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/notifications/mark-all-read',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_all_read' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			)
		);
	}

	/**
	 * GET /notifications
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_notifications( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = $this->repository->find_for_user(
			$user_id,
			(bool) $request->get_param( 'unread_only' ),
			(int) $request->get_param( 'limit' ),
			(int) $request->get_param( 'offset' )
		);

		return $this->ok(
			array(
				'items'        => array_map( array( $this, 'serialize_notification' ), $items ),
				'unread_count' => $this->repository->count_unread( $user_id ),
			)
		);
	}

	/**
	 * POST /notifications/{id}/mark-read
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function mark_read( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$ok      = $this->repository->mark_read( $id, $user_id );
		if ( ! $ok ) {
			return $this->error( 'not_found', __( 'Notificación no encontrada o no te pertenece.', 'welow-rrhh' ), 404 );
		}
		return $this->ok(
			array(
				'id'      => $id,
				'read_at' => current_time( 'c' ),
			)
		);
	}

	/**
	 * POST /notifications/mark-all-read
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function mark_all_read( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$user_id = get_current_user_id();
		$marked  = $this->repository->mark_all_read_for_user( $user_id );
		return $this->ok( array( 'marked' => $marked ) );
	}

	/**
	 * Serializa una notificación para la respuesta JSON.
	 *
	 * @param Notification $n Notificación.
	 * @return array<string, mixed>
	 */
	private function serialize_notification( Notification $n ): array {
		return array(
			'id'         => $n->id,
			'type'       => $n->type,
			'payload'    => $n->payload,
			'is_read'    => $n->is_read(),
			'read_at'    => null !== $n->read_at ? $n->read_at->format( 'c' ) : null,
			'created_at' => $n->created_at->format( 'c' ),
		);
	}
}
