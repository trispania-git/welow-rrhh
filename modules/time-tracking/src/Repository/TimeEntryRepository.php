<?php
/**
 * Repositorio de eventos de fichaje (welow_time_entries).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Repository
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Repository;

use Welow\RRHH\Database\Repository\AbstractRepository;
use Welow\RRHH\Modules\TimeTracking\Data\EntrySource;
use Welow\RRHH\Modules\TimeTracking\Data\EventType;
use Welow\RRHH\Modules\TimeTracking\Data\TimeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * TimeEntryRepository.
 */
final class TimeEntryRepository extends AbstractRepository {

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->wpdb->prefix . 'welow_time_entries';
	}

	/**
	 * Recupera por PK.
	 *
	 * @param int $id Identificador.
	 * @return TimeEntry|null
	 */
	public function find_by_id( int $id ): ?TimeEntry {
		$row = parent::find( $id );
		return null === $row ? null : self::hydrate( $row );
	}

	/**
	 * Último evento del usuario (más reciente por occurred_at).
	 *
	 * @param int $user_id Usuario.
	 * @return TimeEntry|null
	 */
	public function find_last_for_user( int $user_id ): ?TimeEntry {
		$table = $this->table();
		$query = "SELECT * FROM {$table} WHERE user_id = %d ORDER BY occurred_at DESC, id DESC LIMIT 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $query, $user_id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Búsqueda por rango (incluido) para un usuario.
	 *
	 * Si $user_id es null, busca para todos (uso interno admin/HR).
	 *
	 * @param int|null                $user_id Usuario o null para todos.
	 * @param \DateTimeImmutable|null $from    Desde (inclusive).
	 * @param \DateTimeImmutable|null $to      Hasta (inclusive).
	 * @param int                     $limit   Tamaño.
	 * @param int                     $offset  Offset.
	 * @return TimeEntry[]
	 */
	public function find_for_range(
		?int $user_id,
		?\DateTimeImmutable $from,
		?\DateTimeImmutable $to,
		int $limit = 500,
		int $offset = 0
	): array {
		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		if ( null !== $user_id ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}
		if ( null !== $from ) {
			$where[]  = 'occurred_at >= %s';
			$params[] = $from->format( 'Y-m-d 00:00:00' );
		}
		if ( null !== $to ) {
			$where[]  = 'occurred_at <= %s';
			$params[] = $to->format( 'Y-m-d 23:59:59' );
		}

		$where_sql = implode( ' AND ', $where );
		$query     = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY occurred_at ASC, id ASC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[]  = max( 1, min( 5000, $limit ) );
		$params[]  = max( 0, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Inserta un evento.
	 *
	 * @param TimeEntry $entry DTO.
	 * @return int ID insertado.
	 *
	 * @throws \RuntimeException Si insert falla.
	 */
	public function insert_entry( TimeEntry $entry ): int {
		$now     = current_time( 'mysql' );
		$data    = array(
			'user_id'       => $entry->user_id,
			'event_type'    => $entry->event_type->value,
			'occurred_at'   => $entry->occurred_at->format( 'Y-m-d H:i:s' ),
			'source'        => $entry->source->value,
			'latitude'      => $entry->latitude,
			'longitude'     => $entry->longitude,
			'ip'            => $entry->ip,
			'user_agent'    => $entry->user_agent,
			'note'          => $entry->note,
			'attachment_id' => $entry->attachment_id,
			'is_edited'     => $entry->is_edited ? 1 : 0,
			'edited_by'     => $entry->edited_by,
			'edit_reason'   => $entry->edit_reason,
			'created_at'    => $now,
			'updated_at'    => $now,
		);
		$formats = array(
			'%d',
			'%s',
			'%s',
			'%s',
			'%f',
			'%f',
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
		);

		$ok = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $ok ) {
			throw new \RuntimeException(
				sprintf( 'TimeEntryRepository::insert — failed: %s', esc_html( (string) $this->wpdb->last_error ) )
			);
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Actualiza un evento. Si $mark_edited es true, fija is_edited=1 y guarda
	 * edited_by + edit_reason.
	 *
	 * @param int                  $id          Identificador.
	 * @param array<string, mixed> $changes     Cambios a aplicar.
	 * @param bool                 $mark_edited Marcar como editado.
	 * @param int|null             $editor_id   Quién edita (si mark_edited).
	 * @param string|null          $edit_reason Motivo (si mark_edited).
	 * @return bool
	 *
	 * @throws \RuntimeException Si update falla.
	 */
	public function update_entry( int $id, array $changes, bool $mark_edited = false, ?int $editor_id = null, ?string $edit_reason = null ): bool {
		$allowed = array(
			'user_id'       => '%d',
			'event_type'    => '%s',
			'occurred_at'   => '%s',
			'source'        => '%s',
			'latitude'      => '%f',
			'longitude'     => '%f',
			'ip'            => '%s',
			'user_agent'    => '%s',
			'note'          => '%s',
			'attachment_id' => '%d',
		);

		$data    = array();
		$formats = array();
		foreach ( $allowed as $col => $format ) {
			if ( array_key_exists( $col, $changes ) ) {
				$data[ $col ] = $changes[ $col ];
				$formats[]    = $format;
			}
		}
		if ( $mark_edited ) {
			$data['is_edited']   = 1;
			$formats[]           = '%d';
			$data['edited_by']   = $editor_id;
			$formats[]           = '%d';
			$data['edit_reason'] = $edit_reason;
			$formats[]           = '%s';
		}
		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql' );
		$formats[]          = '%s';

		$result = $this->wpdb->update( $this->table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf( 'TimeEntryRepository::update — failed: %s', esc_html( (string) $this->wpdb->last_error ) )
			);
		}
		return $result > 0;
	}

	/**
	 * Elimina un evento (sólo HR/admin con motivo en audit).
	 *
	 * @param int $id Identificador.
	 * @return bool
	 */
	public function delete_by_id( int $id ): bool {
		$result = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result && $result > 0;
	}

	/**
	 * Convierte una fila de BD a DTO.
	 *
	 * @param array<string, mixed> $row Fila cruda.
	 * @return TimeEntry
	 */
	private static function hydrate( array $row ): TimeEntry {
		$occurred = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) ( $row['occurred_at'] ?? '' ) );
		if ( false === $occurred ) {
			$occurred = new \DateTimeImmutable( 'now' );
		}
		$created = isset( $row['created_at'] )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $row['created_at'] )
			: false;
		$updated = isset( $row['updated_at'] )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $row['updated_at'] )
			: false;

		return new TimeEntry(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(int) ( $row['user_id'] ?? 0 ),
			EventType::from_db( isset( $row['event_type'] ) ? (string) $row['event_type'] : null ) ?? EventType::PUNCH_IN,
			$occurred,
			EntrySource::from_db( isset( $row['source'] ) ? (string) $row['source'] : null ),
			isset( $row['latitude'] ) && null !== $row['latitude'] ? (float) $row['latitude'] : null,
			isset( $row['longitude'] ) && null !== $row['longitude'] ? (float) $row['longitude'] : null,
			! empty( $row['ip'] ) ? (string) $row['ip'] : null,
			! empty( $row['user_agent'] ) ? (string) $row['user_agent'] : null,
			! empty( $row['note'] ) ? (string) $row['note'] : null,
			isset( $row['attachment_id'] ) && null !== $row['attachment_id'] ? (int) $row['attachment_id'] : null,
			! empty( $row['is_edited'] ),
			isset( $row['edited_by'] ) && null !== $row['edited_by'] ? (int) $row['edited_by'] : null,
			! empty( $row['edit_reason'] ) ? (string) $row['edit_reason'] : null,
			false === $created ? null : $created,
			false === $updated ? null : $updated
		);
	}
}
