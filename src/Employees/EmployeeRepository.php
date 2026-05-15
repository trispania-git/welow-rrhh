<?php
/**
 * Repositorio de empleados (welow_employees).
 *
 * Encapsula todo acceso a $wpdb sobre la tabla, gestiona el cifrado/
 * descifrado de `dni_nie` (vía Security\Crypto) y el cálculo del hash de
 * lookup (`dni_nie_hash`). Hidrata filas a DTOs `Employee` inmutables.
 *
 * @package Welow\RRHH\Employees
 */

declare( strict_types=1 );

namespace Welow\RRHH\Employees;

use Welow\RRHH\Database\Repository\AbstractRepository;
use Welow\RRHH\Security\Crypto;
use Welow\RRHH\Support\Data\Employee;
use Welow\RRHH\Support\Data\EmployeeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Repositorio CRUD de empleados.
 */
final class EmployeeRepository extends AbstractRepository {

	/**
	 * Nombre completo de la tabla con prefijo.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->wpdb->prefix . 'welow_employees';
	}

	/**
	 * Recupera un empleado por su PK.
	 *
	 * @param int $id Identificador.
	 * @return Employee|null
	 */
	public function find_by_id( int $id ): ?Employee {
		$row = parent::find( $id );
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Recupera un empleado por el ID del WP_User vinculado.
	 *
	 * @param int $user_id wp_users.ID.
	 * @return Employee|null
	 */
	public function find_by_user_id( int $user_id ): ?Employee {
		return $this->find_one_by( 'user_id', $user_id, '%d' );
	}

	/**
	 * Recupera un empleado a partir del DNI/NIE en texto plano (vía hash).
	 *
	 * @param string $dni_plain DNI en plano (ya validado).
	 * @return Employee|null
	 */
	public function find_by_dni( string $dni_plain ): ?Employee {
		return $this->find_one_by( 'dni_nie_hash', Crypto::lookup_hash( $dni_plain ), '%s' );
	}

	/**
	 * Recupera por código interno.
	 *
	 * @param string $code Código.
	 * @return Employee|null
	 */
	public function find_by_code( string $code ): ?Employee {
		return $this->find_one_by( 'employee_code', $code, '%s' );
	}

	/**
	 * Búsqueda paginada con filtros.
	 *
	 * Filtros soportados (todos opcionales):
	 *   - status:        EmployeeStatus|string
	 *   - department_id: int
	 *   - manager_user_id: int
	 *   - search:        string (busca en first_name, last_name, employee_code, position)
	 *
	 * @param array{status?: mixed, department_id?: int, manager_user_id?: int, search?: string} $criteria Filtros.
	 * @param int                                                                                $page     Página (1-indexed).
	 * @param int                                                                                $per_page Tamaño de página.
	 * @return array{items: Employee[], total: int}
	 */
	public function search( array $criteria = array(), int $page = 1, int $per_page = 20 ): array {
		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		if ( isset( $criteria['status'] ) ) {
			$status   = $criteria['status'] instanceof EmployeeStatus
				? $criteria['status']->value
				: (string) $criteria['status'];
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( ! empty( $criteria['department_id'] ) ) {
			$where[]  = 'department_id = %d';
			$params[] = (int) $criteria['department_id'];
		}

		if ( ! empty( $criteria['manager_user_id'] ) ) {
			$where[]  = 'manager_user_id = %d';
			$params[] = (int) $criteria['manager_user_id'];
		}

		if ( ! empty( $criteria['search'] ) ) {
			$like     = '%' . $this->wpdb->esc_like( (string) $criteria['search'] ) . '%';
			$where[]  = '( first_name LIKE %s OR last_name LIKE %s OR employee_code LIKE %s OR position LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// Total.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = empty( $params )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			? (int) $this->wpdb->get_var( $count_sql )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			: (int) $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, $params ) );

		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY last_name, first_name LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $rows_sql, $params ), ARRAY_A );

		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = $this->hydrate( $row );
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Inserta un nuevo empleado.
	 *
	 * @param Employee $employee DTO. Su `id` se ignora; se devuelve el insertado.
	 * @return int Nuevo id.
	 *
	 * @throws \RuntimeException Si la inserción falla.
	 */
	public function create( Employee $employee ): int {
		$data = $this->dehydrate( $employee );
		unset( $data['id'] );

		$now                = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$ok = $this->wpdb->insert( $this->table(), $data, $this->placeholders_for( $data ) );
		if ( false === $ok ) {
			throw new \RuntimeException(
				sprintf( 'EmployeeRepository::create — insert failed: %s', esc_html( (string) $this->wpdb->last_error ) )
			);
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Actualiza un empleado existente (por id).
	 *
	 * @param int                  $id      Identificador.
	 * @param array<string, mixed> $changes Mapa de campos a actualizar. Los campos no presentes se conservan.
	 * @return bool true si se modificó algo.
	 *
	 * @throws \RuntimeException Si update devuelve false.
	 */
	public function update_changes( int $id, array $changes ): bool {
		if ( empty( $changes ) ) {
			return false;
		}

		$prepared = $this->prepare_changes_for_db( $changes );
		if ( empty( $prepared ) ) {
			return false;
		}

		$prepared['updated_at'] = current_time( 'mysql' );

		$result = $this->wpdb->update(
			$this->table(),
			$prepared,
			array( 'id' => $id ),
			$this->placeholders_for( $prepared ),
			array( '%d' )
		);

		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf( 'EmployeeRepository::update — update failed: %s', esc_html( (string) $this->wpdb->last_error ) )
			);
		}

		return $result > 0;
	}

	/**
	 * Elimina un empleado por id. No borra el WP_User asociado.
	 *
	 * @param int $id Identificador.
	 * @return bool true si se eliminó alguna fila.
	 */
	public function delete_by_id( int $id ): bool {
		$result = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result && $result > 0;
	}

	/**
	 * Recupera un único registro filtrando por una columna escalar.
	 *
	 * @param string $column Nombre de columna (controlado internamente).
	 * @param mixed  $value  Valor.
	 * @param string $format %s o %d.
	 * @return Employee|null
	 */
	private function find_one_by( string $column, $value, string $format ): ?Employee {
		$table = $this->table();
		// $column es controlado internamente (no input externo) y $format es uno de %s/%d
		// interpolado para que prepare lo trate como placeholder real con $value.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $this->wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = {$format} LIMIT 1", $value );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Convierte una fila de BD en un DTO Employee (descifra dni_nie).
	 *
	 * @param array<string, mixed> $row Fila cruda.
	 * @return Employee
	 */
	private function hydrate( array $row ): Employee {
		$dni_plain = null;
		if ( ! empty( $row['dni_nie'] ) ) {
			try {
				$dni_plain = Crypto::decrypt( (string) $row['dni_nie'] );
			} catch ( \Throwable $e ) {
				// Si el ciphertext quedó corrupto o la clave cambió, lo dejamos null y dejamos
				// que UI o servicios decidan; loggear para visibilidad.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[Welow RRHH] Error descifrando dni_nie del empleado ' . ( $row['id'] ?? '?' ) . ': ' . $e->getMessage() );
				}
			}
		}

		return new Employee(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(int) ( $row['user_id'] ?? 0 ),
			isset( $row['employee_code'] ) && '' !== $row['employee_code'] ? (string) $row['employee_code'] : null,
			$dni_plain,
			(string) ( $row['first_name'] ?? '' ),
			(string) ( $row['last_name'] ?? '' ),
			isset( $row['department_id'] ) && null !== $row['department_id'] ? (int) $row['department_id'] : null,
			(string) ( $row['position'] ?? '' ),
			isset( $row['manager_user_id'] ) && null !== $row['manager_user_id'] ? (int) $row['manager_user_id'] : null,
			self::to_date( $row['hire_date'] ?? null ),
			self::to_date( $row['termination_date'] ?? null ),
			isset( $row['weekly_hours'] ) && null !== $row['weekly_hours'] ? (float) $row['weekly_hours'] : null,
			isset( $row['vacation_days_override'] ) && null !== $row['vacation_days_override'] ? (int) $row['vacation_days_override'] : null,
			self::decode_json( $row['geo_policy_override'] ?? null ),
			EmployeeStatus::from_db( isset( $row['status'] ) ? (string) $row['status'] : null ),
			self::decode_json( $row['meta'] ?? null ) ?? array(),
			self::to_datetime( $row['created_at'] ?? null ),
			self::to_datetime( $row['updated_at'] ?? null )
		);
	}

	/**
	 * Convierte un DTO Employee a array listo para $wpdb->insert/update.
	 *
	 * @param Employee $e DTO.
	 * @return array<string, mixed>
	 */
	private function dehydrate( Employee $e ): array {
		return array(
			'id'                     => $e->id,
			'user_id'                => $e->user_id,
			'employee_code'          => $e->employee_code,
			'dni_nie'                => null !== $e->dni_nie && '' !== $e->dni_nie ? Crypto::encrypt( $e->dni_nie ) : null,
			'dni_nie_hash'           => null !== $e->dni_nie && '' !== $e->dni_nie ? Crypto::lookup_hash( $e->dni_nie ) : null,
			'first_name'             => $e->first_name,
			'last_name'              => $e->last_name,
			'department_id'          => $e->department_id,
			'position'               => $e->position,
			'manager_user_id'        => $e->manager_user_id,
			'hire_date'              => null !== $e->hire_date ? $e->hire_date->format( 'Y-m-d' ) : null,
			'termination_date'       => null !== $e->termination_date ? $e->termination_date->format( 'Y-m-d' ) : null,
			'weekly_hours'           => $e->weekly_hours,
			'vacation_days_override' => $e->vacation_days_override,
			'geo_policy_override'    => null !== $e->geo_policy_override ? wp_json_encode( $e->geo_policy_override ) : null,
			'status'                 => $e->status->value,
			'meta'                   => empty( $e->meta ) ? null : wp_json_encode( $e->meta ),
		);
	}

	/**
	 * Transforma un mapa parcial de cambios al formato listo para $wpdb->update,
	 * gestionando cifrado, hash y serialización JSON.
	 *
	 * @param array<string, mixed> $changes Cambios crudos.
	 * @return array<string, mixed>
	 */
	private function prepare_changes_for_db( array $changes ): array {
		$out = array();

		$direct = array( 'user_id', 'employee_code', 'first_name', 'last_name', 'department_id', 'position', 'manager_user_id', 'weekly_hours', 'vacation_days_override' );
		foreach ( $direct as $key ) {
			if ( array_key_exists( $key, $changes ) ) {
				$out[ $key ] = $changes[ $key ];
			}
		}

		if ( array_key_exists( 'dni_nie', $changes ) ) {
			$dni = $changes['dni_nie'];
			if ( null === $dni || '' === $dni ) {
				$out['dni_nie']      = null;
				$out['dni_nie_hash'] = null;
			} else {
				$out['dni_nie']      = Crypto::encrypt( (string) $dni );
				$out['dni_nie_hash'] = Crypto::lookup_hash( (string) $dni );
			}
		}

		if ( array_key_exists( 'hire_date', $changes ) ) {
			$out['hire_date'] = $this->date_to_db( $changes['hire_date'] );
		}
		if ( array_key_exists( 'termination_date', $changes ) ) {
			$out['termination_date'] = $this->date_to_db( $changes['termination_date'] );
		}

		if ( array_key_exists( 'geo_policy_override', $changes ) ) {
			$out['geo_policy_override'] = null === $changes['geo_policy_override'] ? null : wp_json_encode( $changes['geo_policy_override'] );
		}

		if ( array_key_exists( 'status', $changes ) ) {
			$status        = $changes['status'];
			$out['status'] = $status instanceof EmployeeStatus ? $status->value : (string) $status;
		}

		if ( array_key_exists( 'meta', $changes ) ) {
			$out['meta'] = empty( $changes['meta'] ) ? null : wp_json_encode( $changes['meta'] );
		}

		return $out;
	}

	/**
	 * Devuelve los placeholders (%d/%s/%f) por columna para $wpdb->insert/update.
	 *
	 * @param array<string, mixed> $data Datos a persistir.
	 * @return array<int, string>
	 */
	private function placeholders_for( array $data ): array {
		$int   = array( 'id', 'user_id', 'department_id', 'manager_user_id', 'vacation_days_override' );
		$float = array( 'weekly_hours' );

		$placeholders = array();
		foreach ( array_keys( $data ) as $col ) {
			if ( in_array( $col, $int, true ) ) {
				$placeholders[] = '%d';
			} elseif ( in_array( $col, $float, true ) ) {
				$placeholders[] = '%f';
			} else {
				$placeholders[] = '%s';
			}
		}
		return $placeholders;
	}

	/**
	 * Convierte un valor a DateTimeImmutable (formato Y-m-d) o null.
	 *
	 * @param mixed $value Valor crudo.
	 * @return \DateTimeImmutable|null
	 */
	private static function to_date( $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value || '0000-00-00' === $value ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );
		return false === $dt ? null : $dt;
	}

	/**
	 * Convierte un valor a DateTimeImmutable (formato Y-m-d H:i:s) o null.
	 *
	 * @param mixed $value Valor crudo.
	 * @return \DateTimeImmutable|null
	 */
	private static function to_datetime( $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $value );
		return false === $dt ? null : $dt;
	}

	/**
	 * Convierte una entrada de fecha (string, DateTimeInterface o null) a string Y-m-d para BD.
	 *
	 * @param mixed $value Valor.
	 * @return string|null
	 */
	private function date_to_db( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d' );
		}
		return (string) $value;
	}

	/**
	 * Decodifica un campo JSON; devuelve null si está vacío o no es JSON.
	 *
	 * @param mixed $value Valor.
	 * @return array<mixed>|null
	 */
	private static function decode_json( $value ): ?array {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
