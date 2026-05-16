<?php
/**
 * Repositorio del saldo anual de vacaciones (welow_vacation_balances).
 *
 * Mantiene una fila por (user_id, año). El BalanceCalculator (10.B) usará
 * este repositorio como cache y reconciliará periódicamente con la suma de
 * solicitudes APPROVED del año.
 *
 * @package Welow\RRHH\Modules\Vacations\Repository
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Repository;

use Welow\RRHH\Database\Repository\AbstractRepository;
use Welow\RRHH\Modules\Vacations\Data\VacationBalance;

defined( 'ABSPATH' ) || exit;

/**
 * VacationBalanceRepository.
 */
final class VacationBalanceRepository extends AbstractRepository {

	/**
	 * Tabla.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->wpdb->prefix . 'welow_vacation_balances';
	}

	/**
	 * Recupera el saldo de un (user, año) o null si no existe.
	 *
	 * @param int $user_id Usuario.
	 * @param int $year    Año.
	 * @return VacationBalance|null
	 */
	public function find_for_user_year( int $user_id, int $year ): ?VacationBalance {
		$table = $this->table();
		$query = "SELECT * FROM {$table} WHERE user_id = %d AND year = %d LIMIT 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $query, $user_id, $year ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Lista los saldos de un usuario ordenados por año desc.
	 *
	 * @param int $user_id Usuario.
	 * @return VacationBalance[]
	 */
	public function find_all_for_user( int $user_id ): array {
		$table = $this->table();
		$query = "SELECT * FROM {$table} WHERE user_id = %d ORDER BY year DESC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $user_id ), ARRAY_A );
		return is_array( $rows ) ? array_map( array( __CLASS__, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Crea o actualiza el saldo (upsert por uk_user_year).
	 *
	 * @param VacationBalance $bal Saldo.
	 * @return int Id de la fila persistida (existente o nueva).
	 *
	 * @throws \RuntimeException Si la operación falla.
	 */
	public function upsert( VacationBalance $bal ): int {
		$existing = $this->find_for_user_year( $bal->user_id, $bal->year );
		$now      = current_time( 'mysql' );
		$data     = array(
			'user_id'                => $bal->user_id,
			'year'                   => $bal->year,
			'accrued'                => $bal->accrued,
			'used'                   => $bal->used,
			'carried_over_from_prev' => $bal->carried_over_from_prev,
			'carry_over_expires_at'  => null === $bal->carry_over_expires_at ? null : $bal->carry_over_expires_at->format( 'Y-m-d' ),
			'updated_at'             => $now,
		);
		$formats  = array( '%d', '%d', '%f', '%f', '%f', '%s', '%s' );

		if ( null === $existing ) {
			$ok = $this->wpdb->insert( $this->table(), $data, $formats );
			if ( false === $ok ) {
				throw new \RuntimeException( sprintf( 'VacationBalanceRepository::insert — failed: %s', esc_html( (string) $this->wpdb->last_error ) ) );
			}
			return (int) $this->wpdb->insert_id;
		}

		$rows = $this->wpdb->update(
			$this->table(),
			$data,
			array( 'id' => (int) $existing->id ),
			$formats,
			array( '%d' )
		);
		if ( false === $rows ) {
			throw new \RuntimeException( sprintf( 'VacationBalanceRepository::update — failed: %s', esc_html( (string) $this->wpdb->last_error ) ) );
		}
		return (int) $existing->id;
	}

	/**
	 * Borra el saldo de un (user, año). Uso administrativo (reset).
	 *
	 * @param int $user_id Usuario.
	 * @param int $year    Año.
	 * @return bool
	 */
	public function delete_for_user_year( int $user_id, int $year ): bool {
		$ok = $this->wpdb->delete(
			$this->table(),
			array(
				'user_id' => $user_id,
				'year'    => $year,
			),
			array( '%d', '%d' )
		);
		return false !== $ok && $ok > 0;
	}

	/**
	 * Hidrata fila → DTO.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return VacationBalance
	 */
	private static function hydrate( array $row ): VacationBalance {
		$tz = wp_timezone();
		return new VacationBalance(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(int) $row['user_id'],
			(int) $row['year'],
			(float) $row['accrued'],
			(float) $row['used'],
			(float) $row['carried_over_from_prev'],
			isset( $row['carry_over_expires_at'] ) && '' !== (string) $row['carry_over_expires_at']
				? ( new \DateTimeImmutable( (string) $row['carry_over_expires_at'], $tz ) )->setTime( 0, 0, 0 )
				: null,
			isset( $row['updated_at'] ) && '' !== (string) $row['updated_at']
				? new \DateTimeImmutable( (string) $row['updated_at'], $tz )
				: null
		);
	}
}
