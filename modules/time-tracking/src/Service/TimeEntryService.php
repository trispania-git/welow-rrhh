<?php
/**
 * Servicio de fichajes.
 *
 * - record_event() inserta un evento (entrada/salida/pausa) con la lógica
 *   de validación de transiciones y captura de IP/UA.
 * - update_entry() edita un registro existente marcando is_edited y
 *   exigiendo motivo (§7.4).
 * - delete_entry() borra (audit log obligatorio).
 *
 * En 9.B se inyectará el PunchGuard para validar geo/IP previo.
 *
 * @package Welow\RRHH\Modules\TimeTracking\Service
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Service;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Modules\TimeTracking\Data\EntrySource;
use Welow\RRHH\Modules\TimeTracking\Data\EventType;
use Welow\RRHH\Modules\TimeTracking\Data\TimeEntry;
use Welow\RRHH\Modules\TimeTracking\Repository\TimeEntryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * TimeEntryService.
 */
final class TimeEntryService {

	private const AUDIT_ENTITY = 'time_entry';

	/**
	 * Repositorio.
	 *
	 * @var TimeEntryRepository
	 */
	private TimeEntryRepository $repository;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param TimeEntryRepository $repository Repo.
	 * @param AuditLogger         $audit      Audit logger.
	 */
	public function __construct( TimeEntryRepository $repository, AuditLogger $audit ) {
		$this->repository = $repository;
		$this->audit      = $audit;
	}

	/**
	 * Acceso al repositorio (para componentes admin/REST).
	 *
	 * @return TimeEntryRepository
	 */
	public function repository(): TimeEntryRepository {
		return $this->repository;
	}

