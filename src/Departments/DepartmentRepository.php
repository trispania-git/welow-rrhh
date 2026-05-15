<?php
/**
 * Repositorio de departamentos (welow_departments).
 *
 * @package Welow\RRHH\Departments
 */

declare( strict_types=1 );

namespace Welow\RRHH\Departments;

use Welow\RRHH\Database\Repository\AbstractRepository;
use Welow\RRHH\Support\Data\Department;

defined( 'ABSPATH' ) || exit;

/**
 * Repositorio CRUD de departamentos.
 */
final class DepartmentRepository extends AbstractRepository {

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->wpdb->prefix . 'welow_departments';
	}

	/**
	 * Recupera un departamento por PK.
	 *
	 * @param int $id Identificador.
	 * @return Department|null
	 */
	public function find_by_id( int $id ): ?Department {
		$row = parent::find( $id );
		return null === $row ? null : self::hydrate( $row );
	}

	/**
	 * Recupera un departamento por slug.
	 *
	 * @param string $slug Slug.
	 * @return Department|null
	 */
	public function find_by_slug( string $slug ): ?Department {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Devuelve todos los departamentos ordenados por nombre.
	 *
	 * @return Department[]
	 */
	public function find_all(): array {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Cuenta el número de empleados asignados a un departamento.
	 *
	 * @param int $department_id Identificador del departamento.
	 * @return int
	 */
	public function count_employees( int $department_id ): int {
		$employees_table = $this->wpdb->prefix . 'welow_employees';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare( "SELECT COUNT(*) FROM {$employees_table} WHERE department_id = %d", $department_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Inserta un nuevo departamento.
	 *
	 * @param Department $department DTO.
	 * @return int ID insertado.
	 *
	 * @throws \RuntimeException Si insert falla.
	 */
	public function create( Department $department ): int {
		$now     = current_time( 'mysql' );
		$data    = array(
			'name'            => $department->name,
			'slug'            => $department->slug,
			'parent_id'       => $department->parent_id,
			'manager_user_id' => $department->manager_user_id,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$formats = array( '%s', '%s', '%d', '%d', '%s', '%s' );

		$ok = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $ok ) {
			throw new \RuntimeException(
				sprintf( 'DepartmentRepository::create — insert failed: %s', esc_html( (string) $this->wpdb->last_error ) )
			);
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Actualiza un departamento existente por id.
	 *
	 * @param int                  $id      Identificador.
	 * @param array<string, mixed> $changes Cambios.
	 * @return bool true si se cambió algo.
	 *
	 * @throws \RuntimeException Si update devuelve false.
	 */
	public function update_changes( int $id, array $changes ): bool {
		if ( empty( $changes ) ) {
			return false;
		}

		$allowed = array(
			'name'            => '%s',
			'slug'            => '%s',
			'parent_id'       => '%d',
			'manager_user_id' => '%d',
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
				sprintf( 'DepartmentRepository::update — update failed: %s', esc_html( (string) $this->wpdb->last_error ) )
			);
		}
		return $result > 0;
	}

	/**
	 * Elimina un departamento por id.
	 *
	 * @param int $id Identificador.
	 * @return bool
	 */
	public function delete_by_id( int $id ): bool {
		$result = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result && $result > 0;
	}

	/**
	 * Convierte una fila de BD en DTO.
	 *
	 * @param array<string, mixed> $row Fila cruda.
	 * @return Department
	 */
	private static function hydrate( array $row ): Department {
		$created = isset( $row['created_at'] )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $row['created_at'] )
			: false;
		$updated = isset( $row['updated_at'] )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $row['updated_at'] )
			: false;

		return new Department(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(string) ( $row['name'] ?? '' ),
			(string) ( $row['slug'] ?? '' ),
			isset( $row['parent_id'] ) && null !== $row['parent_id'] ? (int) $row['parent_id'] : null,
			isset( $row['manager_user_id'] ) && null !== $row['manager_user_id'] ? (int) $row['manager_user_id'] : null,
			false === $created ? null : $created,
			false === $updated ? null : $updated
		);
	}
}
