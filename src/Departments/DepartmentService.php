<?php
/**
 * Servicio de departamentos.
 *
 * Centraliza validación + unicidad + control de ciclos en `parent_id` y
 * auditoría de los cambios.
 *
 * @package Welow\RRHH\Departments
 */

declare( strict_types=1 );

namespace Welow\RRHH\Departments;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Support\Data\Department;

defined( 'ABSPATH' ) || exit;

/**
 * Operaciones de alto nivel sobre departamentos.
 */
final class DepartmentService {

	private const AUDIT_ENTITY = 'department';

	/**
	 * Repositorio.
	 *
	 * @var DepartmentRepository
	 */
	private DepartmentRepository $repository;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param DepartmentRepository $repository Repositorio.
	 * @param AuditLogger          $audit      Audit logger.
	 */
	public function __construct( DepartmentRepository $repository, AuditLogger $audit ) {
		$this->repository = $repository;
		$this->audit      = $audit;
	}

	/**
	 * Acceso al repositorio (útil para componentes admin).
	 *
	 * @return DepartmentRepository
	 */
	public function repository(): DepartmentRepository {
		return $this->repository;
	}

	/**
	 * Crea un departamento.
	 *
	 * @param array<string, mixed> $data Datos (name, slug?, parent_id?, manager_user_id?).
	 * @return Department|\WP_Error
	 */
	public function create( array $data ) {
		$errors = self::validate( $data );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$name      = sanitize_text_field( (string) $data['name'] );
		$slug      = self::resolve_slug( $data, $name );
		$parent_id = self::normalize_nullable_int( $data['parent_id'] ?? null );
		$manager   = self::normalize_nullable_int( $data['manager_user_id'] ?? null );

		if ( null !== $this->repository->find_by_slug( $slug ) ) {
			$err = new \WP_Error();
			$err->add( 'slug', __( 'Ya existe un departamento con ese slug.', 'welow-rrhh' ) );
			return $err;
		}

		$dto = new Department( null, $name, $slug, $parent_id, $manager );

		try {
			$id = $this->repository->create( $dto );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_department_create_failed', $e->getMessage() );
		}

		$created = $this->repository->find_by_id( $id );
		if ( null === $created ) {
			return new \WP_Error( 'welow_department_lookup_failed', __( 'Departamento creado pero no recuperable.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'create',
			self::AUDIT_ENTITY,
			$id,
			array(
				'name' => $name,
				'slug' => $slug,
			)
		);

		return $created;
	}

	/**
	 * Actualiza un departamento.
	 *
	 * @param int                  $id   Identificador.
	 * @param array<string, mixed> $data Cambios.
	 * @return Department|\WP_Error
	 */
	public function update( int $id, array $data ) {
		$current = $this->repository->find_by_id( $id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_department_not_found', __( 'Departamento no encontrado.', 'welow-rrhh' ) );
		}

		$errors = self::validate( $data, $current );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$changes = array();
		if ( array_key_exists( 'name', $data ) ) {
			$changes['name'] = sanitize_text_field( (string) $data['name'] );
		}
		if ( array_key_exists( 'slug', $data ) || array_key_exists( 'name', $data ) ) {
			$proposed = self::resolve_slug( $data, $changes['name'] ?? $current->name );
			if ( $proposed !== $current->slug ) {
				$colliding = $this->repository->find_by_slug( $proposed );
				if ( null !== $colliding && $colliding->id !== $current->id ) {
					$err = new \WP_Error();
					$err->add( 'slug', __( 'Ya existe un departamento con ese slug.', 'welow-rrhh' ) );
					return $err;
				}
				$changes['slug'] = $proposed;
			}
		}
		if ( array_key_exists( 'parent_id', $data ) ) {
			$new_parent = self::normalize_nullable_int( $data['parent_id'] );
			if ( null !== $new_parent && $this->would_create_cycle( $current->id, $new_parent ) ) {
				$err = new \WP_Error();
				$err->add( 'parent_id', __( 'La jerarquía generaría un ciclo.', 'welow-rrhh' ) );
				return $err;
			}
			$changes['parent_id'] = $new_parent;
		}
		if ( array_key_exists( 'manager_user_id', $data ) ) {
			$changes['manager_user_id'] = self::normalize_nullable_int( $data['manager_user_id'] );
		}

		if ( ! empty( $changes ) ) {
			try {
				$this->repository->update_changes( $id, $changes );
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'welow_department_update_failed', $e->getMessage() );
			}
		}

		$updated = $this->repository->find_by_id( $id );
		$this->audit->log(
			'update',
			self::AUDIT_ENTITY,
			$id,
			array(
				'before' => array(
					'name' => $current->name,
					'slug' => $current->slug,
				),
				'after'  => $changes,
			)
		);

		return $updated ?? $current;
	}

	/**
	 * Elimina un departamento. Falla si tiene empleados asignados.
	 *
	 * @param int $id Identificador.
	 * @return true|\WP_Error
	 */
	public function delete( int $id ) {
		$current = $this->repository->find_by_id( $id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_department_not_found', __( 'Departamento no encontrado.', 'welow-rrhh' ) );
		}

		$emp_count = $this->repository->count_employees( $id );
		if ( $emp_count > 0 ) {
			return new \WP_Error(
				'welow_department_has_employees',
				sprintf(
					/* translators: %d: number of employees. */
					_n(
						'No se puede eliminar: el departamento tiene %d empleado asignado.',
						'No se puede eliminar: el departamento tiene %d empleados asignados.',
						$emp_count,
						'welow-rrhh'
					),
					$emp_count
				)
			);
		}

		if ( ! $this->repository->delete_by_id( $id ) ) {
			return new \WP_Error( 'welow_department_delete_failed', __( 'No se pudo eliminar el departamento.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'delete',
			self::AUDIT_ENTITY,
			$id,
			array(
				'name' => $current->name,
				'slug' => $current->slug,
			)
		);

		return true;
	}

	/**
	 * Validación de campos.
	 *
	 * @param array<string, mixed> $data    Datos.
	 * @param Department|null      $current Departamento actual (en update).
	 * @return \WP_Error
	 */
	private static function validate( array $data, ?Department $current = null ): \WP_Error {
		$errors = new \WP_Error();

		$is_create = null === $current;

		if ( $is_create || array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
			if ( '' === $name ) {
				$errors->add( 'name', __( 'El nombre del departamento es obligatorio.', 'welow-rrhh' ) );
			}
		}

		return $errors;
	}

	/**
	 * Resuelve el slug a usar: si viene explícito sanitízalo, si no, genérelo del nombre.
	 *
	 * @param array<string, mixed> $data Datos.
	 * @param string               $name Nombre.
	 * @return string
	 */
	private static function resolve_slug( array $data, string $name ): string {
		if ( ! empty( $data['slug'] ) ) {
			return sanitize_title( (string) $data['slug'] );
		}
		return sanitize_title( $name );
	}

	/**
	 * Normaliza un id opcional (string/int → int|null).
	 *
	 * @param mixed $value Valor.
	 * @return int|null
	 */
	private static function normalize_nullable_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}

	/**
	 * Detecta si establecer $new_parent como padre de $department_id crearía un ciclo.
	 *
	 * @param int|null $department_id ID del departamento a actualizar.
	 * @param int      $new_parent_id Nuevo padre propuesto.
	 * @return bool
	 */
	private function would_create_cycle( ?int $department_id, int $new_parent_id ): bool {
		if ( null === $department_id ) {
			return false;
		}
		if ( $department_id === $new_parent_id ) {
			return true;
		}
		$visited = array();
		$current = $new_parent_id;
		while ( null !== $current && ! in_array( $current, $visited, true ) ) {
			if ( $current === $department_id ) {
				return true;
			}
			$visited[] = $current;
			$parent    = $this->repository->find_by_id( $current );
			$current   = null !== $parent ? $parent->parent_id : null;
		}
		return false;
	}
}
