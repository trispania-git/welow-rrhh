<?php
/**
 * Tab "Mis vacaciones" — saldo + listado propio + formulario de solicitud.
 *
 * @package Welow\RRHH\Modules\Vacations\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Frontend;

use Welow\RRHH\Frontend\Tabs\TabInterface;
use Welow\RRHH\Modules\Vacations\Config\VacationYearsConfig;
use Welow\RRHH\Modules\Vacations\Service\BalanceCalculator;
use Welow\RRHH\Modules\Vacations\Service\RequestService;
use Welow\RRHH\Modules\Vacations\VacationsCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * MyVacationsTab.
 */
final class MyVacationsTab implements TabInterface {

	/**
	 * Servicio.
	 *
	 * @var RequestService
	 */
	private RequestService $service;

	/**
	 * Calculadora de saldo.
	 *
	 * @var BalanceCalculator
	 */
	private BalanceCalculator $calculator;

	/**
	 * Config años.
	 *
	 * @var VacationYearsConfig
	 */
	private VacationYearsConfig $years;

	/**
	 * Constructor.
	 *
	 * @param RequestService      $service    Servicio.
	 * @param BalanceCalculator   $calculator Calculadora.
	 * @param VacationYearsConfig $years      Config años.
	 */
	public function __construct( RequestService $service, BalanceCalculator $calculator, VacationYearsConfig $years ) {
		$this->service    = $service;
		$this->calculator = $calculator;
		$this->years      = $years;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'vacations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Mis vacaciones', 'welow-rrhh' );
	}

	/**
	 * Visible para cualquiera con VIEW_OWN.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function visible_for( \WP_User $user ): bool {
		unset( $user );
		return current_user_can( VacationsCapabilities::VIEW_OWN );
	}

	/**
	 * {@inheritDoc}
	 */
	public function order(): int {
		return 30;
	}

	/**
	 * Render.
	 *
	 * @param \WP_User $user Usuario.
	 * @return void
	 */
	public function render( \WP_User $user ): void {
		$year      = (int) wp_date( 'Y' );
		$balance   = $this->calculator->recalculate( (int) $user->ID, $year );
		$available = $this->calculator->available_considering_pending( (int) $user->ID, $year );
		$requests  = $this->service->repository()->find_for_user_year( (int) $user->ID, $year );
		$year_cfg  = $this->years->get( $year );
		$can_req   = current_user_can( VacationsCapabilities::REQUEST_OWN ) && null !== $year_cfg && $year_cfg->is_open;

		$html = ModuleTemplates::render(
			'tab-my-vacations',
			array(
				'user'        => $user,
				'year'        => $year,
				'balance'     => $balance,
				'available'   => $available,
				'requests'    => $requests,
				'year_cfg'    => $year_cfg,
				'can_request' => $can_req,
				'can_cancel'  => current_user_can( VacationsCapabilities::CANCEL_OWN ),
				'today'       => ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0, 0 ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
