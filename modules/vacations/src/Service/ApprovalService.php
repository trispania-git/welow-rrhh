<?php
/**
 * Servicio de aprobación de solicitudes de vacaciones (§8.3).
 *
 * Aprueba o rechaza solicitudes PENDING. Una vez aprobada, recalcula el
 * saldo materializado del empleado para que `used` refleje el consumo
 * real. Dispara los actions `welow_rrhh/vacation_request_approved` y
 * `welow_rrhh/vacation_request_rejected` para que VacationNotifications
 * (10.C) avise al solicitante por email.
 *
 * Esta primera iteración implementa aprobación de un único paso: cualquier
 * usuario con APPROVE_TEAM (sobre miembros de su equipo directo) o
 * MANAGE_ANY (cualquier solicitud) puede decidir.
 *
 * TODO(welow): aprobación multi-nivel basada en
 * settings.vacations.approval_flow (manager_direct → hr → ...). Por ahora
 * sólo se honra `approval_flow` para listar quién debe ser notificado en
 * primer lugar.
 *
 * @package Welow\RRHH\Modules\Vacations\Service
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Service;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Modules\Vacations\Data\RequestStatus;
use Welow\RRHH\Modules\Vacations\Data\VacationRequest;
use Welow\RRHH\Modules\Vacations\Repository\VacationRequestRepository;
use Welow\RRHH\Modules\Vacations\VacationsCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * ApprovalService.
 */
final class ApprovalService {

	private const AUDIT_ENTITY = 'vacation_request';

	/**
	 * Repo solicitudes.
	 *
	 * @var VacationRequestRepository
	 */
	private VacationRequestRepository $requests;

	/**
	 * Calculadora (para recalcular saldo tras aprobar).
	 *
	 * @var BalanceCalculator
	 */
	private BalanceCalculator $calculator;

	/**
	 * Repo empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param VacationRequestRepository $requests   Repo.
	 * @param BalanceCalculator         $calculator Calculadora.
	 * @param EmployeeRepository        $employees  Repo empleados.
	 * @param AuditLogger               $audit      Audit.
	 */
	public function __construct(
		VacationRequestRepository $requests,
		BalanceCalculator $calculator,
		EmployeeRepository $employees,
		AuditLogger $audit
	) {
		$this->requests   = $requests;
		$this->calculator = $calculator;
		$this->employees  = $employees;
		$this->audit      = $audit;
	}