	/**
	 * Registra un evento de fichaje para el usuario indicado.
	 *
	 * Validaciones aplicadas (§7.1):
	 *   - El próximo evento debe ser coherente con el último: tras un
	 *     punch_in no se permite otro punch_in inmediato sin un punch_out.
	 *   - Permite que módulos externos veten via filtro
	 *     `welow_rrhh/time_tracking/can_punch` (§16).
	 *
	 * @param int                  $user_id    Usuario.
	 * @param EventType            $event_type Tipo.
	 * @param array<string, mixed> $context    Contexto opcional: latitude, longitude, ip,
	 *                                         user_agent, note, attachment_id, source.
	 * @return TimeEntry|\WP_Error
	 */
	public function record_event( int $user_id, EventType $event_type, array $context = array() ) {
		$source = self::resolve_source( $context['source'] ?? null );

		/**
		 * Permite a integradores vetar un fichaje antes de procesarlo.
		 *
		 * Devuelve true para permitir o un WP_Error con código/mensaje para vetar.
		 *
		 * @since 0.1.0
		 *
		 * @param true|\WP_Error      $allowed    true por defecto.
		 * @param int                 $user_id    Usuario.
		 * @param EventType           $event_type Tipo.
		 * @param array<string,mixed> $context    Contexto.
		 */
		$check = apply_filters( 'welow_rrhh/time_tracking/can_punch', true, $user_id, $event_type, $context );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// Validación de coherencia respecto al último evento.
		$last = $this->repository->find_last_for_user( $user_id );
		$err  = self::validate_transition( $last, $event_type );
		if ( null !== $err ) {
			return $err;
		}

		$entry = new TimeEntry(
			null,
			$user_id,
			$event_type,
			new \DateTimeImmutable( 'now', wp_timezone() ),
			$source,
			self::float_or_null( $context['latitude'] ?? null, -90, 90 ),
			self::float_or_null( $context['longitude'] ?? null, -180, 180 ),
			isset( $context['ip'] ) ? (string) $context['ip'] : self::detect_ip(),
			isset( $context['user_agent'] ) ? (string) $context['user_agent'] : self::detect_user_agent(),
			isset( $context['note'] ) ? sanitize_textarea_field( (string) $context['note'] ) : null,
			isset( $context['attachment_id'] ) && $context['attachment_id'] > 0 ? (int) $context['attachment_id'] : null
		);

		try {
			$id = $this->repository->insert_entry( $entry );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_time_entry_insert_failed', $e->getMessage() );
		}

		$created = $this->repository->find_by_id( $id );
		if ( null === $created ) {
			return new \WP_Error( 'welow_time_entry_lookup_failed', __( 'Evento creado pero no recuperable.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'create',
			self::AUDIT_ENTITY,
			$id,
			array(
				'user_id'     => $user_id,
				'event_type'  => $event_type->value,
				'source'      => $source->value,
				'occurred_at' => $created->occurred_at->format( 'c' ),
			)
		);

		/**
		 * Disparado tras registrar un evento de fichaje (§16).
		 *
		 * @since 0.1.0
		 *
		 * @param int       $id         Id del evento.
		 * @param int       $user_id    Usuario.
		 * @param EventType $event_type Tipo.
		 */
		do_action( 'welow_rrhh/punch_created', $id, $user_id, $event_type );

		return $created;
	}

	/**
	 * Edita un evento existente.
	 *
	 * Si edit_reason está vacío, se rechaza (§7.4).
	 *
	 * @param int                  $entry_id    Id.
	 * @param array<string, mixed> $changes     Cambios.
	 * @param int                  $editor_id   Quién edita.
	 * @param string               $edit_reason Motivo (obligatorio).
	 * @return TimeEntry|\WP_Error
	 */
	public function update_entry( int $entry_id, array $changes, int $editor_id, string $edit_reason ) {
		$current = $this->repository->find_by_id( $entry_id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_time_entry_not_found', __( 'Evento no encontrado.', 'welow-rrhh' ) );
		}
		$reason = sanitize_text_field( $edit_reason );
		if ( '' === $reason ) {
			return new \WP_Error( 'welow_time_entry_reason_required', __( 'Indica el motivo de la edición.', 'welow-rrhh' ) );
		}

		$prepared = self::prepare_changes( $changes );
		if ( empty( $prepared ) ) {
			return new \WP_Error( 'welow_time_entry_no_changes', __( 'No se han indicado cambios.', 'welow-rrhh' ) );
		}

		try {
			$this->repository->update_entry( $entry_id, $prepared, true, $editor_id, $reason );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_time_entry_update_failed', $e->getMessage() );
		}

		$updated = $this->repository->find_by_id( $entry_id );

		$this->audit->log(
			'update',
			self::AUDIT_ENTITY,
			$entry_id,
			array(
				'before' => array(
					'occurred_at' => $current->occurred_at->format( 'c' ),
					'event_type'  => $current->event_type->value,
					'note'        => $current->note,
				),
				'after'  => array(
					'occurred_at' => null !== $updated ? $updated->occurred_at->format( 'c' ) : null,
					'event_type'  => null !== $updated ? $updated->event_type->value : null,
					'note'        => null !== $updated ? $updated->note : null,
				),
				'reason' => $reason,
				'editor' => $editor_id,
			)
		);

		return $updated ?? $current;
	}

	/**
	 * Elimina un evento (acción reservada a HR/admin con audit obligatorio).
	 *
	 * @param int    $entry_id Id.
	 * @param int    $actor_id Quién elimina.
	 * @param string $reason   Motivo (obligatorio).
	 * @return true|\WP_Error
	 */
	public function delete_entry( int $entry_id, int $actor_id, string $reason ) {
		$current = $this->repository->find_by_id( $entry_id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_time_entry_not_found', __( 'Evento no encontrado.', 'welow-rrhh' ) );
		}
		$reason_clean = sanitize_text_field( $reason );
		if ( '' === $reason_clean ) {
			return new \WP_Error( 'welow_time_entry_reason_required', __( 'Indica el motivo del borrado.', 'welow-rrhh' ) );
		}
		if ( ! $this->repository->delete_by_id( $entry_id ) ) {
			return new \WP_Error( 'welow_time_entry_delete_failed', __( 'No se pudo eliminar el evento.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'delete',
			self::AUDIT_ENTITY,
			$entry_id,
			array(
				'user_id'     => $current->user_id,
				'event_type'  => $current->event_type->value,
				'occurred_at' => $current->occurred_at->format( 'c' ),
				'reason'      => $reason_clean,
				'actor'       => $actor_id,
			)
		);
		return true;
	}

	/**
	 * Estado actual del usuario (basado en el último evento).
	 *
	 * Devuelve uno de: 'out' (sin fichar/cerrado), 'in' (fichado), 'on_break'.
	 *
	 * @param int $user_id Usuario.
	 * @return string
	 */
	public function current_state( int $user_id ): string {
		$last = $this->repository->find_last_for_user( $user_id );
		if ( null === $last ) {
			return 'out';
		}
		return match ( $last->event_type ) {
			EventType::PUNCH_IN, EventType::BREAK_END => 'in',
			EventType::BREAK_START                    => 'on_break',
			EventType::PUNCH_OUT                      => 'out',
		};
	}

	/**
	 * Validación de coherencia: el nuevo evento debe ser consecuente con el último.
	 *
	 * @param TimeEntry|null $last       Último evento del usuario.
	 * @param EventType      $next       Nuevo evento.
	 * @return \WP_Error|null  Error si la transición es inválida; null si OK.
	 */
	private static function validate_transition( ?TimeEntry $last, EventType $next ): ?\WP_Error {
		$last_type = null !== $last ? $last->event_type : null;

		// Sin eventos previos: solo se admite PUNCH_IN.
		if ( null === $last_type ) {
			if ( EventType::PUNCH_IN === $next ) {
				return null;
			}
			return new \WP_Error( 'welow_time_invalid_transition', __( 'El primer evento debe ser una entrada.', 'welow-rrhh' ) );
		}

		// Transiciones válidas.
		$valid = array(
			'punch_in'    => array( EventType::BREAK_START, EventType::PUNCH_OUT ),
			'break_start' => array( EventType::BREAK_END ),
			'break_end'   => array( EventType::BREAK_START, EventType::PUNCH_OUT ),
			'punch_out'   => array( EventType::PUNCH_IN ),
		);

		$allowed = $valid[ $last_type->value ] ?? array();
		if ( in_array( $next, $allowed, true ) ) {
			return null;
		}

		return new \WP_Error(
			'welow_time_invalid_transition',
			sprintf(
				/* translators: 1: last event label, 2: attempted event label. */
				__( 'Transición no válida: tras "%1$s" no se puede registrar "%2$s".', 'welow-rrhh' ),
				$last_type->label(),
				$next->label()
			)
		);
	}

	/**
	 * Resuelve la fuente del evento desde un valor opcional.
	 *
	 * @param mixed $value Valor.
	 * @return EntrySource
	 */
	private static function resolve_source( $value ): EntrySource {
		if ( $value instanceof EntrySource ) {
			return $value;
		}
		return EntrySource::from_db( null === $value ? null : (string) $value );
	}

	/**
	 * Sanitiza un float opcional dentro de rango.
	 *
	 * @param mixed     $value Valor.
	 * @param int|float $min   Mín.
	 * @param int|float $max   Máx.
	 * @return float|null
	 */
	private static function float_or_null( $value, $min, $max ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$f = (float) $value;
		if ( $f < $min || $f > $max ) {
			return null;
		}
		return $f;
	}

	/**
	 * Filtra el array de cambios a las columnas permitidas para update.
	 *
	 * @param array<string, mixed> $changes Cambios.
	 * @return array<string, mixed>
	 */
	private static function prepare_changes( array $changes ): array {
		$out = array();
		if ( array_key_exists( 'event_type', $changes ) ) {
			$type = $changes['event_type'] instanceof EventType
				? $changes['event_type']
				: EventType::from_db( (string) $changes['event_type'] );
			if ( null !== $type ) {
				$out['event_type'] = $type->value;
			}
		}
		if ( array_key_exists( 'occurred_at', $changes ) ) {
			$dt = $changes['occurred_at'] instanceof \DateTimeInterface
				? $changes['occurred_at']
				: \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $changes['occurred_at'] );
			if ( false !== $dt ) {
				$out['occurred_at'] = $dt->format( 'Y-m-d H:i:s' );
			}
		}
		if ( array_key_exists( 'note', $changes ) ) {
			$out['note'] = null === $changes['note'] ? null : sanitize_textarea_field( (string) $changes['note'] );
		}
		if ( array_key_exists( 'latitude', $changes ) ) {
			$out['latitude'] = self::float_or_null( $changes['latitude'], -90, 90 );
		}
		if ( array_key_exists( 'longitude', $changes ) ) {
			$out['longitude'] = self::float_or_null( $changes['longitude'], -180, 180 );
		}
		if ( array_key_exists( 'attachment_id', $changes ) ) {
			$out['attachment_id'] = null === $changes['attachment_id'] || '' === $changes['attachment_id']
				? null
				: (int) $changes['attachment_id'];
		}
		return $out;
	}

	/**
	 * IP del request (REMOTE_ADDR).
	 *
	 * @return string|null
	 */
	private static function detect_ip(): ?string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}
		$ip = filter_var( sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ), FILTER_VALIDATE_IP );
		return false === $ip ? null : $ip;
	}

	/**
	 * User-Agent truncado.
	 *
	 * @return string|null
	 */
	private static function detect_user_agent(): ?string {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return null;
		}
		$ua = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) );
		$ua = mb_substr( $ua, 0, 255 );
		return '' === $ua ? null : $ua;
	}
}
