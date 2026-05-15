<?php
/**
 * DTO inmutable de una notificación in-app (welow_notifications).
 *
 * @package Welow\RRHH\Notifications
 */

declare( strict_types=1 );

namespace Welow\RRHH\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Notification DTO.
 */
final class Notification {

	/**
	 * Constructor.
	 *
	 * @param int|null                $id         PK.
	 * @param int                     $user_id    Destinatario.
	 * @param string                  $type       Slug del tipo (ej. vacation_requested).
	 * @param array<string, mixed>    $payload    Datos serializables (vars de plantilla, refs a entidades).
	 * @param \DateTimeImmutable|null $read_at    Timestamp de lectura (null = no leído).
	 * @param \DateTimeImmutable      $created_at Timestamp de creación.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $user_id,
		public readonly string $type,
		public readonly array $payload,
		public readonly ?\DateTimeImmutable $read_at,
		public readonly \DateTimeImmutable $created_at
	) {}

	/**
	 * Indica si la notificación ya fue marcada como leída.
	 *
	 * @return bool
	 */
	public function is_read(): bool {
		return null !== $this->read_at;
	}
}
