<?php
/**
 * Endpoints REST del módulo Vacaciones (§10).
 *
 *   GET    /welow-rrhh/v1/vacations/requests              — listado (filtros: user_id, year, status, type).
 *   POST   /welow-rrhh/v1/vacations/requests              — crear solicitud (rate-limited).
 *   PATCH  /welow-rrhh/v1/vacations/requests/{id}         — cancelar / aprobar / rechazar según `action`.
 *   GET    /welow-rrhh/v1/vacations/balance               — saldo del año (?year=YYYY).
 *
 * @package Welow\RRHH\Modules\Vacations\REST
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\REST;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Modules\Vacations\Data\RequestType;
use Welow\RRHH\Modules\Vacations\Data\VacationRequest;
use Welow\RRHH\Modules\Vacations\Service\ApprovalService;
use Welow\RRHH\Modules\Vacations\Service\BalanceCalculator;
use Welow\RRHH\Modules\Vacations\Service\RequestService;
use Welow\RRHH\Modules\Vacations\VacationsCapabilities;
use Welow\RRHH\REST\AbstractController;
use Welow\RRHH\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * VacationsController.
 */
final class VacationsController extends AbstractController {

	/**
	 * Servicio de solicitudes.
	 *
	 * @var RequestService
	 */
	private RequestService $requests;

	/**
	 * Servicio de aprobación.
	 *
	 * @var ApprovalService
	 */
	private ApprovalService $approvals;

	/**
	 * Calculadora de saldo.
	 *
	 * @var BalanceCalculator
	 */
	private BalanceCalculator $calculator;

	/**
	 * Repo empleados (para chequeo de equipo).
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Rate limiter del POST de solicitudes.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param RequestService     $requests     Servicio.
	 * @param ApprovalService    $approvals    Servicio.
	 * @param BalanceCalculator  $calculator   Calculadora.
	 * @param EmployeeRepository $employees    Repo.
	 * @param RateLimiter        $rate_limiter Rate limiter.
	 */
	public function __construct(
		RequestService $requests,
		ApprovalService $approvals,
		BalanceCalculator $calculator,
		EmployeeRepository $employees,
		RateLimiter $rate_limiter
	) {
		$this->requests     = $requests;
		$this->approvals    = $approvals;
		$this->calculator   = $calculator;
		$this->employees    = $employees;
		$this->rate_limiter = $rate_limiter;
	}

