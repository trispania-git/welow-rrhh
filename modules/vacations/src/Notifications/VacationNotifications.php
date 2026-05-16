<?php
/**
 * Disparador de emails para los eventos del módulo Vacaciones (§6 / §8.3).
 *
 * Escucha los actions emitidos por RequestService y ApprovalService y
 * delega en el Dispatcher central. Sólo email en esta primera iteración;
 * los integradores pueden añadir más canales con el filtro
 * `welow_rrhh/notifications/channels`.
 *
 * Tipos emitidos al Dispatcher:
 *   - vacation_request_submitted (a approver/HR)
 *   - vacation_request_approved  (al solicitante)
 *   - vacation_request_rejected  (al solicitante)
 *   - vacation_request_cancelled (a approver/HR; informativo)
 *
 * El payload sigue la convención del template genérico:
 *   title, body, action_url, action_label, request, requester_id.
 *
 * @package Welow\RRHH\Modules\Vacations\Notifications
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Notifications;

use Welow\RRHH\Modules\Vacations\Data\VacationRequest;
use Welow\RRHH\Modules\Vacations\Service\ApprovalService;
use Welow\RRHH\Notifications\Dispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Vacation notifications integrator.
 */
final class VacationNotifications {

	/**
	 * Dispatcher.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * Approval service (para resolver destinatarios manager/HR).
	 *
	 * @var ApprovalService
	 */
	private ApprovalService $approvals;

	/**
	 * Constructor.
	 *
	 * @param Dispatcher      $dispatcher Dispatcher.
	 * @param ApprovalService $approvals  Approval service.
	 */
	public function __construct( Dispatcher $dispatcher, ApprovalService $approvals ) {
		$this->dispatcher = $dispatcher;
		$this->approvals  = $approvals;
	}

	/**
	 * Engancha los actions del módulo.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'welow_rrhh/vacation_request_created', array( $this, 'on_created' ), 10, 3 );
		add_action( 'welow_rrhh/vacation_request_approved', array( $this, 'on_approved' ), 10, 3 );
		add_action( 'welow_rrhh/vacation_request_rejected', array( $this, 'on_rejected' ), 10, 3 );
		add_action( 'welow_rrhh/vacation_request_cancelled', array( $this, 'on_cancelled' ), 10, 2 );
	}

	/**
	 * Notifica a los aprobadores cuando se crea una solicitud.
	 *
	 * @param int             $request_id Id.
	 * @param int             $user_id    Solicitante.
	 * @param VacationRequest $request    Solicitud.
	 * @return void
	 */
	public function on_created( int $request_id, int $user_id, VacationRequest $request ): void {
		$recipients = $this->approvals->recipients_for_new_request( $user_id );
		if ( empty( $recipients ) ) {
			return;
		}
		$requester = get_userdata( $user_id );
		$name      = $requester ? $requester->display_name : '#' . $user_id;
		$range     = $this->format_range( $request );

		$payload = array(
			'title'        => sprintf(
				/* translators: %s: requester name. */
				__( 'Nueva solicitud de vacaciones de %s', 'welow-rrhh' ),
				$name
			),
			'body'         => sprintf(
				/* translators: 1: requester name, 2: date range, 3: days. */
				__( '%1$s ha solicitado vacaciones del %2$s (%3$s).', 'welow-rrhh' ),
				$name,
				$range,
				self::days_label( $request->requested_days )
			),
			'action_url'   => admin_url( 'admin.php?page=welow-rrhh-vacations&action=view&id=' . $request_id ),
			'action_label' => __( 'Revisar solicitud', 'welow-rrhh' ),
			'request'      => $request,
			'requester_id' => $user_id,
		);

		foreach ( $recipients as $rid ) {
			$this->dispatcher->send( $rid, 'vacation_request_submitted', $payload );
		}
	}

	/**
	 * Notifica al solicitante cuando se aprueba.
	 *
	 * @param int                  $request_id Id.
	 * @param int                  $approver_id Aprobador.
	 * @param VacationRequest|null $request    Solicitud (puede ser null si lookup falló).
	 * @return void
	 */
	public function on_approved( int $request_id, int $approver_id, ?VacationRequest $request ): void {
		if ( null === $request ) {
			return;
		}
		$approver = get_userdata( $approver_id );
		$by       = $approver ? $approver->display_name : '#' . $approver_id;
		$range    = $this->format_range( $request );

		$payload = array(
			'title'        => __( 'Tu solicitud de vacaciones ha sido aprobada', 'welow-rrhh' ),
			'body'         => sprintf(
				/* translators: 1: date range, 2: days, 3: approver name. */
				__( 'Tu solicitud del %1$s (%2$s) ha sido aprobada por %3$s.', 'welow-rrhh' ),
				$range,
				self::days_label( $request->requested_days ),
				$by
			),
			'action_url'   => home_url( '/' ),
			'action_label' => __( 'Ver mis vacaciones', 'welow-rrhh' ),
			'request'      => $request,
		);
		$this->dispatcher->send( $request->user_id, 'vacation_request_approved', $payload );
	}

