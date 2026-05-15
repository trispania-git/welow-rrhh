<?php
/**
 * WP_List_Table de festivos.
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Holidays\HolidayRepository;
use Welow\RRHH\Support\Data\Holiday;
use Welow\RRHH\Support\Data\HolidayScope;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Listado de festivos.
 */
final class HolidaysListTable extends \WP_List_Table {

	/**
	 * Repositorio.
	 *
	 * @var HolidayRepository
	 */
	private HolidayRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param HolidayRepository $repository Repositorio.
	 */
	public function __construct( HolidayRepository $repository ) {
		parent::__construct(
			array(
				'singular' => 'holiday',
				'plural'   => 'holidays',
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
			'date'  => __( 'Fecha', 'welow-rrhh' ),
			'name'  => __( 'Nombre', 'welow-rrhh' ),
			'scope' => __( 'Ámbito', 'welow-rrhh' ),
			'year'  => __( 'Año', 'welow-rrhh' ),
		);
	}

	/**
	 * Prepara los items.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged_raw = isset( $_GET['paged'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['paged'] ) ) : '';
		$paged     = max( 1, (int) $paged_raw );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$year = isset( $_GET['year'] ) ? (int) $_GET['year'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['scope'] ) ) : '';

		$page_data   = $this->repository->search( $year > 0 ? $year : null, '' !== $scope ? $scope : null, $paged, $per_page );
		$this->items = $page_data['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $page_data['total'],
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $page_data['total'] / $per_page ) ),
			)
		);
	}

	/**
	 * Default column.
	 *
	 * @param Holiday $item        Festivo.
	 * @param string  $column_name Columna.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		switch ( $column_name ) {
			case 'date':
				return esc_html( $item->date->format( 'Y-m-d' ) );
			case 'scope':
				return sprintf(
					'<span class="welow-rrhh__status welow-rrhh__status--%s">%s</span>',
					esc_attr( $item->scope->value ),
					esc_html( $item->scope->label() )
				);
			case 'year':
				return esc_html( (string) $item->year );
		}
		return '—';
	}

	/**
	 * Columna name con row_actions.
	 *
	 * @param Holiday $item Festivo.
	 * @return string
	 */
	public function column_name( Holiday $item ): string {
		$page       = HolidaysScreen::PAGE_SLUG;
		$edit_url   = admin_url( "admin.php?page={$page}&action=edit&id=" . (int) $item->id );
		$delete_url = wp_nonce_url(
			admin_url( "admin.php?page={$page}&action=delete&id=" . (int) $item->id ),
			'welow_rrhh_holiday_delete_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Editar', 'welow-rrhh' ) ),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( '¿Eliminar este festivo?', 'welow-rrhh' ) ),
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
	 * Mensaje vacío.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No hay festivos para los filtros seleccionados.', 'welow-rrhh' );
	}

	/**
	 * Filtro de año + scope.
	 *
	 * @param string $which top/bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_year = isset( $_GET['year'] ) ? (int) $_GET['year'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_scope = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['scope'] ) ) : '';
		$years         = $this->repository->distinct_years();

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="filter-year">' . esc_html__( 'Año', 'welow-rrhh' ) . '</label>';
		echo '<select name="year" id="filter-year">';
		echo '<option value="0">' . esc_html__( 'Todos los años', 'welow-rrhh' ) . '</option>';
		foreach ( $years as $year ) {
			printf(
				'<option value="%1$d" %2$s>%1$d</option>',
				(int) $year,
				selected( $current_year, (int) $year, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — devuelve string seguro de WP.
			);
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="filter-scope">' . esc_html__( 'Ámbito', 'welow-rrhh' ) . '</label>';
		echo '<select name="scope" id="filter-scope">';
		echo '<option value="">' . esc_html__( 'Todos los ámbitos', 'welow-rrhh' ) . '</option>';
		foreach ( HolidayScope::cases() as $scope ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $scope->value ),
				selected( $current_scope, $scope->value, false ),
				esc_html( $scope->label() )
			);
		}
		echo '</select>';

		submit_button( __( 'Filtrar', 'welow-rrhh' ), '', 'filter_action', false );
		echo '</div>';
	}
}
