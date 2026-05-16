<?php
/**
 * Tab "Calendario equipo" — agenda compacta de vacaciones aprobadas y
 * pendientes del equipo (o de toda la empresa si MANAGE_ANY), agrupada
 * por mes.
 *
 * @package Welow\RRHH\Modules\Vacations\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Frontend;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Frontend\Tabs\TabInterface;
use Welow\RRHH\Modules\Vacations\Data\VacationRequest;
use Welow\RRHH\Modules\Vacations\Service\RequestService;
use Welow\RRHH\Modules\Vacations\VacationsCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * TeamCalendarTab.
 */
final class TeamCalendarTab implements TabInterface {

	/**
	 * Servicio.
	 *
	 * @var RequestService
	 */
	private RequestService $service;

	/**
	 * Repo empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Constructor.
	 *
	 * @param RequestService     $service   Servicio.
	 * @param EmployeeRepository $employees Repo empleados.
	 */
	public function __construct( RequestService $service, EmployeeRepository $employees ) {
		$this->service   = $service;
		$this->employees = $employees;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'team-calendar';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Calendario equipo', 'welow-rrhh' );
	}

	/**
	 * Visible para VIEW_TEAM, VIEW_ALL o MANAGE_ANY.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function visible_for( \WP_User $user ): bool {
		unset( $user );
		return current_user_can( VacationsCapabilities::VIEW_TEAM )
			|| current_user_can( VacationsCapabilities::VIEW_ALL )
			|| current_user_can( VacationsCapabilities::MANAGE_ANY );
	}

	/**
	 * {@inheritDoc}
	 */
	public function order(): int {
		return 40;
	}

	/**
	 * Render.
	 *
	 * @param \WP_User $user Usuario.
	 * @return void
	 */
	public function render( \WP_User $user ): void {
		$year = (int) wp_date( 'Y' );

		$filters = array(
			'year' => $year,
			'from' => new \DateTimeImmutable( sprintf( '%04d-01-01', $year ), wp_timezone() ),
			'to'   => new \DateTimeImmutable( sprintf( '%04d-12-31', $year ), wp_timezone() ),
		);

		$see_all = current_user_can( VacationsCapabilities::VIEW_ALL )
			|| current_user_can( VacationsCapabilities::MANAGE_ANY );
		if ( ! $see_all ) {
			$ids                 = $this->team_user_ids( (int) $user->ID );
			$ids[]               = (int) $user->ID;
			$filters['user_ids'] = array_values( array_unique( $ids ) );
		}

		$all_items = $this->service->repository()->search( $filters, 500, 0 );
		$items     = array_values(
			array_filter(
				$all_items,
				static fn( VacationRequest $r ): bool => in_array( $r->status->value, array( 'pending', 'approved' ), true )
			)
		);

		// Agrupa por mes (YYYY-MM).
		$by_month = array();
		foreach ( $items as $r ) {
			$key                = $r->start_date->format( 'Y-m' );
			$by_month[ $key ][] = $r;
		}
		ksort( $by_month );

		$html = ModuleTemplates::render(
			'tab-team-calendar',
			array(
				'user'     => $user,
				'year'     => $year,
				'by_month' => $by_month,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * IDs de WP_User que reportan al manager dado.
	 *
	 * @param int $manager_user_id Manager.
	 * @return int[]
	 */
	private function team_user_ids( int $manager_user_id ): array {
		$emps = $this->employees->search( array( 'manager_user_id' => $manager_user_id ), 1, 200 )['items'] ?? array();
		$ids  = array();
		foreach ( $emps as $emp ) {
			$ids[] = (int) $emp->user_id;
		}
		return array_values( array_unique( $ids ) );
	}
}
