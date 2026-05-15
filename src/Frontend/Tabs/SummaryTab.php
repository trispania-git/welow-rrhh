<?php
/**
 * Tab "Mi resumen" del dashboard frontend.
 *
 * Visible para todos los usuarios logueados. Muestra el perfil del
 * empleado (si está registrado en welow_employees) o un aviso si no.
 *
 * @package Welow\RRHH\Frontend\Tabs
 */

declare( strict_types=1 );

namespace Welow\RRHH\Frontend\Tabs;

use Welow\RRHH\Departments\DepartmentRepository;
use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Frontend\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Tab Mi resumen.
 */
final class SummaryTab implements TabInterface {

	/**
	 * Repositorio de empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Repositorio de departamentos.
	 *
	 * @var DepartmentRepository
	 */
	private DepartmentRepository $departments;

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
		return 'summary';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Mi resumen', 'welow-rrhh' );
	}

	/**
	 * Indica si el tab es visible para el usuario.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function visible_for( \WP_User $user ): bool {
		unset( $user );
		return true;
	}

	/**
	 * Posición del tab.
	 *
	 * @return int
	 */
	public function order(): int {
		return 10;
	}

	/**
	 * Renderiza el contenido del tab.
	 *
	 * @param \WP_User $user Usuario actual.
	 * @return void
	 */
	public function render( \WP_User $user ): void {
		$employee   = $this->employees->find_by_user_id( $user->ID );
		$department = null;
		$manager    = null;

		if ( null !== $employee ) {
			if ( null !== $employee->department_id ) {
				$department = $this->departments->find_by_id( $employee->department_id );
			}
			if ( null !== $employee->manager_user_id ) {
				$manager = get_userdata( $employee->manager_user_id );
			}
		}

		$html = Templates::render(
			'tab-summary',
			array(
				'user'       => $user,
				'employee'   => $employee,
				'department' => $department,
				'manager'    => $manager,
			)
		);
		// La plantilla ya escapa con esc_html/esc_url internamente.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
