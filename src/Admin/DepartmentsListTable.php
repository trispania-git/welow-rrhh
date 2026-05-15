<?php
/**
 * WP_List_Table de departamentos.
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Departments\DepartmentRepository;
use Welow\RRHH\Support\Data\Department;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Listado de departamentos.
 */
final class DepartmentsListTable extends \WP_List_Table {

	/**
	 * Repositorio.
	 *
	 * @var DepartmentRepository
	 */
	private DepartmentRepository $repository;

	/**
	 * Cache local de departamentos (para resolver parent rápidamente).
	 *
	 * @var array<int, Department>
	 */
	private array $by_id = array();

	/**
	 * Constructor.
	 *
	 * @param DepartmentRepository $repository Repositorio.
	 */
	public function __construct( DepartmentRepository $repository ) {
		parent::__construct(
			array(
				'singular' => 'department',
				'plural'   => 'departments',
				'ajax'     => false,
			)
		);
		$this->repository = $repository;
	}

	/**
	 * Columnas.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'name'      => __( 'Nombre', 'welow-rrhh' ),
			'slug'      => __( 'Slug', 'welow-rrhh' ),
			'parent'    => __( 'Padre', 'welow-rrhh' ),
			'manager'   => __( 'Manager', 'welow-rrhh' ),
			'employees' => __( 'Empleados', 'welow-rrhh' ),
		);
	}

	/**
	 * Prepara los items.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$all         = $this->repository->find_all();
		$this->items = $all;
		foreach ( $all as $dep ) {
			if ( null !== $dep->id ) {
				$this->by_id[ $dep->id ] = $dep;
			}
		}

		$this->set_pagination_args(
			array(
				'total_items' => count( $all ),
				'per_page'    => max( 20, count( $all ) ),
				'total_pages' => 1,
			)
		);
	}

	/**
	 * Default column.
	 *
	 * @param Department $item        Departamento.
	 * @param string     $column_name Columna.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		switch ( $column_name ) {
			case 'slug':
				return esc_html( $item->slug );
			case 'parent':
				if ( null === $item->parent_id ) {
					return '—';
				}
				$parent = $this->by_id[ $item->parent_id ] ?? null;
				return null !== $parent ? esc_html( $parent->name ) : '—';
			case 'manager':
				if ( null === $item->manager_user_id ) {
					return '—';
				}
				$user = get_userdata( $item->manager_user_id );
				return $user ? esc_html( $user->display_name ) : '—';
			case 'employees':
				return (string) $this->repository->count_employees( (int) $item->id );
		}
		return '—';
	}

	/**
	 * Columna name con row actions.
	 *
	 * @param Department $item Departamento.
	 * @return string
	 */
	public function column_name( Department $item ): string {
		$page       = DepartmentsScreen::PAGE_SLUG;
		$edit_url   = admin_url( "admin.php?page={$page}&action=edit&id=" . (int) $item->id );
		$delete_url = wp_nonce_url(
			admin_url( "admin.php?page={$page}&action=delete&id=" . (int) $item->id ),
			'welow_rrhh_department_delete_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Editar', 'welow-rrhh' ) ),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( '¿Eliminar este departamento?', 'welow-rrhh' ) ),
				esc_html__( 'Eliminar', 'welow-rrhh' )
			),
		);

		$name = sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $edit_url ),
			esc_html( $item->name )
		);

		return $name . $this->row_actions( $actions );
	}

	/**
	 * Mensaje cuando no hay items.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No hay departamentos todavía.', 'welow-rrhh' );
	}
}