	/**
	 * Aprueba una solicitud pendiente.
	 *
	 * @param int    $request_id  Id solicitud.
	 * @param int    $approver_id WP_User que aprueba.
	 * @param string $note        Nota opcional.
	 * @return VacationRequest|\WP_Error
	 */
	public function approve( int $request_id, int $approver_id, string $note = '' ) {
		$req = $this->guard_decidable( $request_id, $approver_id );
		if ( is_wp_error( $req ) ) {
			return $req;
		}

		$now = current_time( 'mysql' );
		$ok  = $this->requests->update_request(
			$request_id,
			array(
				'status'        => RequestStatus::APPROVED->value,
				'decided_by'    => $approver_id,
				'decided_at'    => $now,
				'decision_note' => sanitize_text_field( $note ),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'welow_vacation_approve_failed', __( 'No se pudo aprobar la solicitud.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'approve',
			self::AUDIT_ENTITY,
			$request_id,
			array(
				'approver_id' => $approver_id,
				'note'        => $note,
			),
			$approver_id
		);

		// Recalcula el saldo materializado: `used` debe reflejar la nueva APPROVED.
		$this->calculator->recalculate( $req->user_id, $req->year );

		$reloaded = $this->requests->find_by_id( $request_id );

		/**
		 * Disparado tras aprobar una solicitud.
		 *
		 * @since 0.1.0
		 *
		 * @param int                  $request_id Id.
		 * @param int                  $approver_id Aprobador.
		 * @param VacationRequest|null $request    Solicitud reciente.
		 */
		do_action( 'welow_rrhh/vacation_request_approved', $request_id, $approver_id, $reloaded );

		return null !== $reloaded ? $reloaded : new \WP_Error( 'welow_vacation_lookup_failed', '' );
	}

	/**
	 * Rechaza una solicitud pendiente.
	 *
	 * @param int    $request_id  Id.
	 * @param int    $approver_id Aprobador.
	 * @param string $reason      Motivo (recomendado, no obligatorio).
	 * @return VacationRequest|\WP_Error
	 */
	public function reject( int $request_id, int $approver_id, string $reason = '' ) {
		$req = $this->guard_decidable( $request_id, $approver_id );
		if ( is_wp_error( $req ) ) {
			return $req;
		}

		$now = current_time( 'mysql' );
		$ok  = $this->requests->update_request(
			$request_id,
			array(
				'status'        => RequestStatus::REJECTED->value,
				'decided_by'    => $approver_id,
				'decided_at'    => $now,
				'decision_note' => sanitize_text_field( $reason ),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'welow_vacation_reject_failed', __( 'No se pudo rechazar la solicitud.', 'welow-rrhh' ) );
		}

		$this->audit->log(
			'reject',
			self::AUDIT_ENTITY,
			$request_id,
			array(
				'approver_id' => $approver_id,
				'reason'      => $reason,
			),
			$approver_id
		);

		$reloaded = $this->requests->find_by_id( $request_id );

		/**
		 * Disparado tras rechazar una solicitud.
		 *
		 * @since 0.1.0
		 *
		 * @param int                  $request_id Id.
		 * @param int                  $approver_id Aprobador.
		 * @param VacationRequest|null $request    Solicitud reciente.
		 */
		do_action( 'welow_rrhh/vacation_request_rejected', $request_id, $approver_id, $reloaded );

		return null !== $reloaded ? $reloaded : new \WP_Error( 'welow_vacation_lookup_failed', '' );
	}

	/**
	 * Indica si el usuario puede decidir sobre la solicitud dada.
	 *
	 * Regla: MANAGE_ANY → siempre; APPROVE_TEAM → si el solicitante es
	 * miembro de su equipo directo (manager_user_id apunta al aprobador).
	 *
	 * @param int             $approver_id WP_User aprobador.
	 * @param VacationRequest $request     Solicitud.
	 * @return bool
	 */
	public function can_decide( int $approver_id, VacationRequest $request ): bool {
		if ( user_can( $approver_id, VacationsCapabilities::MANAGE_ANY ) ) {
			return true;
		}
		if ( ! user_can( $approver_id, VacationsCapabilities::APPROVE_TEAM ) ) {
			return false;
		}
		$emp = $this->employees->find_by_user_id( $request->user_id );
		if ( null === $emp ) {
			return false;
		}
		return $emp->manager_user_id === $approver_id;
	}

	/**
	 * Lista los WP_User IDs que deben ser notificados cuando se crea una
	 * solicitud para `$user_id`.
	 *
	 * Estrategia:
	 *   1) Si el solicitante tiene manager_user_id, ése es el destinatario
	 *      principal.
	 *   2) Si no, se notifica a todos los WP_User con rol welow_hr o
	 *      welow_rrhh_admin (capacidad MANAGE_ANY).
	 *
	 * No incluye al propio solicitante.
	 *
	 * @param int $user_id Solicitante.
	 * @return int[] IDs únicos.
	 */
	public function recipients_for_new_request( int $user_id ): array {
		$emp = $this->employees->find_by_user_id( $user_id );
		if ( null !== $emp && null !== $emp->manager_user_id && $emp->manager_user_id !== $user_id ) {
			return array( (int) $emp->manager_user_id );
		}
		// Fallback HR/admin.
		$ids = get_users(
			array(
				'role__in' => array( 'welow_hr', 'welow_rrhh_admin' ),
				'fields'   => 'ID',
				'number'   => 50,
			)
		);
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );
		$ids = array_values( array_filter( $ids, static fn( int $id ): bool => $id !== $user_id ) );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Valida que la solicitud existe, está PENDING y el aprobador puede decidir.
	 *
	 * @param int $request_id  Id.
	 * @param int $approver_id Aprobador.
	 * @return VacationRequest|\WP_Error
	 */
	private function guard_decidable( int $request_id, int $approver_id ) {
		$req = $this->requests->find_by_id( $request_id );
		if ( null === $req ) {
			return new \WP_Error( 'welow_vacation_not_found', __( 'Solicitud no encontrada.', 'welow-rrhh' ) );
		}
		if ( RequestStatus::PENDING !== $req->status ) {
			return new \WP_Error( 'welow_vacation_not_pending', __( 'Sólo se pueden decidir solicitudes pendientes.', 'welow-rrhh' ) );
		}
		if ( ! $this->can_decide( $approver_id, $req ) ) {
			return new \WP_Error( 'welow_vacation_not_authorized', __( 'No tienes permisos para decidir esta solicitud.', 'welow-rrhh' ) );
		}
		return $req;
	}
}
