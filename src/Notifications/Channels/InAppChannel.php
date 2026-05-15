<?php
/**
 * Canal "in-app": inserta una fila en welow_notifications.
 *
 * @package Welow\RRHH\Notifications\Channels
 */

declare( strict_types=1 );

namespace Welow\RRHH\Notifications\Channels;

use Welow\RRHH\Notifications\NotificationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * InAppChannel.
 */
final class InAppChannel implements ChannelInterface {

	/**
	 * Repositorio.
	 *
	 * @var NotificationRepository
	 */
	private NotificationRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param NotificationRepository $repository Repositorio.
	 */
	public function __construct( NotificationRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'in_app';
	}

	/**
	 * Inserta la notificación en welow_notifications para el usuario.
	 *
	 * @param int                  $user_id Destinatario.
	 * @param string               $type    Tipo de notificación.
	 * @param array<string, mixed> $payload Datos.
	 * @return bool
	 */
	public function deliver( int $user_id, string $type, array $payload ): bool {
		$id = $this->repository->insert_notification( $user_id, $type, $payload );
		return false !== $id;
	}
}
