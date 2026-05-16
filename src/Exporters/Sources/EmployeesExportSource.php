<?php
/**
 * Fuente exportable: empleados.
 *
 * Volcado de la tabla welow_employees con DNI descifrado, nombres,
 * departamento (resuelto por nombre), estado, fechas, etc.
 *
 * @package Welow\RRHH\Exporters\Sources
 */

declare( strict_types=1 );

namespace Welow\RRHH\Exporters\Sources;

use Welow\RRHH\Departments\DepartmentRepository;
use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Exporters\ExportSourceInterface;
use Welow\RRHH\Roles\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * EmployeesExportSource.
 */
final class EmployeesExportSource implements ExportSourceInterface {

	/**
	 * Repo empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Repo departamentos.
	 *
	 * @var DepartmentRepository
	 */
	private DepartmentRepository $departments;

	/**
	 * Cache local de nombres de departamento por id (para no repetir queries).
	 *
	 * @var array<int, string>|null
	 */
	private ?array $department_names = null;

	/**
	 * Constructor.
	 *
	 * @param EmployeeRepository   $employees   Repo empleados.
	 * @param DepartmentRepository $departments Repo departamentos.
	 */
	public function __construct( EmployeeRepository $employees, DepartmentRepository $departments ) {
		$this->employees   = $employees;
		$this->departments = $departments;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'employees';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Empleados', 'welow-rrhh' );
	}

	/**
	 * Indica si el usuario puede exportar empleados.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function can_export( \WP_User $user ): bool {
		unset( $user );
		return current_user_can( Capabilities::CAP_EXPORT_DATA )
			|| current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES );
	}

	/**
	 * {@inheritDoc}
	 */
	public function headers(): array {
		return array(
			__( 'ID', 'welow-rrhh' ),
			__( 'Código', 'welow-rrhh' ),
			__( 'Nombre', 'welow-rrhh' ),
			__( 'Apellidos', 'welow-rrhh' ),
			__( 'Email', 'welow-rrhh' ),
			__( 'DNI/NIE', 'welow-rrhh' ),
			__( 'Departamento', 'welow-rrhh' ),
			__( 'Cargo', 'welow-rrhh' ),
			__( 'Estado', 'welow-rrhh' ),
			__( 'Fecha de alta', 'welow-rrhh' ),
			__( 'Fecha de baja', 'welow-rrhh' ),
			__( 'Horas semanales', 'welow-rrhh' ),
			__( 'Días vac. (override)', 'welow-rrhh' ),
		);
	}

	/**
	 * Iterador de filas (paginado interno para no cargar todo en memoria).
	 *
	 * @return iterable<int, string[]>
	 */
	public function rows(): iterable {
		$page       = 1;
		$page_size  = 200;
		$last_count = $page_size;
		while ( $page_size === $last_count ) {
			$result     = $this->employees->search( array(), $page, $page_size );
			$last_count = count( $result['items'] );
			foreach ( $result['items'] as $emp ) {
				$user      = get_userdata( $emp->user_id );
				$email     = $user ? (string) $user->user_email : '';
				$dept_name = null !== $emp->department_id ? $this->resolve_department_name( $emp->department_id ) : '';

				yield array(
					(string) ( $emp->id ?? '' ),
					(string) ( $emp->employee_code ?? '' ),
					(string) $emp->first_name,
					(string) $emp->last_name,
					$email,
					(string) ( $emp->dni_nie ?? '' ),
					$dept_name,
					(string) $emp->position,
					$emp->status->value,
					null !== $emp->hire_date ? $emp->hire_date->format( 'Y-m-d' ) : '',
					null !== $emp->termination_date ? $emp->termination_date->format( 'Y-m-d' ) : '',
					null !== $emp->weekly_hours ? number_format( $emp->weekly_hours, 2, '.', '' ) : '',
					null !== $emp->vacation_days_override ? (string) $emp->vacation_days_override : '',
				);
			}
			++$page;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_format(): string {
		return 'csv';
	}

	/**
	 * Devuelve el nombre del departamento por id (con cache interna).
	 *
	 * @param int $id ID.
	 * @return string
	 */
	private function resolve_department_name( int $id ): string {
		if ( null === $this->department_names ) {
			$this->department_names = array();
			foreach ( $this->departments->find_all() as $dep ) {
				if ( null !== $dep->id ) {
					$this->department_names[ $dep->id ] = $dep->name;
				}
			}
		}
		return $this->department_names[ $id ] ?? '';
	}
}
