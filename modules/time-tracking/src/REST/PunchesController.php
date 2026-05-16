<?php
/**
 * Endpoints REST del módulo Fichajes (§10).
 *
 *   GET    /welow-rrhh/v1/punches/state        — estado actual del usuario.
 *   GET    /welow-rrhh/v1/punches?from&to&user — listado por rango.
 *   POST   /welow-rrhh/v1/punches              — crear evento (rate-limited).
 *   PATCH  /welow-rrhh/v1/punches/{id}         — editar con motivo.
 *
 * @package Welow\RRHH\Modules\TimeTracking\REST
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\REST;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Modules\TimeTracking\Data\EntrySource;
use Welow\RRHH\Modules\TimeTracking\Data\EventType;
use Welow\RRHH\Modules\TimeTracking\Data\TimeEntry;
use Welow\RRHH\Modules\TimeTracking\Service\TimeEntryService;
use Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities;
use Welow\RRHH\REST\AbstractController;

defined( 'ABSPATH' ) || exit;

/**
 * PunchesController.
 */
final class PunchesController extends AbstractController {

	/**
	 * Servicio.
	 *
	 * @var TimeEntryService
	 */
	private TimeEntryService $service;

	/**
	 * Repositorio de empleados (para resolver equipo en GET ajenos).
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Rate limiter del POST.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param TimeEntryService   $service      Servicio de fichajes.
	 * @param EmployeeRepository $employees    Repo empleados.
	 * @param RateLimiter        $rate_limiter Rate limiter.
	 */
	public function __construct(
		TimeEntryService $service,
		EmployeeRepository $employees,
		RateLimiter $rate_limiter
	) {
		$this->service      = $service;
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
			'/punches/state',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_state' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/punches',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_punches' ),
					'permission_callback' => array( $this, 'check_logged_in' ),
					'args'                => array(
						'from'    => array( 'type' => 'string' ),
						'to'      => array( 'type' => 'string' ),
						'user_id' => array( 'type' => 'integer' ),
						'limit'   => array(
							'type'    => 'integer',
							'default' => 200,
							'minimum' => 1,
							'maximum' => 1000,
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
					'callback'            => array( $this, 'create_punch' ),
					'permission_callback' => $this->require_cap( TimeTrackingCapabilities::CREATE_OWN ),
					'args'                => array(
						'event_type' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'punch_in', 'punch_out', 'break_start', 'break_end' ),
						),
						'latitude'   => array( 'type' => 'number' ),
						'longitude'  => array( 'type' => 'number' ),
						'note'       => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/punches/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_punch' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'id'          => array(
						'type'     => 'integer',
						'required' => true,
					),
					'occurred_at' => array( 'type' => 'string' ),
					'event_type'  => array(
						'type' => 'string',
						'enum' => array( 'punch_in', 'punch_out', 'break_start', 'break_end' ),
					),
					'note'        => array( 'type' => 'string' ),
					'edit_reason' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /punches/state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_state( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$user_id = get_current_user_id();
		return $this->ok(
			array(
				'state'   => $this->service->current_state( $user_id ),
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * GET /punches.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_punches( \WP_REST_Request $request ): \WP_REST_Response {
		$current_user_id = get_current_user_id();
		$target_user_id  = (int) ( $request->get_param( 'user_id' ) ?? 0 );

		if ( $target_user_id <= 0 || $target_user_id === $current_user_id ) {
			if ( ! current_user_can( TimeTrackingCapabilities::VIEW_OWN ) ) {
				return $this->error( 'forbidden', __( 'No tienes permisos para ver tus fichajes.', 'welow-rrhh' ), 403 );
			}
			$target_user_id = $current_user_id;
		} else {
			$can_team = current_user_can( TimeTrackingCapabilities::VIEW_TEAM );
			$can_all  = current_user_can( TimeTrackingCapabilities::VIEW_ALL );
			if ( ! $can_all && ! ( $can_team && $this->is_in_team( $current_user_id, $target_user_id ) ) ) {
				return $this->error( 'forbidden', __( 'No tienes permisos para ver fichajes de otros usuarios.', 'welow-rrhh' ), 403 );
			}
		}

		$from = self::parse_date_arg( (string) $request->get_param( 'from' ) );
		$to   = self::parse_date_arg( (string) $request->get_param( 'to' ) );

		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		$entries = $this->service->repository()->find_for_range( $target_user_id, $from, $to, $limit, $offset );

		return $this->ok(
			array(
				'items'   => array_map( array( $this, 'serialize_entry' ), $entries ),
				'user_id' => $target_user_id,
				'from'    => null !== $from ? $from->format( 'Y-m-d' ) : null,
				'to'      => null !== $to ? $to->format( 'Y-m-d' ) : null,
			)
		);
	}

	/**
	 * POST /punches (rate-limited).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_punch( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();

		if ( ! $this->rate_limiter->consume( $user_id ) ) {
			return $this->error(
				'rate_limited',
				__( 'Has excedido el límite de fichajes por minuto. Espera unos segundos.', 'welow-rrhh' ),
				429
			);
		}

		$type = EventType::from_db( (string) $request->get_param( 'event_type' ) );
		if ( null === $type ) {
			return $this->error( 'invalid_event_type', __( 'Tipo de evento no válido.', 'welow-rrhh' ), 400 );
		}

		$context = array(
			'source'    => EntrySource::WEB->value,
			'latitude'  => $request->get_param( 'latitude' ),
			'longitude' => $request->get_param( 'longitude' ),
			'note'      => $request->get_param( 'note' ),
		);

		$result = $this->service->record_event( $user_id, $type, $context );
		if ( is_wp_error( $result ) ) {
			return $this->from_wp_error( $result, 422 );
		}

		return $this->ok(
			array(
				'entry' => $this->serialize_entry( $result ),
				'state' => $this->service->current_state( $user_id ),
			),
			201
		);
	}

	/**
	 * PATCH /punches/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_punch( \WP_REST_Request $request ): \WP_REST_Response {
		$id    = (int) $request->get_param( 'id' );
		$entry = $this->service->repository()->find_by_id( $id );
		if ( null === $entry ) {
			return $this->error( 'not_found', __( 'Evento no encontrado.', 'welow-rrhh' ), 404 );
		}

		$current_user_id = get_current_user_id();
		$is_own          = ( $entry->user_id === $current_user_id );

		if ( $is_own ) {
			if ( ! current_user_can( TimeTrackingCapabilities::EDIT_OWN ) ) {
				return $this->error( 'forbidden', __( 'No tienes permisos para editar tus fichajes.', 'welow-rrhh' ), 403 );
			}
		} elseif ( ! current_user_can( TimeTrackingCapabilities::EDIT_ANY ) ) {
			return $this->error( 'forbidden', __( 'No tienes permisos para editar fichajes ajenos.', 'welow-rrhh' ), 403 );
		}

		$changes = array();
		if ( null !== $request->get_param( 'occurred_at' ) ) {
			$changes['occurred_at'] = (string) $request->get_param( 'occurred_at' );
		}
		if ( null !== $request->get_param( 'event_type' ) ) {
			$changes['event_type'] = (string) $request->get_param( 'event_type' );
		}
		if ( null !== $request->get_param( 'note' ) ) {
			$changes['note'] = (string) $request->get_param( 'note' );
		}

		$reason = (string) $request->get_param( 'edit_reason' );

		$result = $this->service->update_entry( $id, $changes, $current_user_id, $reason );
		if ( is_wp_error( $result ) ) {
			return $this->from_wp_error( $result, 422 );
		}

		return $this->ok( array( 'entry' => $this->serialize_entry( $result ) ) );
	}

	/**
	 * ¿El target está en el equipo directo del current (manager_user_id = current)?
	 *
	 * @param int $current_user_id Manager candidato.
	 * @param int $target_user_id  Usuario objetivo.
	 * @return bool
	 */
	private function is_in_team( int $current_user_id, int $target_user_id ): bool {
		$emp = $this->employees->find_by_user_id( $target_user_id );
		if ( null === $emp ) {
			return false;
		}
		return $emp->manager_user_id === $current_user_id;
	}

	/**
	 * Convierte un TimeEntry a array JSON-serializable.
	 *
	 * @param TimeEntry $e Entrada.
	 * @return array<string, mixed>
	 */
	private function serialize_entry( TimeEntry $e ): array {
		return array(
			'id'          => $e->id,
			'user_id'     => $e->user_id,
			'event_type'  => $e->event_type->value,
			'event_label' => $e->event_type->label(),
			'occurred_at' => $e->occurred_at->format( 'c' ),
			'source'      => $e->source->value,
			'latitude'    => $e->latitude,
			'longitude'   => $e->longitude,
			'note'        => $e->note,
			'is_edited'   => $e->is_edited,
			'edit_reason' => $e->edit_reason,
			'created_at'  => null !== $e->created_at ? $e->created_at->format( 'c' ) : null,
			'updated_at'  => null !== $e->updated_at ? $e->updated_at->format( 'c' ) : null,
		);
	}

	/**
	 * Parsea un parámetro de fecha (YYYY-MM-DD) tolerante a string vacío.
	 *
	 * @param string $value Valor.
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_date_arg( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false === $dt ? null : $dt;
	}
}
