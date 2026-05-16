<?php
/**
 * Repositorio de festivos (welow_holidays).
 *
 * @package Welow\RRHH\Holidays
 */

declare( strict_types=1 );

namespace Welow\RRHH\Holidays;

use Welow\RRHH\Database\Repository\AbstractRepository;
use Welow\RRHH\Support\Data\Holiday;
use Welow\RRHH\Support\Data\HolidayScope;

defined( 'ABSPATH' ) || exit;

/**
 * Repositorio CRUD de festivos.
 */
final class HolidayRepository extends AbstractRepository {

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->wpdb->prefix . 'welow_holidays';
	}

	/**
	 * Recupera un festivo por PK.
	 *
	 * @param int $id Identificador.
	 * @return Holiday|null
	 */
	public function find_by_id( int $id ): ?Holiday {
		$row = parent::find( $id );
		return null === $row ? null : self::hydrate( $row );
	}

	/**
	 * Recupera un festivo por (fecha, scope) — claves del UNIQUE.
	 *
	 * @param string       $date  Fecha YYYY-MM-DD.
	 * @param HolidayScope $scope Ámbito.
	 * @return Holiday|null
	 */
	public function find_by_date_scope( string $date, HolidayScope $scope ): ?Holiday {
		$table = $this->table();
		// $table es controlado internamente; $date y $scope se pasan como placeholders.
		$query = "SELECT * FROM {$table} WHERE holiday_date = %s AND scope = %s LIMIT 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $query, $date, $scope->value ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Búsqueda paginada con filtros opcionales por año y ámbito.
	 *
	 * @param int|null    $year     Año (opcional).
	 * @param string|null $scope    Slug del scope (opcional).
	 * @param int         $page     Página (1-indexed).
	 * @param int         $per_page Tamaño de página.
	 * @return array{items: Holiday[], total: int}
	 */
	public function search( ?int $year = null, ?string $scope = null, int $page = 1, int $per_page = 50 ): array {
		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();
		if ( null !== $year && $year > 0 ) {
			$where[]  = 'year = %d';
			$params[] = $year;
		}
		if ( null !== $scope && '' !== $scope ) {
			$where[]  = 'scope = %s';
			$params[] = $scope;
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = empty( $params )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			? (int) $this->wpdb->get_var( $count_sql )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			: (int) $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, $params ) );

		$page     = max( 1, $page );
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY holiday_date ASC LIMIT %d OFFSET %d";
		$params_with_lo = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $this->wpdb->get_results( $this->wpdb->prepare( $rows_sql, $params_with_lo ), ARRAY_A );
		$items = is_array( $rows ) ? array_map( array( __CLASS__, 'hydrate' ), $rows ) : array();

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Devuelve las fechas (YYYY-MM-DD) con festivos en un rango,
	 * opcionalmente filtrando por scopes.
	 *
	 * Pensado para sumadores que sólo necesitan saber qué días son festivos
	 * (no el detalle de cada Holiday). Mucho más barato que paginar.
	 *
	 * @param \DateTimeImmutable $from   Desde (inclusive).
	 * @param \DateTimeImmutable $to     Hasta (inclusive).
	 * @param string[]           $scopes Scopes a incluir; vacío = todos.
	 * @return string[] Lista de fechas únicas YYYY-MM-DD.
	 */
	public function find_dates_in_range( \DateTimeImmutable $from, \DateTimeImmutable $to, array $scopes = array() ): array {
		$table  = $this->table();
		$where  = array( 'holiday_date >= %s', 'holiday_date <= %s' );
		$params = array( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
		$scopes = array_values( array_unique( array_filter( array_map( 'strval', $scopes ) ) ) );
		if ( ! empty( $scopes ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $scopes ), '%s' ) );
			$where[]      = 'scope IN (' . $placeholders . ')';
			foreach ( $scopes as $s ) {
				$params[] = $s;
			}
		}
		$where_sql = implode( ' AND ', $where );
		$query     = "SELECT DISTINCT holiday_date FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dates = $this->wpdb->get_col( $this->wpdb->prepare( $query, $params ) );
		return is_array( $dates ) ? array_map( 'strval', $dates ) : array();
	}

	/**
	 * Devuelve los años distintos con festivos registrados (para el filtro).
	 *
	 * @return int[]
	 */
	public function distinct_years(): array {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$years = $this->wpdb->get_col( "SELECT DISTINCT year FROM {$table} ORDER BY year DESC" );
		return is_array( $years ) ? array_map( 'intval', $years ) : array();
	}

	/**
	 * Inserta un nuevo festivo.
	 *
	 * @param Holiday $holiday DTO.
	 * @return int ID insertado.
	 *
	 * @throws \RuntimeException Si insert falla.
	 */
	public function create( Holiday $holiday ): int {
		$now     = current_time( 'mysql' );
		$data    = array(
			'holiday_date' => $holiday->date->format( 'Y-m-d' ),
			'name'         => $holiday->name,
			'scope'        => $holiday->scope->value,
			'year'         => $holiday->year,
			'created_at'   => $now,
			'updated_at'   => $now,
		);
		$formats = array( '%s', '%s', '%s', '%d', '%s', '%s' );

		$ok = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $ok ) {
			throw new \RuntimeException(
				sprintf( 'HolidayRepository::create — insert failed: %s', esc_html( (string) $this->wpdb->last_error ) )
			);
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Actualiza un festivo existente por id.
	 *
	 * @param int                  $id      Identificador.
	 * @param array<string, mixed> $changes Cambios.
	 * @return bool
	 *
	 * @throws \RuntimeException Si update devuelve false.
	 */
	public function update_changes( int $id, array $changes ): bool {
		if ( empty( $changes ) ) {
			return false;
		}

		$allowed = array(
			'holiday_date' => '%s',
			'name'         => '%s',
			'scope'        => '%s',
			'year'         => '%d',
		);

		$data    = array();
		$formats = array();
		foreach ( $allowed as $col => $format ) {
			if ( array_key_exists( $col, $changes ) ) {
				$data[ $col ] = $changes[ $col ];
				$formats[]    = $format;
			}
		}
		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql' );
		$formats[]          = '%s';

		$result = $this->wpdb->update( $this->table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf( 'HolidayRepository::update — update failed: %s', esc_html( (string) $this->wpdb->last_error ) )
			);
		}
		return $result > 0;
	}

	/**
	 * Elimina un festivo por id.
	 *
	 * @param int $id Identificador.
	 * @return bool
	 */
	public function delete_by_id( int $id ): bool {
		$result = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result && $result > 0;
	}

	/**
	 * Convierte una fila a DTO.
	 *
	 * @param array<string, mixed> $row Fila cruda.
	 * @return Holiday
	 */
	private static function hydrate( array $row ): Holiday {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) ( $row['holiday_date'] ?? '' ) );
		if ( false === $date ) {
			$date = new \DateTimeImmutable( 'today' );
		}
		$created = isset( $row['created_at'] )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $row['created_at'] )
			: false;
		$updated = isset( $row['updated_at'] )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $row['updated_at'] )
			: false;

		return new Holiday(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			$date,
			(string) ( $row['name'] ?? '' ),
			HolidayScope::from_db( isset( $row['scope'] ) ? (string) $row['scope'] : null ),
			(int) ( $row['year'] ?? (int) $date->format( 'Y' ) ),
			false === $created ? null : $created,
			false === $updated ? null : $updated
		);
	}
}
