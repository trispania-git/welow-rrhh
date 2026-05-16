<?php
/**
 * WP_List_Table de fichajes para admin.
 *
 * Filtra por:
 *   - Permisos del usuario actual (VIEW_TEAM → sólo equipo;
 *     VIEW_ALL → todos).
 *   - Empleado concreto (dropdown), fecha desde/hasta.
 *
 * @package Welow\RRHH\Modules\TimeTracking\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Admin;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Modules\TimeTracking\Closure\MonthClosure;
use Welow\RRHH\Modules\TimeTracking\Data\TimeEntry;
use Welow\RRHH\Modules\TimeTracking\Repository\TimeEntryRepository;
use Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * TimeEntriesListTable.
 */
final class TimeEntriesListTable extends \WP_List_Table {

	private const PER_PAGE = 50;

	/**
	 * Repositorio fichajes.
	 *
	 * @var TimeEntryRepository
	 */
	private TimeEntryRepository $repository;

	/**
	 * Repo empleados (para resolver nombre y team scoping).
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Servicio de cierre.
	 *
	 * @var MonthClosure
	 */
	private MonthClosure $closure;

	/**
	 * Cache local user_id → display name.
	 *
	 * @var array<int, string>
	 */
	private array $user_name_cache = array();

	/**
	 * Constructor.
	 *
	 * @param TimeEntryRepository $repository Repo.
	 * @param EmployeeRepository  $employees  Repo empleados.
	 * @param MonthClosure        $closure    Servicio de cierre.
	 */
	public function __construct( TimeEntryRepository $repository, EmployeeRepository $employees, MonthClosure $closure ) {
		parent::__construct(
			array(
				'singular' => 'time_entry',
				'plural'   => 'time_entries',
				'ajax'     => false,
			)
		);
		$this->repository = $repository;
		$this->employees  = $employees;
		$this->closure    = $closure;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_columns(): array {
		return array(
			'occurred_at' => __( 'Fecha y hora', 'welow-rrhh' ),
			'user'        => __( 'Empleado', 'welow-rrhh' ),
			'event'       => __( 'Evento', 'welow-rrhh' ),
			'source'      => __( 'Origen', 'welow-rrhh' ),
			'note'        => __( 'Nota', 'welow-rrhh' ),
			'edited'      => __( 'Editado', 'welow-rrhh' ),
			'closed'      => __( 'Cerrado', 'welow-rrhh' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$user_ids = $this->scope_user_ids();
		// Si no es array (admin viendo todos), pasamos null al find_for_range.
		$is_global = ( null === $user_ids );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged_raw = isset( $_GET['paged'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['paged'] ) ) : '';
		$paged     = max( 1, (int) $paged_raw );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$emp_id_raw = isset( $_GET['emp'] ) ? (int) $_GET['emp'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from_raw = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to_raw = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : '';

		$from = self::parse_date( $from_raw );
		$to   = self::parse_date( $to_raw );

		// Defaults: mes en curso.
		if ( null === $from && null === $to ) {
			$now  = new \DateTimeImmutable( 'now', wp_timezone() );
			$from = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
			$to   = $now->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		}

		$target_user = null;
		if ( $emp_id_raw > 0 ) {
			if ( $is_global || in_array( $emp_id_raw, (array) $user_ids, true ) ) {
				$target_user = $emp_id_raw;
			}
		}

		if ( null !== $target_user ) {
			$items = $this->repository->find_for_range( $target_user, $from, $to, 1000, ( $paged - 1 ) * self::PER_PAGE );
			$total = count( $items );
		} elseif ( $is_global ) {
			$items = $this->repository->find_for_range( null, $from, $to, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
			$total = $this->approx_total_for( null, $from, $to );
		} else {
			// Equipo: traemos todos los del rango y filtramos en memoria por user_ids permitidos.
			$all   = $this->repository->find_for_range( null, $from, $to, 2000, 0 );
			$items = array_values(
				array_filter(
					$all,
					static fn( TimeEntry $e ): bool => in_array( $e->user_id, $user_ids, true )
				)
			);
			$total = count( $items );
			$items = array_slice( $items, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		}

		$this->items = $items;
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			)
		);
	}

	/**
	 * Aproxima un total (para paginación) cuando consultamos sin filtrar por user.
	 *
	 * @param int|null                $user_id Usuario.
	 * @param \DateTimeImmutable|null $from    Desde.
	 * @param \DateTimeImmutable|null $to      Hasta.
	 * @return int
	 */
	private function approx_total_for( ?int $user_id, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to ): int {
		// Para evitar añadir un count() a la base, traemos sólo 1001 y aproximamos.
		$probe = $this->repository->find_for_range( $user_id, $from, $to, 1001, 0 );
		$count = count( $probe );
		return $count;
	}

	/**
	 * Devuelve la lista de user_ids visibles para el usuario actual.
	 * null si VIEW_ALL (sin restricción de listado).
	 *
	 * @return int[]|null
	 */
	private function scope_user_ids(): ?array {
		if ( current_user_can( TimeTrackingCapabilities::VIEW_ALL ) ) {
			return null;
		}
		// VIEW_TEAM: empleados cuyo manager_user_id sea el current.
		$current = get_current_user_id();
		$team    = $this->employees->search( array( 'manager_user_id' => $current ), 1, 1000 );
		$ids     = array_map( static fn( $emp ): int => (int) $emp->user_id, $team['items'] );
		// Incluye al propio current (puede ver sus propios fichajes en esta pantalla).
		$ids[] = $current;
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Default column renderer.
	 *
	 * @param TimeEntry $item        Evento.
	 * @param string    $column_name Columna.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'occurred_at':
				return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->occurred_at->getTimestamp() ) );
			case 'user':
				return esc_html( $this->resolve_user_name( $item->user_id ) );
			case 'event':
				return esc_html( $item->event_type->label() );
			case 'source':
				return esc_html( $item->source->value );
			case 'note':
				return $item->note ? esc_html( $item->note ) : '—';
			case 'edited':
				return $item->is_edited ? '<strong>' . esc_html__( 'Sí', 'welow-rrhh' ) . '</strong>' : '—';
			case 'closed':
				return $this->closure->is_closed( $item->occurred_at ) ? esc_html__( 'Sí', 'welow-rrhh' ) : '—';
		}
		return '';
	}

	/**
	 * Columna ocurrida_at con row_actions Editar.
	 *
	 * @param TimeEntry $item Evento.
	 * @return string
	 */
	public function column_occurred_at( TimeEntry $item ): string {
		$timestamp = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->occurred_at->getTimestamp() );

		$edit_url = admin_url(
			'admin.php?page=' . TimeEntriesScreen::PAGE_SLUG . '&action=edit&id=' . (int) $item->id
		);
		$actions  = array(
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Editar', 'welow-rrhh' ) ),
		);

		return '<strong>' . esc_html( $timestamp ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		esc_html_e( 'No hay fichajes en el rango seleccionado.', 'welow-rrhh' );
	}

	/**
	 * Filtros: empleado + rango.
	 *
	 * @param string $which top/bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$user_ids = $this->scope_user_ids();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_emp = isset( $_GET['emp'] ) ? (int) $_GET['emp'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : '';

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="filter-emp">' . esc_html__( 'Empleado', 'welow-rrhh' ) . '</label>';
		echo '<select name="emp" id="filter-emp">';
		echo '<option value="0">' . esc_html__( 'Todos los empleados visibles', 'welow-rrhh' ) . '</option>';
		$choices = $this->employee_choices( $user_ids );
		foreach ( $choices as $uid => $label ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $uid,
				selected( $current_emp, (int) $uid, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<input type="date" name="from" value="' . esc_attr( $current_from ) . '" />';
		echo '<input type="date" name="to" value="' . esc_attr( $current_to ) . '" />';
		submit_button( __( 'Filtrar', 'welow-rrhh' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Lista de empleados disponibles para el filtro (acotada por scope).
	 *
	 * @param int[]|null $user_ids Scope.
	 * @return array<int, string>
	 */
	private function employee_choices( ?array $user_ids ): array {
		$search = $this->employees->search( array(), 1, 500 );
		$out    = array();
		foreach ( $search['items'] as $emp ) {
			if ( null !== $user_ids && ! in_array( (int) $emp->user_id, $user_ids, true ) ) {
				continue;
			}
			$out[ (int) $emp->user_id ] = $emp->full_name();
		}
		asort( $out );
		return $out;
	}

	/**
	 * Resuelve nombre de un user (cache local).
	 *
	 * @param int $user_id Usuario.
	 * @return string
	 */
	private function resolve_user_name( int $user_id ): string {
		if ( isset( $this->user_name_cache[ $user_id ] ) ) {
			return $this->user_name_cache[ $user_id ];
		}
		$user                              = get_userdata( $user_id );
		$this->user_name_cache[ $user_id ] = $user ? $user->display_name : sprintf( '#%d', $user_id );
		return $this->user_name_cache[ $user_id ];
	}

	/**
	 * Parser YYYY-MM-DD.
	 *
	 * @param string $value Valor.
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_date( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false === $dt ? null : $dt;
	}
}
