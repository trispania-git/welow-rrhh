<?php
/**
 * WP_List_Table para la pantalla de empleados en wp-admin.
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Support\Data\Employee;
use Welow\RRHH\Support\Data\EmployeeStatus;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Listado paginado de empleados.
 */
final class EmployeesListTable extends \WP_List_Table {

	/**
	 * Repositorio de empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param EmployeeRepository $repository Repositorio (inyectado).
	 */
	public function __construct( EmployeeRepository $repository ) {
		parent::__construct(
			array(
				'singular' => 'employee',
				'plural'   => 'employees',
				'ajax'     => false,
				'screen'   => 'welow-rrhh-employees',
			)
		);
		$this->repository = $repository;
	}

	/**
	 * Define las columnas.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'name'          => __( 'Nombre', 'welow-rrhh' ),
			'email'         => __( 'Email', 'welow-rrhh' ),
			'employee_code' => __( 'Código', 'welow-rrhh' ),
			'dni_nie'       => __( 'DNI/NIE', 'welow-rrhh' ),
			'position'      => __( 'Cargo', 'welow-rrhh' ),
			'status'        => __( 'Estado', 'welow-rrhh' ),
			'hire_date'     => __( 'Alta', 'welow-rrhh' ),
		);
	}

	/**
	 * Columnas ordenables.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'name'      => array( 'last_name', false ),
			'email'     => array( 'user_email', false ),
			'status'    => array( 'status', false ),
			'hire_date' => array( 'hire_date', false ),
		);
	}

	/**
	 * Prepara los items (datos paginados desde el repositorio).
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged_raw = isset( $_GET['paged'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['paged'] ) ) : '';
		$paged     = max( 1, (int) $paged_raw );

		$criteria = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['status'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$criteria['status'] = sanitize_text_field( wp_unslash( (string) $_GET['status'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['department_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$criteria['department_id'] = (int) $_GET['department_id'];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['s'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$criteria['search'] = sanitize_text_field( wp_unslash( (string) $_GET['s'] ) );
		}

		$page_data = $this->repository->search( $criteria, $paged, $per_page );

		$this->items = $page_data['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $page_data['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $page_data['total'] / $per_page ),
			)
		);
	}

	/**
	 * Renderizado por defecto para columnas no especializadas.
	 *
	 * @param Employee $item        Empleado.
	 * @param string   $column_name Columna.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		switch ( $column_name ) {
			case 'employee_code':
				return null !== $item->employee_code ? esc_html( $item->employee_code ) : '—';
			case 'dni_nie':
				return null !== $item->dni_nie ? esc_html( $item->dni_nie ) : '—';
			case 'position':
				return '' !== $item->position ? esc_html( $item->position ) : '—';
			case 'hire_date':
				return null !== $item->hire_date
					? esc_html( $item->hire_date->format( 'Y-m-d' ) )
					: '—';
		}
		return '—';
	}

	/**
	 * Nombre + acciones de fila.
	 *
	 * @param Employee $item Empleado.
	 * @return string
	 */
	public function column_name( Employee $item ): string {
		$page = 'welow-rrhh-employees';

		$edit_url   = admin_url( "admin.php?page={$page}&action=edit&id=" . (int) $item->id );
		$delete_url = wp_nonce_url(
			admin_url( "admin.php?page={$page}&action=delete&id=" . (int) $item->id ),
			'welow_rrhh_employee_delete_' . $item->id
		);
		$term_url   = wp_nonce_url(
			admin_url( "admin.php?page={$page}&action=terminate&id=" . (int) $item->id ),
			'welow_rrhh_employee_terminate_' . $item->id
		);

		$actions = array(
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Editar', 'welow-rrhh' ) ),
		);

		if ( EmployeeStatus::ACTIVE === $item->status ) {
			$actions['terminate'] = sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $term_url ),
				esc_js( __( '¿Marcar este empleado como baja?', 'welow-rrhh' ) ),
				esc_html__( 'Dar de baja', 'welow-rrhh' )
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $delete_url ),
			esc_js( __( '¿Eliminar definitivamente este empleado? El usuario WP no se borrará.', 'welow-rrhh' ) ),
			esc_html__( 'Eliminar', 'welow-rrhh' )
		);

		$name = sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $edit_url ),
			esc_html( $item->full_name() )
		);

		return $name . $this->row_actions( $actions );
	}

	/**
	 * Email (lee de wp_users; cae al avatar/email del WP_User).
	 *
	 * @param Employee $item Empleado.
	 * @return string
	 */
	public function column_email( Employee $item ): string {
		$user = get_userdata( $item->user_id );
		if ( ! $user ) {
			return '—';
		}
		return sprintf(
			'<a href="mailto:%1$s">%1$s</a>',
			esc_attr( $user->user_email )
		);
	}

	/**
	 * Estado con badge de color.
	 *
	 * @param Employee $item Empleado.
	 * @return string
	 */
	public function column_status( Employee $item ): string {
		$class = 'welow-rrhh__status welow-rrhh__status--' . esc_attr( $item->status->value );
		return sprintf( '<span class="%s">%s</span>', $class, esc_html( $item->status->label() ) );
	}

	/**
	 * Mensaje cuando no hay items.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No hay empleados todavía.', 'welow-rrhh' );
	}

	/**
	 * Navegación extra: filtros por estado.
	 *
	 * @param string $which top/bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '';
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="filter-status">' . esc_html__( 'Filtrar por estado', 'welow-rrhh' ) . '</label>';
		echo '<select name="status" id="filter-status">';
		echo '<option value="">' . esc_html__( 'Todos los estados', 'welow-rrhh' ) . '</option>';
		foreach ( EmployeeStatus::cases() as $status ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $status->value ),
				selected( $current, $status->value, false ),
				esc_html( $status->label() )
			);
		}
		echo '</select>';
		submit_button( __( 'Filtrar', 'welow-rrhh' ), '', 'filter_action', false );
		echo '</div>';
	}
}
