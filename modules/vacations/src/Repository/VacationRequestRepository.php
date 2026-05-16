<?php
/**
 * Repositorio CRUD de solicitudes de vacaciones (welow_vacation_requests).
 *
 * @package Welow\RRHH\Modules\Vacations\Repository
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Repository;

use Welow\RRHH\Database\Repository\AbstractRepository;
use Welow\RRHH\Modules\Vacations\Data\RequestStatus;
use Welow\RRHH\Modules\Vacations\Data\RequestType;
use Welow\RRHH\Modules\Vacations\Data\VacationRequest;

defined( 'ABSPATH' ) || exit;

/**
 * VacationRequestRepository.
 */
final class VacationRequestRepository extends AbstractRepository {

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->wpdb->prefix . 'welow_vacation_requests';
	}

	/**
	 * Recupera por PK.
	 *
	 * @param int $id PK.
	 * @return VacationRequest|null
	 */
	public function find_by_id( int $id ): ?VacationRequest {
		$row = parent::find( $id );
		return null === $row ? null : self::hydrate( $row );
	}

	/**
	 * Solicitudes de un usuario para un año (todas las status).
	 *
	 * @param int $user_id Usuario.
	 * @param int $year    Año.
	 * @return VacationRequest[]
	 */
	public function find_for_user_year( int $user_id, int $year ): array {
		$table = $this->table();
		$query = "SELECT * FROM {$table} WHERE user_id = %d AND year = %d ORDER BY start_date ASC, id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $user_id, $year ), ARRAY_A );
		return is_array( $rows ) ? array_map( array( __CLASS__, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Búsqueda paginada con filtros (estado, usuario, rango, tipo).
	 *
	 * @param array<string,mixed> $filters Filtros opcionales: user_id, status,
	 *                                     type, from (DateTimeImmutable), to,
	 *                                     user_ids (array<int>).
	 * @param int                 $limit   Tamaño.
	 * @param int                 $offset  Offset.
	 * @return VacationRequest[]
	 */
	public function search( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		[ $where_sql, $params ] = $this->build_where( $filters );
		$table                  = $this->table();
		$query                  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY start_date DESC, id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[]               = max( 1, min( 1000, $limit ) );
		$params[]               = max( 0, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $params ), ARRAY_A );
		return is_array( $rows ) ? array_map( array( __CLASS__, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Cuenta filas para los mismos filtros (paginación admin).
	 *
	 * @param array<string,mixed> $filters Filtros.
	 * @return int
	 */
	public function count( array $filters = array() ): int {
		[ $where_sql, $params ] = $this->build_where( $filters );
		$table                  = $this->table();
		$query                  = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $this->wpdb->get_var( $query );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $query, $params ) );
	}

	/**
	 * Solicitudes activas (pending/approved) que solapen con un rango para
	 * un usuario (para detección de duplicados).
	 *
	 * @param int                $user_id    Usuario.
	 * @param \DateTimeImmutable $from       Desde.
	 * @param \DateTimeImmutable $to         Hasta.
	 * @param int|null           $exclude_id Si edición, id a excluir.
	 * @return VacationRequest[]
	 */
	public function find_active_overlapping( int $user_id, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $exclude_id = null ): array {
		$table       = $this->table();
		$exclude_sql = '';
		// Overlap entre [start_date, end_date] y [from, to] ⇒ start_date <= to AND end_date >= from.
		$params = array( $user_id, $to->format( 'Y-m-d' ), $from->format( 'Y-m-d' ) );
		if ( null !== $exclude_id ) {
			$exclude_sql = ' AND id <> %d';
			$params[]    = $exclude_id;
		}
		$query = "SELECT * FROM {$table} WHERE user_id = %d AND status IN ('pending','approved') AND start_date <= %s AND end_date >= %s{$exclude_sql} ORDER BY start_date ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $params ), ARRAY_A );
		return is_array( $rows ) ? array_map( array( __CLASS__, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Suma de días por status para un (user, year) — para reconciliación
	 * del saldo materializado.
	 *
	 * Devuelve array status => days (sólo APPROVED y PENDING tienen interés).
	 *
	 * @param int $user_id Usuario.
	 * @param int $year    Año.
	 * @return array<string, float>
	 */
	public function sum_days_by_status( int $user_id, int $year ): array {
		$table = $this->table();
		$type  = RequestType::VACATION->value;
		$query = "SELECT status, SUM(requested_days) AS days FROM {$table} WHERE user_id = %d AND year = %d AND type = %s GROUP BY status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $user_id, $year, $type ), ARRAY_A );
		$out  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (string) $row['status'] ] = (float) $row['days'];
			}
		}
		return $out;
	}

	/**
	 * Inserta una solicitud y devuelve su id.
	 *
	 * @param VacationRequest $req Solicitud.
	 * @return int
	 *
	 * @throws \RuntimeException Si el insert falla.
	 */
	public function insert_request( VacationRequest $req ): int {
		$now     = current_time( 'mysql' );
		$data    = array(
			'user_id'        => $req->user_id,
			'year'           => $req->year,
			'type'           => $req->type->value,
			'start_date'     => $req->start_date->format( 'Y-m-d' ),
			'end_date'       => $req->end_date->format( 'Y-m-d' ),
			'start_half_day' => $req->start_half_day ? 1 : 0,
			'end_half_day'   => $req->end_half_day ? 1 : 0,
			'requested_days' => $req->requested_days,
			'status'         => $req->status->value,
			'reason'         => $req->reason,
			'decided_by'     => $req->decided_by,
			'decided_at'     => null === $req->decided_at ? null : $req->decided_at->format( 'Y-m-d H:i:s' ),
			'decision_note'  => $req->decision_note,
			'cancelled_at'   => null === $req->cancelled_at ? null : $req->cancelled_at->format( 'Y-m-d H:i:s' ),
			'created_at'     => $now,
			'updated_at'     => $now,
		);
		$formats = array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		$ok = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $ok ) {
			throw new \RuntimeException( sprintf( 'VacationRequestRepository::insert — failed: %s', esc_html( (string) $this->wpdb->last_error ) ) );
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Actualiza una solicitud existente.
	 *
	 * @param int                 $id      PK.
	 * @param array<string,mixed> $changes Cambios.
	 * @return bool true si afectó alguna fila.
	 */
	public function update_request( int $id, array $changes ): bool {
		$allowed = array(
			'user_id'        => '%d',
			'year'           => '%d',
			'type'           => '%s',
			'start_date'     => '%s',
			'end_date'       => '%s',
			'start_half_day' => '%d',
			'end_half_day'   => '%d',
			'requested_days' => '%f',
			'status'         => '%s',
			'reason'         => '%s',
			'decided_by'     => '%d',
			'decided_at'     => '%s',
			'decision_note'  => '%s',
			'cancelled_at'   => '%s',
		);
		$data    = array();
		$formats = array();
		foreach ( $allowed as $col => $fmt ) {
			if ( array_key_exists( $col, $changes ) ) {
				$data[ $col ] = $changes[ $col ];
				$formats[]    = $fmt;
			}
		}
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = current_time( 'mysql' );
		$formats[]          = '%s';
		$rows               = $this->wpdb->update( $this->table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
		return is_int( $rows ) && $rows > 0;
	}

	/**
	 * Elimina por PK.
	 *
	 * @param int $id PK.
	 * @return bool
	 */
	public function delete_by_id( int $id ): bool {
		$ok = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $ok && $ok > 0;
	}

	/**
	 * Hidrata fila → DTO.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return VacationRequest
	 */
	private static function hydrate( array $row ): VacationRequest {
		$tz   = wp_timezone();
		$type = RequestType::from_db( (string) $row['type'] ) ?? RequestType::VACATION;
		$st   = RequestStatus::from_db( (string) $row['status'] ) ?? RequestStatus::PENDING;
		return new VacationRequest(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(int) $row['user_id'],
			(int) $row['year'],
			$type,
			( new \DateTimeImmutable( (string) $row['start_date'], $tz ) )->setTime( 0, 0, 0 ),
			( new \DateTimeImmutable( (string) $row['end_date'], $tz ) )->setTime( 0, 0, 0 ),
			(bool) (int) ( $row['start_half_day'] ?? 0 ),
			(bool) (int) ( $row['end_half_day'] ?? 0 ),
			(float) $row['requested_days'],
			$st,
			isset( $row['reason'] ) && '' !== $row['reason'] ? (string) $row['reason'] : null,
			isset( $row['decided_by'] ) && null !== $row['decided_by'] ? (int) $row['decided_by'] : null,
			self::date_or_null( $row['decided_at'] ?? null, $tz ),
			isset( $row['decision_note'] ) && '' !== $row['decision_note'] ? (string) $row['decision_note'] : null,
			self::date_or_null( $row['cancelled_at'] ?? null, $tz ),
			self::date_or_null( $row['created_at'] ?? null, $tz ),
			self::date_or_null( $row['updated_at'] ?? null, $tz )
		);
	}

	/**
	 * Construye el WHERE para search/count.
	 *
	 * @param array<string,mixed> $filters Filtros.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_where( array $filters ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( isset( $filters['user_id'] ) && $filters['user_id'] > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( isset( $filters['user_ids'] ) && is_array( $filters['user_ids'] ) && ! empty( $filters['user_ids'] ) ) {
			$ids          = array_values( array_unique( array_map( 'intval', $filters['user_ids'] ) ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$where[]      = 'user_id IN (' . $placeholders . ')';
			foreach ( $ids as $id ) {
				$params[] = $id;
			}
		}
		if ( isset( $filters['status'] ) && '' !== (string) $filters['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( isset( $filters['type'] ) && '' !== (string) $filters['type'] ) {
			$where[]  = 'type = %s';
			$params[] = (string) $filters['type'];
		}
		if ( isset( $filters['from'] ) && $filters['from'] instanceof \DateTimeImmutable ) {
			$where[]  = 'end_date >= %s';
			$params[] = $filters['from']->format( 'Y-m-d' );
		}
		if ( isset( $filters['to'] ) && $filters['to'] instanceof \DateTimeImmutable ) {
			$where[]  = 'start_date <= %s';
			$params[] = $filters['to']->format( 'Y-m-d' );
		}
		if ( isset( $filters['year'] ) && $filters['year'] > 0 ) {
			$where[]  = 'year = %d';
			$params[] = (int) $filters['year'];
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Convierte cadena DATETIME a DateTimeImmutable o null.
	 *
	 * @param mixed         $val Valor crudo.
	 * @param \DateTimeZone $tz  Timezone.
	 * @return \DateTimeImmutable|null
	 */
	private static function date_or_null( $val, \DateTimeZone $tz ): ?\DateTimeImmutable {
		if ( null === $val || '' === $val ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( (string) $val, $tz );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
