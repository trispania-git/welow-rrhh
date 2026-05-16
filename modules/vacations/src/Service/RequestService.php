<?php
/**
 * Servicio de solicitudes de vacaciones (§8.2 / §8.3).
 *
 * Encapsula la creación y cancelación de solicitudes aplicando todas las
 * validaciones de negocio (año abierto, deadline, solapes, saldo, mínimo
 * preaviso, máximo consecutivo). La aprobación/rechazo vive en
 * ApprovalService (10.C) — aquí sólo se gestiona el ciclo del propio
 * solicitante.
 *
 * @package Welow\RRHH\Modules\Vacations\Service
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Service;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Modules\Vacations\Config\VacationYearsConfig;
use Welow\RRHH\Modules\Vacations\Data\RequestStatus;
use Welow\RRHH\Modules\Vacations\Data\RequestType;
use Welow\RRHH\Modules\Vacations\Data\VacationRequest;
use Welow\RRHH\Modules\Vacations\Repository\VacationRequestRepository;
use Welow\RRHH\Settings\CompanySettings;

defined( 'ABSPATH' ) || exit;

/**
 * RequestService.
 */
final class RequestService {

	private const AUDIT_ENTITY = 'vacation_request';

	/**
	 * Repo solicitudes.
	 *
	 * @var VacationRequestRepository
	 */
	private VacationRequestRepository $repository;

	/**
	 * Calculadora.
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
	 * Settings empresa.
	 *
	 * @var CompanySettings
	 */
	private CompanySettings $settings;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param VacationRequestRepository $repository Repo solicitudes.
	 * @param BalanceCalculator         $calculator Calculadora.
	 * @param VacationYearsConfig       $years      Config años.
	 * @param CompanySettings           $settings   Settings.
	 * @param AuditLogger               $audit      Audit logger.
	 */
	public function __construct(
		VacationRequestRepository $repository,
		BalanceCalculator $calculator,
		VacationYearsConfig $years,
		CompanySettings $settings,
		AuditLogger $audit
	) {
		$this->repository = $repository;
		$this->calculator = $calculator;
		$this->years      = $years;
		$this->settings   = $settings;
		$this->audit      = $audit;
	}

	/**
	 * Acceso al repositorio (para componentes admin/REST).
	 *
	 * @return VacationRequestRepository
	 */
	public function repository(): VacationRequestRepository {
		return $this->repository;
	}

	/**
	 * Acceso a la calculadora.
	 *
	 * @return BalanceCalculator
	 */
	public function calculator(): BalanceCalculator {
		return $this->calculator;
	}