	/**
	 * Notifica al solicitante cuando se rechaza.
	 *
	 * @param int                  $request_id Id.
	 * @param int                  $approver_id Aprobador.
	 * @param VacationRequest|null $request    Solicitud.
	 * @return void
	 */
	public function on_rejected( int $request_id, int $approver_id, ?VacationRequest $request ): void {
		if ( null === $request ) {
			return;
		}
		$approver = get_userdata( $approver_id );
		$by       = $approver ? $approver->display_name : '#' . $approver_id;
		$range    = $this->format_range( $request );
		$reason   = (string) ( $request->decision_note ?? '' );

		$body = sprintf(
			/* translators: 1: date range, 2: days, 3: approver name. */
			__( 'Tu solicitud del %1$s (%2$s) ha sido rechazada por %3$s.', 'welow-rrhh' ),
			$range,
			self::days_label( $request->requested_days ),
			$by
		);
		if ( '' !== $reason ) {
			$body .= "\n\n" . sprintf(
				/* translators: %s: reason. */
				__( 'Motivo: %s', 'welow-rrhh' ),
				$reason
			);
		}

		$this->dispatcher->send(
			$request->user_id,
			'vacation_request_rejected',
			array(
				'title'        => __( 'Tu solicitud de vacaciones ha sido rechazada', 'welow-rrhh' ),
				'body'         => $body,
				'action_url'   => home_url( '/' ),
				'action_label' => __( 'Ver mis vacaciones', 'welow-rrhh' ),
				'request'      => $request,
			)
		);
	}

	/**
	 * Notifica a los aprobadores que el solicitante canceló.
	 *
	 * @param int $request_id Id.
	 * @param int $actor_id   Actor (no usado; queda para futura traza/auditoría en email).
	 * @return void
	 */
	public function on_cancelled( int $request_id, int $actor_id ): void {
		unset( $actor_id ); // PHPCS guard: param honors la firma del action pero no se usa hoy.
		// Carga la solicitud para conocer al solicitante.
		$req = welow_rrhh()->container()->get( 'vacations.request_repository' )->find_by_id( $request_id );
		if ( null === $req ) {
			return;
		}
		$recipients = $this->approvals->recipients_for_new_request( $req->user_id );
		if ( empty( $recipients ) ) {
			return;
		}
		$requester = get_userdata( $req->user_id );
		$name      = $requester ? $requester->display_name : '#' . $req->user_id;
		$range     = $this->format_range( $req );

		$payload = array(
			'title'        => sprintf(
				/* translators: %s: requester name. */
				__( 'Cancelada la solicitud de vacaciones de %s', 'welow-rrhh' ),
				$name
			),
			'body'         => sprintf(
				/* translators: 1: requester name, 2: date range. */
				__( '%1$s ha cancelado su solicitud del %2$s.', 'welow-rrhh' ),
				$name,
				$range
			),
			'action_url'   => admin_url( 'admin.php?page=welow-rrhh-vacations' ),
			'action_label' => __( 'Ver solicitudes', 'welow-rrhh' ),
			'request'      => $req,
			'requester_id' => $req->user_id,
		);
		foreach ( $recipients as $rid ) {
			$this->dispatcher->send( $rid, 'vacation_request_cancelled', $payload );
		}
	}

	/**
	 * Formatea el rango de fechas para uso en email.
	 *
	 * @param VacationRequest $request Solicitud.
	 * @return string
	 */
	private function format_range( VacationRequest $request ): string {
		$fmt = get_option( 'date_format', 'Y-m-d' );
		if ( ! is_string( $fmt ) || '' === $fmt ) {
			$fmt = 'Y-m-d';
		}
		if ( $request->is_single_day() ) {
			return wp_date( $fmt, $request->start_date->getTimestamp() );
		}
		return sprintf(
			/* translators: 1: start date, 2: end date. */
			__( '%1$s al %2$s', 'welow-rrhh' ),
			wp_date( $fmt, $request->start_date->getTimestamp() ),
			wp_date( $fmt, $request->end_date->getTimestamp() )
		);
	}

	/**
	 * Formato decimal compacto de días (sin .0 cuando es entero).
	 *
	 * @param float $days Días.
	 * @return string
	 */
	private static function days_label( float $days ): string {
		if ( abs( $days - round( $days ) ) < 0.001 ) {
			return sprintf(
				/* translators: %d: days. */
				_n( '%d día', '%d días', (int) $days, 'welow-rrhh' ),
				(int) $days
			);
		}
		return rtrim( rtrim( number_format( $days, 1, '.', '' ), '0' ), '.' ) . ' ' . __( 'días', 'welow-rrhh' );
	}
}
