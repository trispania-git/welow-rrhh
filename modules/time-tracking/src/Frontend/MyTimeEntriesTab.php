<?php
/**
 * Tab "Mis fichajes" — listado de eventos del usuario con filtro por rango.
 *
 * @package Welow\RRHH\Modules\TimeTracking\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Frontend;

use Welow\RRHH\Frontend\Tabs\TabInterface;
use Welow\RRHH\Modules\TimeTracking\Service\TimeEntryService;
use Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * MyTimeEntriesTab.
 */
final class MyTimeEntriesTab implements TabInterface {

	/**
	 * Servicio.
	 *
	 * @var TimeEntryService
	 */
	private TimeEntryService $service;

	/**
	 * Constructor.
	 *
	 * @param TimeEntryService $service Servicio.
	 */
	public function __construct( TimeEntryService $service ) {
		$this->service = $service;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'my-time-entries';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Mis fichajes', 'welow-rrhh' );
	}

	/**
	 * Indica si el tab es visible para el usuario.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	public function visible_for( \WP_User $user ): bool {
		unset( $user );
		return current_user_can( TimeTrackingCapabilities::VIEW_OWN );
	}

	/**
	 * {@inheritDoc}
	 */
	public function order(): int {
		return 30;
	}

	/**
	 * Render del tab.
	 *
	 * @param \WP_User $user Usuario.
	 * @return void
	 */
	public function render( \WP_User $user ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from_raw = isset( $_GET['welow_from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['welow_from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to_raw = isset( $_GET['welow_to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['welow_to'] ) ) : '';

		$from = self::parse_date( $from_raw );
		$to   = self::parse_date( $to_raw );

		// Defaults: mes en curso.
		if ( null === $from && null === $to ) {
			$now  = new \DateTimeImmutable( 'now', wp_timezone() );
			$from = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
			$to   = $now->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		}

		$entries = $this->service->repository()->find_for_range( $user->ID, $from, $to, 500, 0 );

		$html = ModuleTemplates::render(
			'tab-my-entries',
			array(
				'entries' => $entries,
				'from'    => $from,
				'to'      => $to,
				'totals'  => self::compute_daily_totals( $entries ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Calcula totales (horas trabajadas, pausas) por día, agrupando los eventos.
	 *
	 * Implementación simple: para cada día, suma intervalos entre PUNCH_IN/BREAK_START
	 * y el siguiente BREAK_START/BREAK_END/PUNCH_OUT.
	 *
	 * @param array<int, \Welow\RRHH\Modules\TimeTracking\Data\TimeEntry> $entries Eventos.
	 * @return array<string, array{worked_seconds:int,break_seconds:int}>
	 */
	private static function compute_daily_totals( array $entries ): array {
		$by_day = array();
		foreach ( $entries as $e ) {
			$by_day[ $e->date_key() ][] = $e;
		}
		$totals = array();
		foreach ( $by_day as $day => $events ) {
			$worked = 0;
			$break  = 0;
			$open_t = null;
			$open_w = false;
			foreach ( $events as $ev ) {
				$ts = $ev->occurred_at->getTimestamp();
				switch ( $ev->event_type->value ) {
					case 'punch_in':
					case 'break_end':
						$open_t = $ts;
						$open_w = true;
						break;
					case 'break_start':
						if ( $open_w && null !== $open_t ) {
							$worked += $ts - $open_t;
						}
						$open_t = $ts;
						$open_w = false;
						break;
					case 'punch_out':
						if ( $open_w && null !== $open_t ) {
							$worked += $ts - $open_t;
						}
						$open_t = null;
						$open_w = false;
						break;
				}
			}
			// Para el caso de break sin cierre que cae al final del día, contar como pausa.
			$break_open = null;
			foreach ( $events as $ev ) {
				$ts = $ev->occurred_at->getTimestamp();
				if ( 'break_start' === $ev->event_type->value ) {
					$break_open = $ts;
				}
				if ( 'break_end' === $ev->event_type->value && null !== $break_open ) {
					$break     += $ts - $break_open;
					$break_open = null;
				}
			}
			$totals[ $day ] = array(
				'worked_seconds' => max( 0, $worked ),
				'break_seconds'  => max( 0, $break ),
			);
		}
		return $totals;
	}

	/**
	 * Parsea YYYY-MM-DD.
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
