<?php
/**
 * Tab "Fichar" — widget de entrada/salida/pausa.
 *
 * Sólo se muestra a usuarios con cap welow_create_own_time_entries.
 *
 * @package Welow\RRHH\Modules\TimeTracking\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Frontend;

use Welow\RRHH\Frontend\Tabs\TabInterface;
use Welow\RRHH\Modules\TimeTracking\Data\EventType;
use Welow\RRHH\Modules\TimeTracking\Policy\PunchPolicyResolver;
use Welow\RRHH\Modules\TimeTracking\Service\TimeEntryService;
use Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * PunchTab.
 */
final class PunchTab implements TabInterface {

	/**
	 * Servicio.
	 *
	 * @var TimeEntryService
	 */
	private TimeEntryService $service;

	/**
	 * Resolutor de política (para saber si exige geo).
	 *
	 * @var PunchPolicyResolver
	 */
	private PunchPolicyResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param TimeEntryService    $service  Servicio.
	 * @param PunchPolicyResolver $resolver Resolver.
	 */
	public function __construct( TimeEntryService $service, PunchPolicyResolver $resolver ) {
		$this->service  = $service;
		$this->resolver = $resolver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'punch';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Fichar', 'welow-rrhh' );
	}

	/**
	 * Indica si el tab es visible para el usuario.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function visible_for( \WP_User $user ): bool {
		unset( $user );
		return current_user_can( TimeTrackingCapabilities::CREATE_OWN );
	}

	/**
	 * {@inheritDoc}
	 */
	public function order(): int {
		return 20;
	}

	/**
	 * Render del tab.
	 *
	 * @param \WP_User $user Usuario.
	 * @return void
	 */
	public function render( \WP_User $user ): void {
		$state  = $this->service->current_state( $user->ID );
		$last   = $this->service->repository()->find_last_for_user( $user->ID );
		$policy = $this->resolver->for_user( $user->ID );

		$next_actions = self::actions_for_state( $state );

		$html = ModuleTemplates::render(
			'tab-punch',
			array(
				'user'         => $user,
				'state'        => $state,
				'last'         => $last,
				'next_actions' => $next_actions,
				'require_geo'  => $policy->require_geo,
			)
		);
		// Las plantillas escapan con esc_html/esc_attr/esc_url internamente.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Acciones permitidas según el estado.
	 *
	 * @param string $state out|in|on_break.
	 * @return array<int, array{event:EventType,label:string,style:string}>
	 */
	private static function actions_for_state( string $state ): array {
		switch ( $state ) {
			case 'in':
				return array(
					array(
						'event' => EventType::BREAK_START,
						'label' => __( 'Iniciar pausa', 'welow-rrhh' ),
						'style' => 'secondary',
					),
					array(
						'event' => EventType::PUNCH_OUT,
						'label' => __( 'Salida', 'welow-rrhh' ),
						'style' => 'primary',
					),
				);
			case 'on_break':
				return array(
					array(
						'event' => EventType::BREAK_END,
						'label' => __( 'Reanudar', 'welow-rrhh' ),
						'style' => 'primary',
					),
				);
			default:
				return array(
					array(
						'event' => EventType::PUNCH_IN,
						'label' => __( 'Entrada', 'welow-rrhh' ),
						'style' => 'primary',
					),
				);
		}
	}
}