	/**
	 * Crea una nueva solicitud aplicando todas las validaciones.
	 *
	 * Contexto admisible:
	 *   - type:           RequestType (default VACATION).
	 *   - reason:         string|null.
	 *   - start_half_day: bool.
	 *   - end_half_day:   bool.
	 *   - allow_past:     bool (sólo HR/admin; sortea check de fecha pasada).
	 *
	 * @param int                  $user_id Solicitante.
	 * @param \DateTimeImmutable   $start   Inicio.
	 * @param \DateTimeImmutable   $end     Fin.
	 * @param array<string, mixed> $context Opciones.
	 * @return VacationRequest|\WP_Error
	 */
	public function create_request(
		int $user_id,
		\DateTimeImmutable $start,
		\DateTimeImmutable $end,
		array $context = array()
	) {
		$type           = isset( $context['type'] ) && $context['type'] instanceof RequestType ? $context['type'] : RequestType::VACATION;
		$reason         = isset( $context['reason'] ) ? sanitize_textarea_field( (string) $context['reason'] ) : null;
		$reason         = '' === $reason ? null : $reason;
		$start_half_day = ! empty( $context['start_half_day'] );
		$end_half_day   = ! empty( $context['end_half_day'] );
		$allow_past     = ! empty( $context['allow_past'] );

		$start = $start->setTime( 0, 0, 0 );
		$end   = $end->setTime( 0, 0, 0 );

		if ( $end < $start ) {
			return new \WP_Error( 'welow_vacation_invalid_range', __( 'La fecha de fin debe ser igual o posterior a la de inicio.', 'welow-rrhh' ) );
		}

		// Por simplicidad, todas las solicitudes deben caer dentro del mismo año.
		if ( $start->format( 'Y' ) !== $end->format( 'Y' ) ) {
			return new \WP_Error( 'welow_vacation_cross_year', __( 'No se permiten solicitudes que crucen dos años. Divídelas en dos.', 'welow-rrhh' ) );
		}
		$year = (int) $start->format( 'Y' );

		// Año abierto.
		if ( ! $this->years->is_open( $year ) ) {
			return new \WP_Error( 'welow_vacation_year_closed', __( 'El año seleccionado no está abierto para solicitar vacaciones.', 'welow-rrhh' ) );
		}

		// Deadline de solicitud.
		$year_cfg = $this->years->get( $year );
		$today    = new \DateTimeImmutable( 'now', wp_timezone() );
		if ( null !== $year_cfg && null !== $year_cfg->request_deadline && $today->setTime( 0, 0, 0 ) > $year_cfg->request_deadline ) {
			return new \WP_Error(
				'welow_vacation_deadline_passed',
				sprintf(
					/* translators: %s: deadline date (Y-m-d). */
					__( 'La fecha límite para solicitar vacaciones de ese año (%s) ya ha pasado.', 'welow-rrhh' ),
					$year_cfg->request_deadline->format( 'Y-m-d' )
				)
			);
		}

		// Fecha pasada (excepto allow_past).
		if ( ! $allow_past && $start < $today->setTime( 0, 0, 0 ) ) {
			return new \WP_Error( 'welow_vacation_past_date', __( 'No puedes solicitar vacaciones para fechas pasadas.', 'welow-rrhh' ) );
		}

		$vacations_cfg = $this->settings->section( CompanySettings::SECTION_VACATIONS );

		// Preaviso mínimo (sólo para VACATION).
		$notice_days = (int) ( $vacations_cfg['min_request_notice_days'] ?? 0 );
		if ( RequestType::VACATION === $type && $notice_days > 0 && ! $allow_past ) {
			$min_start = $today->setTime( 0, 0, 0 )->modify( '+' . $notice_days . ' day' );
			if ( $start < $min_start ) {
				return new \WP_Error(
					'welow_vacation_min_notice',
					sprintf(
						/* translators: %d: minimum notice in days. */
						__( 'Debes solicitar con al menos %d día(s) de antelación.', 'welow-rrhh' ),
						$notice_days
					)
				);
			}
		}

		// Días solicitados y máximo consecutivo.
		$requested_days = $this->calculator->compute_requested_days( $start, $end, $start_half_day, $end_half_day );
		if ( $requested_days <= 0 ) {
			return new \WP_Error( 'welow_vacation_zero_days', __( 'El rango seleccionado no incluye ningún día computable.', 'welow-rrhh' ) );
		}
		$max_consecutive = (int) ( $vacations_cfg['max_consecutive_days'] ?? 0 );
		if ( $max_consecutive > 0 && $requested_days > $max_consecutive ) {
			return new \WP_Error(
				'welow_vacation_max_consecutive',
				sprintf(
					/* translators: %d: max consecutive days. */
					__( 'Una solicitud no puede superar %d días consecutivos.', 'welow-rrhh' ),
					$max_consecutive
				)
			);
		}

		// Solapamiento con otras pending/approved.
		$overlap = $this->repository->find_active_overlapping( $user_id, $start, $end );
		if ( ! empty( $overlap ) ) {
			return new \WP_Error( 'welow_vacation_overlap', __( 'Ya tienes una solicitud activa que se solapa con estas fechas.', 'welow-rrhh' ) );
		}

		// Saldo suficiente sólo si el tipo consume saldo.
		if ( $type->consumes_balance() ) {
			$available = $this->calculator->available_considering_pending( $user_id, $year );
			if ( $requested_days > $available + 0.001 ) {
				return new \WP_Error(
					'welow_vacation_no_balance',
					sprintf(
						/* translators: 1: requested days; 2: available days. */
						__( 'Saldo insuficiente: solicitas %1$s días y tienes %2$s disponibles.', 'welow-rrhh' ),
						self::days_label( $requested_days ),
						self::days_label( $available )
					)
				);
			}
		}

		// Filtro extensibilidad: cualquier WP_Error veta.
		/**
		 * Permite a integradores vetar una solicitud antes de crearla.
		 *
		 * @since 0.1.0
		 *
		 * @param true|\WP_Error      $allowed         True por defecto; WP_Error para vetar.
		 * @param int                 $user_id         Solicitante.
		 * @param RequestType         $type            Tipo.
		 * @param \DateTimeImmutable  $start           Inicio.
		 * @param \DateTimeImmutable  $end             Fin.
		 * @param float               $requested_days  Días calculados.
		 * @param array<string,mixed> $context         Contexto recibido.
		 */
		$check = apply_filters( 'welow_rrhh/vacations/can_request', true, $user_id, $type, $start, $end, $requested_days, $context );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$request = new VacationRequest(
			null,
			$user_id,
			$year,
			$type,
			$start,
			$end,
			$start_half_day,
			$end_half_day,
			$requested_days,
			RequestStatus::PENDING,
			$reason
		);

		try {
			$id = $this->repository->insert_request( $request );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_vacation_insert_failed', $e->getMessage() );
		}

		$created = $this->repository->find_by_id( $id );
		if ( null === $created ) {
			return new \WP_Error( 'welow_vacation_lookup_failed', __( 'Solicitud creada pero no recuperable.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'create',
			self::AUDIT_ENTITY,
			$id,
			array(
				'user_id'        => $user_id,
				'type'           => $type->value,
				'year'           => $year,
				'start_date'     => $start->format( 'Y-m-d' ),
				'end_date'       => $end->format( 'Y-m-d' ),
				'requested_days' => $requested_days,
			)
		);

		/**
		 * Disparado tras crear una solicitud (estado PENDING).
		 *
		 * @since 0.1.0
		 *
		 * @param int             $id      Id solicitud.
		 * @param int             $user_id Solicitante.
		 * @param VacationRequest $request Solicitud creada.
		 */
		do_action( 'welow_rrhh/vacation_request_created', $id, $user_id, $created );

		return $created;
	}

	/**
	 * Cancela una solicitud. PENDING siempre; APPROVED sólo si aún no ha empezado.
	 *
	 * @param int    $request_id Id.
	 * @param int    $actor_id   Quién cancela.
	 * @param string $reason     Motivo (libre).
	 * @return VacationRequest|\WP_Error
	 */
	public function cancel_request( int $request_id, int $actor_id, string $reason = '' ) {
		$req = $this->repository->find_by_id( $request_id );
		if ( null === $req ) {
			return new \WP_Error( 'welow_vacation_not_found', __( 'Solicitud no encontrada.', 'welow-rrhh' ) );
		}
		if ( RequestStatus::PENDING !== $req->status && RequestStatus::APPROVED !== $req->status ) {
			return new \WP_Error( 'welow_vacation_not_cancellable', __( 'Sólo se pueden cancelar solicitudes pendientes o aprobadas.', 'welow-rrhh' ) );
		}
		if ( RequestStatus::APPROVED === $req->status ) {
			$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0, 0 );
			if ( $req->start_date <= $today ) {
				return new \WP_Error( 'welow_vacation_already_started', __( 'No se puede cancelar una solicitud que ya ha comenzado.', 'welow-rrhh' ) );
			}
		}

		$now = current_time( 'mysql' );
		$ok  = $this->repository->update_request(
			$request_id,
			array(
				'status'        => RequestStatus::CANCELLED->value,
				'cancelled_at'  => $now,
				'decision_note' => sanitize_text_field( $reason ),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'welow_vacation_cancel_failed', __( 'No se pudo cancelar la solicitud.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'cancel',
			self::AUDIT_ENTITY,
			$request_id,
			array(
				'actor_id' => $actor_id,
				'reason'   => $reason,
			)
		);

		// Si era APPROVED, el saldo materializado deja de consumirlos: recalcula.
		if ( RequestStatus::APPROVED === $req->status ) {
			$this->calculator->recalculate( $req->user_id, $req->year );
		}

		/**
		 * Disparado al cancelar una solicitud.
		 *
		 * @since 0.1.0
		 *
		 * @param int $request_id Id.
		 * @param int $actor_id   Actor.
		 */
		do_action( 'welow_rrhh/vacation_request_cancelled', $request_id, $actor_id );

		$reloaded = $this->repository->find_by_id( $request_id );
		return null !== $reloaded ? $reloaded : new \WP_Error( 'welow_vacation_lookup_failed', '' );
	}

	/**
	 * Formatea un valor decimal de días con como máximo un decimal.
	 *
	 * @param float $days Días.
	 * @return string
	 */
	private static function days_label( float $days ): string {
		if ( abs( $days - round( $days ) ) < 0.001 ) {
			return (string) (int) round( $days );
		}
		return rtrim( rtrim( number_format( $days, 1, '.', '' ), '0' ), '.' );
	}
}
