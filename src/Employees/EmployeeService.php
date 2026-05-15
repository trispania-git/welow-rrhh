<?php
/**
 * Servicio de orquestación de empleados.
 *
 * Centraliza el ciclo de vida (alta vinculada a WP_User, edición, baja
 * lógica, borrado), aplica validaciones de unicidad consultando el
 * repositorio y genera entradas en el audit log para toda mutación.
 *
 * @package Welow\RRHH\Employees
 */

declare( strict_types=1 );

namespace Welow\RRHH\Employees;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Roles\Capabilities;
use Welow\RRHH\Support\Data\Employee;
use Welow\RRHH\Support\Data\EmployeeStatus;
use Welow\RRHH\Support\Validation\Dni;
use Welow\RRHH\Support\Validation\EmployeeValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Operaciones de alto nivel sobre empleados.
 */
final class EmployeeService {

	private const AUDIT_ENTITY = 'employee';

	/**
	 * Repositorio de empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $repository;

	/**
	 * Logger de auditoría.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param EmployeeRepository $repository Repositorio.
	 * @param AuditLogger        $audit      Audit logger.
	 */
	public function __construct( EmployeeRepository $repository, AuditLogger $audit ) {
		$this->repository = $repository;
		$this->audit      = $audit;
	}

	/**
	 * Alta de un empleado nuevo creando simultáneamente su WP_User.
	 *
	 * Opciones soportadas:
	 *   - send_welcome_email (bool, default true): envía wp_new_user_notification.
	 *   - role (string, default welow_employee): rol del usuario.
	 *   - password (string|null): si null, se genera aleatorio.
	 *
	 * @param array<string, mixed> $data Datos del empleado.
	 * @param array<string, mixed> $opts Opciones.
	 * @return Employee|\WP_Error Employee creado o WP_Error con errores.
	 */
	public function create_with_user( array $data, array $opts = array() ) {
		// 1) Validación de formato.
		$errors = EmployeeValidator::validate_create( $data );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		// 2) Unicidades.
		$uniqueness = $this->check_uniqueness_create( $data );
		if ( $uniqueness->has_errors() ) {
			return $uniqueness;
		}

		// 3) Crear WP_User.
		$opts    = array_merge(
			array(
				'send_welcome_email' => true,
				'role'               => Capabilities::ROLE_EMPLOYEE,
				'password'           => null,
			),
			$opts
		);
		$email   = sanitize_email( (string) $data['email'] );
		$user_id = $this->create_user(
			$email,
			(string) ( $data['first_name'] ?? '' ),
			(string) ( $data['last_name'] ?? '' ),
			$opts['password'],
			$opts['role']
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// 4) Insertar fila en welow_employees. Si falla, rollback del WP_User.
		try {
			$employee_id = $this->repository->create( $this->build_employee_dto( $user_id, $data ) );
		} catch ( \Throwable $e ) {
			// Rollback usuario WP creado.
			if ( function_exists( 'wp_delete_user' ) || self::ensure_user_admin_loaded() ) {
				wp_delete_user( $user_id );
			}
			return new \WP_Error( 'welow_employee_create_failed', $e->getMessage() );
		}

		$employee = $this->repository->find_by_id( $employee_id );
		if ( null === $employee ) {
			return new \WP_Error( 'welow_employee_lookup_failed', __( 'Empleado creado pero no recuperable.', 'welow-rrhh' ) );
		}

		// 5) Auditoría.
		$this->audit->log(
			'create',
			self::AUDIT_ENTITY,
			$employee->id,
			array(
				'user_id' => $user_id,
				'email'   => $email,
				'name'    => $employee->full_name(),
			)
		);

		// 6) Email de bienvenida (helper nativo de WP).
		if ( $opts['send_welcome_email'] ) {
			wp_new_user_notification( $user_id, null, 'both' );
		}

		return $employee;
	}

	/**
	 * Crea un empleado para un WP_User existente (vinculación, no crea usuario).
	 *
	 * Útil cuando un usuario WordPress ya existe (p. ej. importado desde CSV
	 * con un email que coincide con un usuario previo) y queremos darlo de
	 * alta como empleado sin duplicarlo.
	 *
	 * Asegura que el usuario tenga el rol indicado (default welow_employee).
	 *
	 * @param int                  $user_id ID del WP_User existente.
	 * @param array<string, mixed> $data    Datos del empleado (sin email; se toma del user).
	 * @param array<string, mixed> $opts    Opciones (role).
	 * @return Employee|\WP_Error
	 */
	public function create_for_existing_user( int $user_id, array $data, array $opts = array() ) {
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return new \WP_Error( 'welow_user_not_found', __( 'Usuario WordPress no encontrado.', 'welow-rrhh' ) );
		}

		if ( null !== $this->repository->find_by_user_id( $user_id ) ) {
			return new \WP_Error(
				'welow_user_already_linked',
				__( 'Este usuario ya está vinculado a un empleado.', 'welow-rrhh' )
			);
		}

		$data['email'] = $user->user_email;
		$errors        = EmployeeValidator::validate_create( $data );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		// Unicidades (saltamos email — el user ya existe legítimamente).
		$u_errors = new \WP_Error();
		if ( ! empty( $data['employee_code'] ) ) {
			$code = sanitize_text_field( (string) $data['employee_code'] );
			if ( null !== $this->repository->find_by_code( $code ) ) {
				$u_errors->add( 'employee_code', __( 'Ya existe un empleado con ese código.', 'welow-rrhh' ) );
			}
		}
		if ( ! empty( $data['dni_nie'] ) ) {
			$dni = Dni::normalize( (string) $data['dni_nie'] );
			if ( null !== $dni && null !== $this->repository->find_by_dni( $dni ) ) {
				$u_errors->add( 'dni_nie', __( 'Ya existe un empleado con ese DNI/NIE.', 'welow-rrhh' ) );
			}
		}
		if ( $u_errors->has_errors() ) {
			return $u_errors;
		}

		$role = isset( $opts['role'] ) && '' !== $opts['role'] ? (string) $opts['role'] : Capabilities::ROLE_EMPLOYEE;
		if ( ! in_array( $role, (array) $user->roles, true ) ) {
			$user->add_role( $role );
		}

		try {
			$employee_id = $this->repository->create( $this->build_employee_dto( $user_id, $data ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_employee_create_failed', $e->getMessage() );
		}

		$employee = $this->repository->find_by_id( $employee_id );
		if ( null === $employee ) {
			return new \WP_Error( 'welow_employee_lookup_failed', __( 'Empleado creado pero no recuperable.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'create',
			self::AUDIT_ENTITY,
			$employee->id,
			array(
				'user_id' => $user_id,
				'email'   => $user->user_email,
				'name'    => $employee->full_name(),
				'linked'  => true,
			)
		);

		return $employee;
	}

	/**
	 * Actualiza un empleado existente.
	 *
	 * Sincroniza email/nombre con el WP_User si esos campos cambian.
	 *
	 * @param int                  $employee_id ID interno del empleado.
	 * @param array<string, mixed> $data        Campos a actualizar (parcial).
	 * @return Employee|\WP_Error Empleado tras actualizar o WP_Error.
	 */
	public function update( int $employee_id, array $data ) {
		$current = $this->repository->find_by_id( $employee_id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_employee_not_found', __( 'Empleado no encontrado.', 'welow-rrhh' ) );
		}

		$errors = EmployeeValidator::validate_update( $data );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$uniqueness = $this->check_uniqueness_update( $current, $data );
		if ( $uniqueness->has_errors() ) {
			return $uniqueness;
		}

		// Sincronizar wp_users si cambian email/nombres.
		$user_update = array();
		if ( ! empty( $data['email'] ) ) {
			$user_update['user_email'] = sanitize_email( (string) $data['email'] );
		}
		if ( array_key_exists( 'first_name', $data ) ) {
			$user_update['first_name'] = sanitize_text_field( (string) $data['first_name'] );
		}
		if ( array_key_exists( 'last_name', $data ) ) {
			$user_update['last_name'] = sanitize_text_field( (string) $data['last_name'] );
		}
		if ( ! empty( $user_update ) ) {
			$user_update['ID']           = $current->user_id;
			$user_update['display_name'] = trim(
				( $user_update['first_name'] ?? $current->first_name )
				. ' '
				. ( $user_update['last_name'] ?? $current->last_name )
			);
			$result                      = wp_update_user( $user_update );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Cambios para la tabla.
		$changes = $this->build_changes_array( $data );

		if ( ! empty( $changes ) ) {
			try {
				$this->repository->update_changes( $employee_id, $changes );
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'welow_employee_update_failed', $e->getMessage() );
			}
		}

		$updated = $this->repository->find_by_id( $employee_id );
		if ( null === $updated ) {
			return new \WP_Error( 'welow_employee_lookup_failed', __( 'Empleado no recuperable tras actualizar.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'update',
			self::AUDIT_ENTITY,
			$employee_id,
			array(
				'before' => array(
					'first_name' => $current->first_name,
					'last_name'  => $current->last_name,
					'status'     => $current->status->value,
				),
				'after'  => array(
					'first_name' => $updated->first_name,
					'last_name'  => $updated->last_name,
					'status'     => $updated->status->value,
				),
			)
		);

		return $updated;
	}

	/**
	 * Marca un empleado como inactivo (baja lógica) registrando termination_date.
	 *
	 * @param int                     $employee_id      Identificador.
	 * @param \DateTimeImmutable|null $termination_date Fecha de baja (default hoy).
	 * @return Employee|\WP_Error
	 */
	public function terminate( int $employee_id, ?\DateTimeImmutable $termination_date = null ) {
		$current = $this->repository->find_by_id( $employee_id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_employee_not_found', __( 'Empleado no encontrado.', 'welow-rrhh' ) );
		}

		$date = $termination_date ?? new \DateTimeImmutable( 'today' );

		try {
			$this->repository->update_changes(
				$employee_id,
				array(
					'status'           => EmployeeStatus::INACTIVE,
					'termination_date' => $date,
				)
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_employee_terminate_failed', $e->getMessage() );
		}

		$this->audit->log(
			'terminate',
			self::AUDIT_ENTITY,
			$employee_id,
			array(
				'termination_date' => $date->format( 'Y-m-d' ),
			)
		);

		return $this->repository->find_by_id( $employee_id );
	}

	/**
	 * Borrado físico del empleado. No borra el WP_User salvo que se pida.
	 *
	 * @param int  $employee_id Identificador.
	 * @param bool $delete_user Si true, borra también el WP_User vinculado.
	 * @return true|\WP_Error
	 */
	public function delete( int $employee_id, bool $delete_user = false ) {
		$current = $this->repository->find_by_id( $employee_id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_employee_not_found', __( 'Empleado no encontrado.', 'welow-rrhh' ) );
		}

		$ok = $this->repository->delete_by_id( $employee_id );
		if ( ! $ok ) {
			return new \WP_Error( 'welow_employee_delete_failed', __( 'No se pudo eliminar el empleado.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'delete',
			self::AUDIT_ENTITY,
			$employee_id,
			array(
				'user_id'     => $current->user_id,
				'name'        => $current->full_name(),
				'delete_user' => $delete_user,
			)
		);

		if ( $delete_user && self::ensure_user_admin_loaded() ) {
			wp_delete_user( $current->user_id );
		}

		return true;
	}

	/**
	 * Devuelve el repositorio (útil para componentes admin/REST).
	 *
	 * @return EmployeeRepository
	 */
	public function repository(): EmployeeRepository {
		return $this->repository;
	}

	/**
	 * Comprueba unicidades para creación (email, employee_code, dni_nie).
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return \WP_Error
	 */
	private function check_uniqueness_create( array $data ): \WP_Error {
		$errors = new \WP_Error();

		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( '' !== $email && email_exists( $email ) ) {
			$errors->add( 'email', __( 'Ya existe un usuario con ese email.', 'welow-rrhh' ) );
		}

		if ( ! empty( $data['employee_code'] ) ) {
			$code = sanitize_text_field( (string) $data['employee_code'] );
			if ( null !== $this->repository->find_by_code( $code ) ) {
				$errors->add( 'employee_code', __( 'Ya existe un empleado con ese código.', 'welow-rrhh' ) );
			}
		}

		if ( ! empty( $data['dni_nie'] ) ) {
			$dni = Dni::normalize( (string) $data['dni_nie'] );
			if ( null !== $dni && null !== $this->repository->find_by_dni( $dni ) ) {
				$errors->add( 'dni_nie', __( 'Ya existe un empleado con ese DNI/NIE.', 'welow-rrhh' ) );
			}
		}

		return $errors;
	}

	/**
	 * Comprueba unicidades en actualización (descarta colisión contra el propio empleado).
	 *
	 * @param Employee             $current Empleado actual.
	 * @param array<string, mixed> $data    Datos.
	 * @return \WP_Error
	 */
	private function check_uniqueness_update( Employee $current, array $data ): \WP_Error {
		$errors = new \WP_Error();

		if ( ! empty( $data['email'] ) ) {
			$email   = sanitize_email( (string) $data['email'] );
			$user_id = email_exists( $email );
			if ( false !== $user_id && (int) $user_id !== $current->user_id ) {
				$errors->add( 'email', __( 'Ya existe un usuario con ese email.', 'welow-rrhh' ) );
			}
		}

		if ( array_key_exists( 'employee_code', $data ) && ! empty( $data['employee_code'] ) ) {
			$code  = sanitize_text_field( (string) $data['employee_code'] );
			$other = $this->repository->find_by_code( $code );
			if ( null !== $other && $other->id !== $current->id ) {
				$errors->add( 'employee_code', __( 'Ya existe un empleado con ese código.', 'welow-rrhh' ) );
			}
		}

		if ( array_key_exists( 'dni_nie', $data ) && ! empty( $data['dni_nie'] ) ) {
			$dni = Dni::normalize( (string) $data['dni_nie'] );
			if ( null !== $dni ) {
				$other = $this->repository->find_by_dni( $dni );
				if ( null !== $other && $other->id !== $current->id ) {
					$errors->add( 'dni_nie', __( 'Ya existe un empleado con ese DNI/NIE.', 'welow-rrhh' ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Crea el WP_User asociado a un empleado.
	 *
	 * @param string      $email      Email.
	 * @param string      $first_name Nombre.
	 * @param string      $last_name  Apellidos.
	 * @param string|null $password   Password explícito o null para generar uno random.
	 * @param string      $role       Slug del rol a asignar.
	 * @return int|\WP_Error
	 */
	private function create_user( string $email, string $first_name, string $last_name, ?string $password, string $role ) {
		$base     = sanitize_user( current( explode( '@', $email ) ), true );
		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . '-' . $i;
			++$i;
		}

		$pass    = null === $password ? wp_generate_password( 20, true ) : $password;
		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'role'         => $role,
			)
		);

		return $user_id;
	}

	/**
	 * Construye un DTO Employee a partir de un payload limpio para inserción.
	 *
	 * @param int                  $user_id Id del WP_User ya creado.
	 * @param array<string, mixed> $data    Datos de entrada.
	 * @return Employee
	 */
	private function build_employee_dto( int $user_id, array $data ): Employee {
		$hire_date = ! empty( $data['hire_date'] ) ? EmployeeValidator::parse_date( (string) $data['hire_date'] ) : false;
		$dni       = ! empty( $data['dni_nie'] ) ? Dni::normalize( (string) $data['dni_nie'] ) : null;

		return new Employee(
			null,
			$user_id,
			isset( $data['employee_code'] ) && '' !== $data['employee_code'] ? sanitize_text_field( (string) $data['employee_code'] ) : null,
			$dni,
			sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
			sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
			isset( $data['department_id'] ) && '' !== $data['department_id'] ? (int) $data['department_id'] : null,
			sanitize_text_field( (string) ( $data['position'] ?? '' ) ),
			isset( $data['manager_user_id'] ) && '' !== $data['manager_user_id'] ? (int) $data['manager_user_id'] : null,
			false === $hire_date ? null : $hire_date,
			null,
			isset( $data['weekly_hours'] ) && '' !== $data['weekly_hours'] ? (float) $data['weekly_hours'] : null,
			isset( $data['vacation_days_override'] ) && '' !== $data['vacation_days_override'] ? (int) $data['vacation_days_override'] : null,
			isset( $data['geo_policy_override'] ) && is_array( $data['geo_policy_override'] ) ? $data['geo_policy_override'] : null,
			isset( $data['status'] ) && '' !== $data['status']
				? ( EmployeeStatus::tryFrom( (string) $data['status'] ) ?? EmployeeStatus::get_default() )
				: EmployeeStatus::get_default(),
			isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array()
		);
	}

	/**
	 * Construye el array de cambios para update_changes() a partir del payload.
	 *
	 * @param array<string, mixed> $data Payload.
	 * @return array<string, mixed>
	 */
	private function build_changes_array( array $data ): array {
		$changes = array();

		$direct = array(
			'employee_code',
			'first_name',
			'last_name',
			'position',
		);
		foreach ( $direct as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$changes[ $key ] = sanitize_text_field( (string) $data[ $key ] );
			}
		}

		if ( array_key_exists( 'department_id', $data ) ) {
			$changes['department_id'] = ( '' === $data['department_id'] || null === $data['department_id'] ) ? null : (int) $data['department_id'];
		}
		if ( array_key_exists( 'manager_user_id', $data ) ) {
			$changes['manager_user_id'] = ( '' === $data['manager_user_id'] || null === $data['manager_user_id'] ) ? null : (int) $data['manager_user_id'];
		}
		if ( array_key_exists( 'weekly_hours', $data ) ) {
			$changes['weekly_hours'] = ( '' === $data['weekly_hours'] || null === $data['weekly_hours'] ) ? null : (float) $data['weekly_hours'];
		}
		if ( array_key_exists( 'vacation_days_override', $data ) ) {
			$changes['vacation_days_override'] = ( '' === $data['vacation_days_override'] || null === $data['vacation_days_override'] ) ? null : (int) $data['vacation_days_override'];
		}
		if ( array_key_exists( 'hire_date', $data ) ) {
			$changes['hire_date'] = empty( $data['hire_date'] ) ? null : EmployeeValidator::parse_date( (string) $data['hire_date'] );
		}
		if ( array_key_exists( 'termination_date', $data ) ) {
			$changes['termination_date'] = empty( $data['termination_date'] ) ? null : EmployeeValidator::parse_date( (string) $data['termination_date'] );
		}
		if ( array_key_exists( 'dni_nie', $data ) ) {
			$changes['dni_nie'] = empty( $data['dni_nie'] ) ? null : ( Dni::normalize( (string) $data['dni_nie'] ) ?? $data['dni_nie'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$changes['status'] = EmployeeStatus::tryFrom( (string) $data['status'] ) ?? EmployeeStatus::get_default();
		}
		if ( array_key_exists( 'meta', $data ) && is_array( $data['meta'] ) ) {
			$changes['meta'] = $data['meta'];
		}
		if ( array_key_exists( 'geo_policy_override', $data ) ) {
			$changes['geo_policy_override'] = is_array( $data['geo_policy_override'] ) ? $data['geo_policy_override'] : null;
		}

		return $changes;
	}

	/**
	 * Asegura que las funciones de gestión de usuarios admin (wp_delete_user) están cargadas.
	 *
	 * @return bool True si están disponibles tras la llamada.
	 */
	private static function ensure_user_admin_loaded(): bool {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		return function_exists( 'wp_delete_user' );
	}
}