	/**
	 * Registra rutas.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/vacations/requests',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_requests' ),
					'permission_callback' => array( $this, 'check_logged_in' ),
					'args'                => array(
						'user_id' => array( 'type' => 'integer' ),
						'year'    => array( 'type' => 'integer' ),
						'status'  => array(
							'type' => 'string',
							'enum' => array( 'pending', 'approved', 'rejected', 'cancelled' ),
						),
						'type'    => array(
							'type' => 'string',
							'enum' => array( 'vacation', 'personal_leave', 'sick', 'unpaid' ),
						),
						'limit'   => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 500,
						),
						'offset'  => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_request' ),
					'permission_callback' => $this->require_cap( VacationsCapabilities::REQUEST_OWN ),
					'args'                => array(
						'start_date'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'end_date'       => array(
							'type'     => 'string',
							'required' => true,
						),
						'type'           => array(
							'type'    => 'string',
							'default' => 'vacation',
							'enum'    => array( 'vacation', 'personal_leave', 'sick', 'unpaid' ),
						),
						'start_half_day' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'end_half_day'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'reason'         => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/vacations/requests/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'patch_request' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'approve', 'reject', 'cancel' ),
					),
					'note'   => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/vacations/balance',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_balance' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'user_id' => array( 'type' => 'integer' ),
					'year'    => array( 'type' => 'integer' ),
				),
			)
		);
	}

	/**
	 * GET /vacations/requests.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_requests( \WP_REST_Request $request ): \WP_REST_Response {
		$current_user_id = get_current_user_id();
		$target_user_id  = (int) ( $request->get_param( 'user_id' ) ?? 0 );

		if ( $target_user_id <= 0 || $target_user_id === $current_user_id ) {
			if ( ! current_user_can( VacationsCapabilities::VIEW_OWN ) ) {
				return $this->error( 'forbidden', __( 'No tienes permisos para ver tus vacaciones.', 'welow-rrhh' ), 403 );
			}
			$target_user_id = $current_user_id;
		} else {
			$can_team = current_user_can( VacationsCapabilities::VIEW_TEAM );
			$can_all  = current_user_can( VacationsCapabilities::VIEW_ALL );
			if ( ! $can_all && ! ( $can_team && $this->is_in_team( $current_user_id, $target_user_id ) ) ) {
				return $this->error( 'forbidden', __( 'No tienes permisos para ver vacaciones de otros usuarios.', 'welow-rrhh' ), 403 );
			}
		}

		$filters = array( 'user_id' => $target_user_id );
		$year    = (int) ( $request->get_param( 'year' ) ?? 0 );
		if ( $year > 0 ) {
			$filters['year'] = $year;
		}
		$status = (string) ( $request->get_param( 'status' ) ?? '' );
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}
		$type = (string) ( $request->get_param( 'type' ) ?? '' );
		if ( '' !== $type ) {
			$filters['type'] = $type;
		}
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		$items = $this->requests->repository()->search( $filters, $limit, $offset );
		$total = $this->requests->repository()->count( $filters );

		return $this->ok(
			array(
				'items'  => array_map( array( $this, 'serialize_request' ), $items ),
				'total'  => $total,
				'limit'  => $limit,
				'offset' => $offset,
			)
		);
	}

	/**
	 * POST /vacations/requests (rate-limited).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_request( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();

		if ( ! $this->rate_limiter->consume( $user_id ) ) {
			return $this->error(
				'rate_limited',
				__( 'Has excedido el límite de solicitudes por minuto. Espera unos segundos.', 'welow-rrhh' ),
				429
			);
		}

		$tz    = wp_timezone();
		$start = self::parse_date_param( (string) $request->get_param( 'start_date' ), $tz );
		$end   = self::parse_date_param( (string) $request->get_param( 'end_date' ), $tz );
		if ( null === $start || null === $end ) {
			return $this->error( 'invalid_date', __( 'Fechas inválidas (formato YYYY-MM-DD).', 'welow-rrhh' ), 400 );
		}

		$type = RequestType::from_db( (string) ( $request->get_param( 'type' ) ?? 'vacation' ) ) ?? RequestType::VACATION;

		$context = array(
			'type'           => $type,
			'start_half_day' => (bool) $request->get_param( 'start_half_day' ),
			'end_half_day'   => (bool) $request->get_param( 'end_half_day' ),
			'reason'         => (string) $request->get_param( 'reason' ),
		);

		$result = $this->requests->create_request( $user_id, $start, $end, $context );
		if ( is_wp_error( $result ) ) {
			return $this->from_wp_error( $result, 422 );
		}

		return $this->ok( array( 'request' => $this->serialize_request( $result ) ), 201 );
	}

	/**
	 * PATCH /vacations/requests/{id}.
	 *
	 * Acciones soportadas:
	 *   - approve / reject: requieren APPROVE_TEAM o MANAGE_ANY.
	 *   - cancel: el propio solicitante con CANCEL_OWN; HR/admin con MANAGE_ANY.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function patch_request( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$action = (string) $request->get_param( 'action' );
		$note   = (string) ( $request->get_param( 'note' ) ?? '' );
		$actor  = get_current_user_id();

		$req = $this->requests->repository()->find_by_id( $id );
		if ( null === $req ) {
			return $this->error( 'not_found', __( 'Solicitud no encontrada.', 'welow-rrhh' ), 404 );
		}

		switch ( $action ) {
			case 'approve':
				if ( ! $this->approvals->can_decide( $actor, $req ) ) {
					return $this->error( 'forbidden', __( 'No tienes permisos para aprobar esta solicitud.', 'welow-rrhh' ), 403 );
				}
				$out = $this->approvals->approve( $id, $actor, $note );
				break;
			case 'reject':
				if ( ! $this->approvals->can_decide( $actor, $req ) ) {
					return $this->error( 'forbidden', __( 'No tienes permisos para rechazar esta solicitud.', 'welow-rrhh' ), 403 );
				}
				$out = $this->approvals->reject( $id, $actor, $note );
				break;
			case 'cancel':
				if ( $req->user_id === $actor ) {
					if ( ! current_user_can( VacationsCapabilities::CANCEL_OWN ) ) {
						return $this->error( 'forbidden', __( 'No tienes permisos para cancelar.', 'welow-rrhh' ), 403 );
					}
				} elseif ( ! current_user_can( VacationsCapabilities::MANAGE_ANY ) ) {
					return $this->error( 'forbidden', __( 'No tienes permisos para cancelar solicitudes ajenas.', 'welow-rrhh' ), 403 );
				}
				$out = $this->requests->cancel_request( $id, $actor, $note );
				break;
			default:
				return $this->error( 'invalid_action', __( 'Acción no soportada.', 'welow-rrhh' ), 400 );
		}

		if ( is_wp_error( $out ) ) {
			return $this->from_wp_error( $out, 422 );
		}

		return $this->ok( array( 'request' => $this->serialize_request( $out ) ) );
	}

	/**
	 * GET /vacations/balance.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_balance( \WP_REST_Request $request ): \WP_REST_Response {
		$current_user_id = get_current_user_id();
		$target_user_id  = (int) ( $request->get_param( 'user_id' ) ?? 0 );

		if ( $target_user_id <= 0 || $target_user_id === $current_user_id ) {
			$target_user_id = $current_user_id;
		} else {
			$can_team = current_user_can( VacationsCapabilities::VIEW_TEAM );
			$can_all  = current_user_can( VacationsCapabilities::VIEW_ALL );
			if ( ! $can_all && ! ( $can_team && $this->is_in_team( $current_user_id, $target_user_id ) ) ) {
				return $this->error( 'forbidden', __( 'No tienes permisos para ver el saldo de otros usuarios.', 'welow-rrhh' ), 403 );
			}
		}

		$year = (int) ( $request->get_param( 'year' ) ?? 0 );
		if ( $year <= 0 ) {
			$year = (int) wp_date( 'Y' );
		}

		$bal       = $this->calculator->recalculate( $target_user_id, $year );
		$available = $this->calculator->available_considering_pending( $target_user_id, $year );

		return $this->ok(
			array(
				'user_id'                => $target_user_id,
				'year'                   => $year,
				'accrued'                => $bal->accrued,
				'used'                   => $bal->used,
				'carried_over_from_prev' => $bal->carried_over_from_prev,
				'carry_over_expires_at'  => null === $bal->carry_over_expires_at ? null : $bal->carry_over_expires_at->format( 'Y-m-d' ),
				'available'              => round( $available, 1 ),
			)
		);
	}

	/**
	 * Serializa una solicitud a array plano.
	 *
	 * @param VacationRequest $req Solicitud.
	 * @return array<string, mixed>
	 */
	private function serialize_request( VacationRequest $req ): array {
		return array(
			'id'             => $req->id,
			'user_id'        => $req->user_id,
			'year'           => $req->year,
			'type'           => $req->type->value,
			'start_date'     => $req->start_date->format( 'Y-m-d' ),
			'end_date'       => $req->end_date->format( 'Y-m-d' ),
			'start_half_day' => $req->start_half_day,
			'end_half_day'   => $req->end_half_day,
			'requested_days' => $req->requested_days,
			'status'         => $req->status->value,
			'reason'         => $req->reason,
			'decided_by'     => $req->decided_by,
			'decided_at'     => null === $req->decided_at ? null : $req->decided_at->format( 'c' ),
			'decision_note'  => $req->decision_note,
			'cancelled_at'   => null === $req->cancelled_at ? null : $req->cancelled_at->format( 'c' ),
			'created_at'     => null === $req->created_at ? null : $req->created_at->format( 'c' ),
		);
	}

	/**
	 * Indica si $target_user_id es miembro del equipo directo de $manager_user_id.
	 *
	 * @param int $manager_user_id Manager.
	 * @param int $target_user_id  Subordinado a comprobar.
	 * @return bool
	 */
	private function is_in_team( int $manager_user_id, int $target_user_id ): bool {
		$emp = $this->employees->find_by_user_id( $target_user_id );
		return null !== $emp && $emp->manager_user_id === $manager_user_id;
	}

	/**
	 * Parsea un parámetro string de fecha YYYY-MM-DD a DateTimeImmutable.
	 *
	 * @param string        $raw Valor crudo.
	 * @param \DateTimeZone $tz  Timezone.
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_date_param( string $raw, \DateTimeZone $tz ): ?\DateTimeImmutable {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $tz );
		return false === $dt ? null : $dt;
	}
}
