<?php
/**
 * Servicio de cierre de meses (§7.4 / §7.5).
 *
 * Persiste la lista de meses cerrados en la opción
 * `welow_rrhh_time_tracking_closed_months` (array de cadenas YYYY-MM).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Closure
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Closure;

use Welow\RRHH\Audit\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * MonthClosure.
 */
final class MonthClosure {

	public const OPTION_KEY = 'welow_rrhh_time_tracking_closed_months';

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param AuditLogger $audit Audit logger.
	 */
	public function __construct( AuditLogger $audit ) {
		$this->audit = $audit;
	}

	/**
	 * Lista ordenada de meses cerrados (formato YYYY-MM, descendente).
	 *
	 * @return string[]
	 */
	public function closed_months(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $val ) {
			if ( is_string( $val ) && 1 === preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $val ) ) {
				$out[] = $val;
			}
		}
		$out = array_values( array_unique( $out ) );
		rsort( $out );
		return $out;
	}

	/**
	 * Indica si la fecha cae dentro de un mes cerrado.
	 *
	 * @param \DateTimeImmutable $date Fecha.
	 * @return bool
	 */
	public function is_closed( \DateTimeImmutable $date ): bool {
		return in_array( $date->format( 'Y-m' ), $this->closed_months(), true );
	}

	/**
	 * Cierra un mes.
	 *
	 * @param int $year     Año (e.g. 2026).
	 * @param int $month    Mes (1-12).
	 * @param int $actor_id Quién cierra.
	 * @return true|\WP_Error
	 */
	public function close( int $year, int $month, int $actor_id ) {
		if ( $year < 1900 || $year > 9999 || $month < 1 || $month > 12 ) {
			return new \WP_Error( 'welow_month_closure_invalid', __( 'Año o mes inválido.', 'welow-rrhh' ) );
		}
		$key  = sprintf( '%04d-%02d', $year, $month );
		$list = $this->closed_months();
		if ( in_array( $key, $list, true ) ) {
			return new \WP_Error( 'welow_month_already_closed', __( 'Este mes ya está cerrado.', 'welow-rrhh' ) );
		}
		$list[] = $key;
		rsort( $list );
		update_option( self::OPTION_KEY, $list, false );

		$this->audit->log(
			'month_closed',
			'time_entry',
			null,
			array(
				'period' => $key,
				'actor'  => $actor_id,
			),
			$actor_id
		);

		/**
		 * Disparado tras cerrar un mes (§16).
		 *
		 * @since 0.1.0
		 *
		 * @param int $year   Año.
		 * @param int $month  Mes.
		 * @param int $closed_by Usuario.
		 */
		do_action( 'welow_rrhh/month_closed', $year, $month, $actor_id );

		return true;
	}

	/**
	 * Reabre un mes (sólo admin con motivo).
	 *
	 * @param int    $year     Año.
	 * @param int    $month    Mes.
	 * @param int    $actor_id Actor.
	 * @param string $reason   Motivo (obligatorio).
	 * @return true|\WP_Error
	 */
	public function reopen( int $year, int $month, int $actor_id, string $reason ) {
		$reason = sanitize_text_field( $reason );
		if ( '' === $reason ) {
			return new \WP_Error( 'welow_month_reopen_reason', __( 'Indica el motivo de la reapertura.', 'welow-rrhh' ) );
		}
		$key  = sprintf( '%04d-%02d', $year, $month );
		$list = $this->closed_months();
		if ( ! in_array( $key, $list, true ) ) {
			return new \WP_Error( 'welow_month_not_closed', __( 'Ese mes no está cerrado.', 'welow-rrhh' ) );
		}
		$list = array_values(
			array_filter(
				$list,
				static fn( string $entry ): bool => $entry !== $key
			)
		);
		update_option( self::OPTION_KEY, $list, false );

		$this->audit->log(
			'month_reopened',
			'time_entry',
			null,
			array(
				'period' => $key,
				'actor'  => $actor_id,
				'reason' => $reason,
			),
			$actor_id
		);

		return true;
	}
}
