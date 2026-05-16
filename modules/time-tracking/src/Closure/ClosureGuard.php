<?php
/**
 * Engancha el filtro `welow_rrhh/time_tracking/can_edit_entry` para
 * bloquear ediciones/borrados de eventos en meses ya cerrados (§7.4).
 *
 * Excepciones:
 *   - Usuarios con cap welow_close_time_periods (welow_rrhh_admin) pueden
 *     editar mes cerrado, pero con motivo extendido (≥30 caracteres).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Closure
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Closure;

use Welow\RRHH\Modules\TimeTracking\Data\TimeEntry;
use Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * ClosureGuard.
 */
final class ClosureGuard {

	public const MIN_REASON_LENGTH_CLOSED = 30;

	/**
	 * Servicio de cierre.
	 *
	 * @var MonthClosure
	 */
	private MonthClosure $closure;

	/**
	 * Constructor.
	 *
	 * @param MonthClosure $closure Closure service.
	 */
	public function __construct( MonthClosure $closure ) {
		$this->closure = $closure;
	}

	/**
	 * Engancha en filtros del servicio.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'welow_rrhh/time_tracking/can_edit_entry', array( $this, 'check_edit' ), 10, 4 );
		add_filter( 'welow_rrhh/time_tracking/can_delete_entry', array( $this, 'check_delete' ), 10, 3 );
	}

	/**
	 * Bloquea ediciones en mes cerrado salvo admin con motivo extendido.
	 *
	 * @param true|\WP_Error $allowed   Estado previo.
	 * @param TimeEntry      $entry     Evento.
	 * @param int            $editor_id Quién edita.
	 * @param string         $reason    Motivo de edición.
	 * @return true|\WP_Error
	 */
	public function check_edit( $allowed, TimeEntry $entry, int $editor_id, string $reason ) {
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return $this->assert_writable( $entry, $editor_id, $reason );
	}

	/**
	 * Mismas reglas para el borrado.
	 *
	 * @param true|\WP_Error $allowed   Estado previo.
	 * @param TimeEntry      $entry     Evento.
	 * @param int            $actor_id  Quién borra.
	 * @return true|\WP_Error
	 */
	public function check_delete( $allowed, TimeEntry $entry, int $actor_id ) {
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		// Para borrar, también se exige motivo extendido si mes cerrado; el
		// motivo viene en el filtro pero no se propaga aquí — el Service lo
		// chequea en el flujo. Para el guard, basta con bloquear si el mes
		// está cerrado y el actor no puede cerrar periodos.
		return $this->assert_writable( $entry, $actor_id, '' );
	}

	/**
	 * Aplica la lógica de cierre.
	 *
	 * @param TimeEntry $entry     Evento.
	 * @param int       $actor_id  Actor.
	 * @param string    $reason    Motivo.
	 * @return true|\WP_Error
	 */
	private function assert_writable( TimeEntry $entry, int $actor_id, string $reason ) {
		if ( ! $this->closure->is_closed( $entry->occurred_at ) ) {
			return true;
		}

		// Mes cerrado: sólo admin (cap close_time_periods).
		if ( ! user_can( $actor_id, TimeTrackingCapabilities::CLOSE_PERIOD ) ) {
			return new \WP_Error(
				'welow_month_closed',
				__( 'El mes está cerrado. No puedes modificar fichajes de ese periodo.', 'welow-rrhh' )
			);
		}

		// Si llega motivo (edición), exigir longitud mínima.
		if ( '' !== $reason && mb_strlen( trim( $reason ) ) < self::MIN_REASON_LENGTH_CLOSED ) {
			return new \WP_Error(
				'welow_month_closed_reason_short',
				sprintf(
					/* translators: %d: minimum characters. */
					__( 'Edición en mes cerrado: el motivo debe tener al menos %d caracteres.', 'welow-rrhh' ),
					self::MIN_REASON_LENGTH_CLOSED
				)
			);
		}

		return true;
	}
}
