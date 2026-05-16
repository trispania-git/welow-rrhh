<?php
/**
 * Tab "Aprobaciones equipo" — lista de pending del equipo + botones de
 * aprobar/rechazar.
 *
 * @package Welow\RRHH\Modules\Vacations\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Frontend;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Frontend\Tabs\TabInterface;
use Welow\RRHH\Modules\Vacations\Service\RequestService;
use Welow\RRHH\Modules\Vacations\VacationsCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * TeamApprovalsTab.
 */
final class TeamApprovalsTab implements TabInterface {

	/**
	 * Servicio solicitudes.
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
		return 'team-approvals';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Aprobaciones', 'welow-rrhh' );
	}

	/**
	 * Visible para quien pueda APPROVE_TEAM o MANAGE_ANY.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function visible_for( \WP_User $user ): bool {
		unset( $user );
		return current_user_can( VacationsCapabilities::APPROVE_TEAM )
			|| current_user_can( VacationsCapabilities::MANAGE_ANY );
	}

	/**
	 * {@inheritDoc}
	 */
	public function order(): int {
		return 35;
	}

	/**
	 * Render.
	 *
	 * @param \WP_User $user Usuario.
	 * @return void
	 */
	public function render( \WP_User $user ): void {
		$filters = array( 'status' => 'pending' );

		if ( ! current_user_can( VacationsCapabilities::MANAGE_ANY ) ) {
			$team_ids = $this->team_user_ids( (int) $user->ID );
			if ( empty( $team_ids ) ) {
				echo '<section class="welow-rrhh__panel-section"><h3 class="welow-rrhh__panel-title">' . esc_html( $this->label() ) . '</h3><p>' . esc_html__( 'No tienes miembros de equipo a tu cargo.', 'welow-rrhh' ) . '</p></section>';
				return;
			}
			$filters['user_ids'] = $team_ids;
		}

		$items = $this->service->repository()->search( $filters, 100, 0 );

		$html = ModuleTemplates::render(
			'tab-team-approvals',
			array(
				'user'  => $user,
				'items' => $items,
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
