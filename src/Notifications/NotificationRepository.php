<?php
/**
 * Repositorio de notificaciones in-app (welow_notifications).
 *
 * @package Welow\RRHH\Notifications
 */

declare( strict_types=1 );

namespace Welow\RRHH\Notifications;

use Welow\RRHH\Database\Repository\AbstractRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Repositorio de notificaciones in-app.
 */
final class NotificationRepository extends AbstractRepository {

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->wpdb->prefix . 'welow_notifications';
	}

	/**
	 * Inserta una notificación in-app.
	 *
	 * @param int                  $user_id Destinatario.
	 * @param string               $type    Tipo.
	 * @param array<string, mixed> $payload Payload.
	 * @return int|false ID insertado o false en error.
	 */
	public function insert_notification( int $user_id, string $type, array $payload ) {
		$data    = array(
			'user_id'    => $user_id,
			'type'       => $type,
			'payload'    => wp_json_encode( $payload ),
			'created_at' => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%s', '%s', '%s' );

		$ok = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $ok ) {
			return false;
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Lista paginada de notificaciones de un usuario.
	 *
	 * @param int  $user_id     Usuario destinatario.
	 * @param bool $unread_only Si true, sólo no leídas.
	 * @param int  $limit       Tamaño.
	 * @param int  $offset      Offset.
	 * @return Notification[]
	 */
	public function find_for_user( int $user_id, bool $unread_only = false, int $limit = 50, int $offset = 0 ): array {
		$table  = $this->table();
		$where  = 'user_id = %d';
		$params = array( $user_id );
		if ( $unread_only ) {
			$where .= ' AND read_at IS NULL';
		}

		$sql_template = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[]     = max( 1, min( 200, $limit ) );
		$params[]     = max( 0, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql_template, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Cuenta no leídas para un usuario.
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public function count_unread( int $user_id ): int {
		$table = $this->table();
		$query = "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND read_at IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $query, $user_id ) );
	}

	/**
	 * Marca todas las notificaciones de un usuario como leídas.
	 *
	 * @param int $user_id Usuario.
	 * @return int Filas actualizadas.
	 */
	public function mark_all_read_for_user( int $user_id ): int {
		$result = $this->wpdb->update(
			$this->table(),
			array( 'read_at' => current_time( 'mysql' ) ),
			array(
				'user_id' => $user_id,
				'read_at' => null,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Marca una notificación concreta como leída.
	 *
	 * @param int $id      Notificación.
	 * @param int $user_id Usuario (para enforzar propiedad).
	 * @return bool
	 */
	public function mark_read( int $id, int $user_id ): bool {
		$result = $this->wpdb->update(
			$this->table(),
			array( 'read_at' => current_time( 'mysql' ) ),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
		return false !== $result && $result > 0;
	}

	/**
	 * Hydrate.
	 *
	 * @param array<string, mixed> $row Fila cruda.
	 * @return Notification
	 */
	private static function hydrate( array $row ): Notification {
		$payload = array();
		if ( ! empty( $row['payload'] ) ) {
			$decoded = json_decode( (string) $row['payload'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}
		$created = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) ( $row['created_at'] ?? '' ) );
		$read    = ! empty( $row['read_at'] )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $row['read_at'] )
			: false;

		return new Notification(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(int) ( $row['user_id'] ?? 0 ),
			(string) ( $row['type'] ?? '' ),
			$payload,
			false === $read ? null : $read,
			false === $created ? new \DateTimeImmutable( 'now' ) : $created
		);
	}
}
