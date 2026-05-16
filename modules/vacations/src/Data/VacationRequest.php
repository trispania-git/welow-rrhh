<?php
/**
 * DTO inmutable de una solicitud de vacaciones.
 *
 * Mapea la fila de welow_vacation_requests. Los días solicitados se
 * almacenan en `requested_days` como cache para listas, pero el cálculo
 * canónico siempre se hace con el rango + flags de medio día por el
 * RequestService.
 *
 * @package Welow\RRHH\Modules\Vacations\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Vacation request DTO.
 */
final class VacationRequest {

	/**
	 * Constructor.
	 *
	 * @param int|null                $id             PK; null si aún no persistido.
	 * @param int                     $user_id        FK lógica a wp_users.ID.
	 * @param int                     $year           Año al que se imputa (start_date->Y).
	 * @param RequestType             $type           Tipo de ausencia.
	 * @param \DateTimeImmutable      $start_date     Fecha inicio (date, sin tiempo).
	 * @param \DateTimeImmutable      $end_date       Fecha fin (inclusive, date sin tiempo).
	 * @param bool                    $start_half_day Si true, el primer día empieza por la tarde (-0.5d).
	 * @param bool                    $end_half_day   Si true, el último día termina por la mañana (-0.5d).
	 * @param float                   $requested_days Días totales descontados al saldo (cache).
	 * @param RequestStatus           $status         Estado.
	 * @param string|null             $reason         Motivo / comentario del solicitante.
	 * @param int|null                $decided_by     Usuario que aprobó/rechazó.
	 * @param \DateTimeImmutable|null $decided_at     Timestamp de decisión.
	 * @param string|null             $decision_note  Nota del aprobador/rechazador.
	 * @param \DateTimeImmutable|null $cancelled_at   Timestamp de cancelación.
	 * @param \DateTimeImmutable|null $created_at     Timestamp de creación.
	 * @param \DateTimeImmutable|null $updated_at     Timestamp de última edición.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $user_id,
		public readonly int $year,
		public readonly RequestType $type,
		public readonly \DateTimeImmutable $start_date,
		public readonly \DateTimeImmutable $end_date,
		public readonly bool $start_half_day,
		public readonly bool $end_half_day,
		public readonly float $requested_days,
		public readonly RequestStatus $status,
		public readonly ?string $reason,
		public readonly ?int $decided_by = null,
		public readonly ?\DateTimeImmutable $decided_at = null,
		public readonly ?string $decision_note = null,
		public readonly ?\DateTimeImmutable $cancelled_at = null,
		public readonly ?\DateTimeImmutable $created_at = null,
		public readonly ?\DateTimeImmutable $updated_at = null
	) {}

	/**
	 * Indica si la solicitud abarca un solo día.
	 *
	 * @return bool
	 */
	public function is_single_day(): bool {
		return $this->start_date->format( 'Y-m-d' ) === $this->end_date->format( 'Y-m-d' );
	}

	/**
	 * Devuelve true si el rango solapa con otra solicitud (mismo usuario).
	 *
	 * Útil para validaciones; no consulta repositorio.
	 *
	 * @param self $other Otra solicitud.
	 * @return bool
	 */
	public function overlaps_with( self $other ): bool {
		return $this->start_date <= $other->end_date && $other->start_date <= $this->end_date;
	}
}
