<?php
/**
 * DTO inmutable de un evento de fichaje (welow_time_entries, §4.2).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Data;

defined( 'ABSPATH' ) || exit;

/**
 * TimeEntry DTO.
 */
final class TimeEntry {

	/**
	 * Constructor.
	 *
	 * @param int|null                $id            PK.
	 * @param int                     $user_id       Usuario al que pertenece el registro.
	 * @param EventType               $event_type    Tipo de evento.
	 * @param \DateTimeImmutable      $occurred_at   Momento del evento.
	 * @param EntrySource             $source        Origen.
	 * @param float|null              $latitude      Latitud.
	 * @param float|null              $longitude     Longitud.
	 * @param string|null             $ip            IP.
	 * @param string|null             $user_agent    User-Agent.
	 * @param string|null             $note          Nota libre.
	 * @param int|null                $attachment_id Adjunto (wp_posts).
	 * @param bool                    $is_edited     true si fue editado tras crearse.
	 * @param int|null                $edited_by     Quién editó.
	 * @param string|null             $edit_reason   Motivo de la edición (obligatorio si is_edited).
	 * @param \DateTimeImmutable|null $created_at    Creación.
	 * @param \DateTimeImmutable|null $updated_at    Última edición.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $user_id,
		public readonly EventType $event_type,
		public readonly \DateTimeImmutable $occurred_at,
		public readonly EntrySource $source,
		public readonly ?float $latitude,
		public readonly ?float $longitude,
		public readonly ?string $ip,
		public readonly ?string $user_agent,
		public readonly ?string $note,
		public readonly ?int $attachment_id,
		public readonly bool $is_edited = false,
		public readonly ?int $edited_by = null,
		public readonly ?string $edit_reason = null,
		public readonly ?\DateTimeImmutable $created_at = null,
		public readonly ?\DateTimeImmutable $updated_at = null
	) {}

	/**
	 * Fecha (sin hora) del evento, útil para agrupar por día.
	 *
	 * @return string Formato Y-m-d.
	 */
	public function date_key(): string {
		return $this->occurred_at->format( 'Y-m-d' );
	}
}
