<?php
/**
 * DTO inmutable del saldo anual de vacaciones de un empleado.
 *
 * Mapea welow_vacation_balances; uno por (user_id, año). El saldo
 * disponible se calcula con `available()` restando lo usado al acreditado
 * más el carry-over vigente.
 *
 * @package Welow\RRHH\Modules\Vacations\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Vacation balance DTO.
 */
final class VacationBalance {

	/**
	 * Constructor.
	 *
	 * @param int|null                $id                     PK.
	 * @param int                     $user_id                Usuario.
	 * @param int                     $year                   Año.
	 * @param float                   $accrued                Días acreditados ese año.
	 * @param float                   $used                   Días consumidos por solicitudes APPROVED.
	 * @param float                   $carried_over_from_prev Días arrastrados desde año-1.
	 * @param \DateTimeImmutable|null $carry_over_expires_at  Vencimiento del carry-over (inclusive).
	 * @param \DateTimeImmutable|null $updated_at             Última actualización.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $user_id,
		public readonly int $year,
		public readonly float $accrued,
		public readonly float $used,
		public readonly float $carried_over_from_prev,
		public readonly ?\DateTimeImmutable $carry_over_expires_at,
		public readonly ?\DateTimeImmutable $updated_at = null
	) {}

	/**
	 * Devuelve el saldo disponible sin considerar pendientes.
	 *
	 * Si la fecha actual ya superó carry_over_expires_at, los días
	 * arrastrados desde el año previo no se cuentan.
	 *
	 * @param \DateTimeImmutable|null $today Fecha de referencia (default: hoy).
	 * @return float
	 */
	public function available( ?\DateTimeImmutable $today = null ): float {
		$today             = $today ?? new \DateTimeImmutable( 'now', wp_timezone() );
		$carry_over_active = null === $this->carry_over_expires_at || $today <= $this->carry_over_expires_at;
		$carry             = $carry_over_active ? $this->carried_over_from_prev : 0.0;
		return $this->accrued + $carry - $this->used;
	}
}
