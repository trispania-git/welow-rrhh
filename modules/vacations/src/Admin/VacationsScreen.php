<?php
/**
 * Pantalla "Vacaciones" en wp-admin: lista de solicitudes con filtros +
 * acciones aprobar/rechazar/cancelar.
 *
 * @package Welow\RRHH\Modules\Vacations\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Admin;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Modules\Vacations\Data\VacationRequest;
use Welow\RRHH\Modules\Vacations\Service\ApprovalService;
use Welow\RRHH\Modules\Vacations\Service\BalanceCalculator;
use Welow\RRHH\Modules\Vacations\Service\RequestService;
use Welow\RRHH\Modules\Vacations\VacationsCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * VacationsScreen.
 */
final class VacationsScreen {

	public const PAGE_SLUG   = 'welow-rrhh-vacations';
	public const SAVE_ACTION = 'welow_rrhh_vacations_decide';
	private const SAVE_NONCE = 'welow_rrhh_vacations_decide_nonce';

	/**
	 * Servicio solicitudes.
	 *
	 * @var RequestService
	 */
	private RequestService $requests;

	/**
	 * Servicio aprobación.
	 *
	 * @var ApprovalService
	 */
	private ApprovalService $approvals;

	/**
	 * Calculadora.
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
	 * Constructor.
	 *
	 * @param RequestService     $requests   Servicio.
	 * @param ApprovalService    $approvals  Servicio.
	 * @param BalanceCalculator  $calculator Calculadora.
	 * @param EmployeeRepository $employees  Repo.
	 */
	public function __construct(
		RequestService $requests,
		ApprovalService $approvals,
		BalanceCalculator $calculator,
		EmployeeRepository $employees
	) {
		$this->requests   = $requests;
		$this->approvals  = $approvals;
		$this->calculator = $calculator;
		$this->employees  = $employees;
	}

	/**
	 * Render principal.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( VacationsCapabilities::VIEW_TEAM ) && ! current_user_can( VacationsCapabilities::MANAGE_ANY ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_user = isset( $_GET['emp_id'] ) ? (int) $_GET['emp_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_year = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) wp_date( 'Y' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';

		$filters = array( 'year' => $filter_year );
		if ( $filter_user > 0 ) {
			$filters['user_id'] = $filter_user;
		} elseif ( ! current_user_can( VacationsCapabilities::MANAGE_ANY ) && ! current_user_can( VacationsCapabilities::VIEW_ALL ) ) {
			// Sin permisos globales: restringe al equipo directo.
			$current             = get_current_user_id();
			$team                = $this->employees->search( array( 'manager_user_id' => $current ), 1, 200 )['items'] ?? array();
			$ids                 = array_map( static fn( $e ): int => (int) $e->user_id, $team );
			$ids[]               = $current;
			$filters['user_ids'] = array_values( array_unique( $ids ) );
		}
		if ( '' !== $filter_status ) {
			$filters['status'] = $filter_status;
		}

		$items = $this->requests->repository()->search( $filters, 200, 0 );

		$years_avail = array();
		$cur_year    = (int) wp_date( 'Y' );
		$min_year    = $cur_year - 3;
		for ( $y = $cur_year + 1; $y >= $min_year; $y-- ) {
			$years_avail[] = $y;
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Vacaciones', 'welow-rrhh' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . VacationYearsScreen::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Configurar años', 'welow-rrhh' ); ?></a>
			<hr class="wp-header-end">

			<form method="get" class="welow-rrhh-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label>
					<?php esc_html_e( 'Empleado (User ID):', 'welow-rrhh' ); ?>
					<input type="number" name="emp_id" value="<?php echo (int) $filter_user; ?>" min="0" />
				</label>
				<label>
					<?php esc_html_e( 'Año:', 'welow-rrhh' ); ?>
					<select name="year">
						<?php foreach ( $years_avail as $y ) : ?>
							<option value="<?php echo (int) $y; ?>" <?php selected( $filter_year, $y ); ?>><?php echo (int) $y; ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Estado:', 'welow-rrhh' ); ?>
					<select name="status">
						<option value=""><?php esc_html_e( 'Todos', 'welow-rrhh' ); ?></option>
						<?php
						$statuses = array(
							'pending'   => __( 'Pendiente', 'welow-rrhh' ),
							'approved'  => __( 'Aprobada', 'welow-rrhh' ),
							'rejected'  => __( 'Rechazada', 'welow-rrhh' ),
							'cancelled' => __( 'Cancelada', 'welow-rrhh' ),
						);
						foreach ( $statuses as $val => $lbl ) :
							?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_status, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php submit_button( __( 'Filtrar', 'welow-rrhh' ), 'secondary small', '', false ); ?>
			</form>

			<?php if ( $filter_user > 0 ) : ?>
				<?php
				$bal       = $this->calculator->recalculate( $filter_user, $filter_year );
				$available = $this->calculator->available_considering_pending( $filter_user, $filter_year );
				?>
				<p>
					<strong><?php esc_html_e( 'Saldo del empleado:', 'welow-rrhh' ); ?></strong>
					<?php
					/* translators: 1: accrued, 2: used, 3: carry-over, 4: available. */
					$tpl = __( 'Acreditado %1$s · Usado %2$s · Arrastrado %3$s · Disponible %4$s', 'welow-rrhh' );
					echo esc_html(
						sprintf(
							$tpl,
							self::n( $bal->accrued ),
							self::n( $bal->used ),
							self::n( $bal->carried_over_from_prev ),
							self::n( $available )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php $this->render_table( $items ); ?>
		</div>
		<?php
	}

	/**
	 * Render tabla de items.
	 *
	 * @param VacationRequest[] $items Items.
	 * @return void
	 */
	private function render_table( array $items ): void {
		$date_format = (string) get_option( 'date_format', 'Y-m-d' );
		if ( empty( $items ) ) {
			echo '<p><em>' . esc_html__( 'No hay solicitudes que coincidan con el filtro.', 'welow-rrhh' ) . '</em></p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Empleado', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Desde', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Hasta', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Días', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'welow-rrhh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $r ) : ?>
					<?php
					$u = get_userdata( $r->user_id );
					?>
					<tr>
						<td>#<?php echo (int) $r->id; ?></td>
						<td><?php echo esc_html( $u ? $u->display_name : '#' . $r->user_id ); ?></td>
						<td><?php echo esc_html( $r->type->label() ); ?></td>
						<td><?php echo esc_html( wp_date( $date_format, $r->start_date->getTimestamp() ) ); ?></td>
						<td><?php echo esc_html( wp_date( $date_format, $r->end_date->getTimestamp() ) ); ?></td>
						<td><?php echo esc_html( self::n( $r->requested_days ) ); ?></td>
						<td>
							<strong><?php echo esc_html( $r->status->label() ); ?></strong>
							<?php if ( '' !== (string) $r->decision_note ) : ?>
								<br><small><?php echo esc_html( $r->decision_note ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'pending' === $r->status->value && $this->approvals->can_decide( (int) get_current_user_id(), $r ) ) : ?>
								<?php $this->render_action_form( $r->id, 'approve', __( 'Aprobar', 'welow-rrhh' ), 'primary small' ); ?>
								<?php $this->render_action_form( $r->id, 'reject', __( 'Rechazar', 'welow-rrhh' ), 'secondary small', true ); ?>
							<?php endif; ?>
							<?php if ( in_array( $r->status->value, array( 'pending', 'approved' ), true ) && current_user_can( VacationsCapabilities::MANAGE_ANY ) ) : ?>
								<?php $this->render_action_form( $r->id, 'cancel', __( 'Cancelar', 'welow-rrhh' ), 'secondary small', true ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render formulario inline para una acción concreta.
	 *
	 * @param int    $id           Id solicitud.
	 * @param string $op           approve|reject|cancel.
	 * @param string $label        Etiqueta del botón.
	 * @param string $btn_class    Clase del botón.
	 * @param bool   $with_note    Si pide nota.
	 * @return void
	 */
	private function render_action_form( int $id, string $op, string $label, string $btn_class, bool $with_note = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:4px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
			<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>" />
			<input type="hidden" name="request_id" value="<?php echo (int) $id; ?>" />
			<?php wp_nonce_field( self::SAVE_NONCE ); ?>
			<?php if ( $with_note ) : ?>
				<input type="text" name="note" placeholder="<?php esc_attr_e( 'Motivo (opcional)', 'welow-rrhh' ); ?>" style="width:140px;" />
			<?php endif; ?>
			<?php submit_button( $label, $btn_class, 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handler POST de las acciones.
	 *
	 * @return void
	 */
	public function handle_post_save(): void {
		check_admin_referer( self::SAVE_NONCE );

		// Guard temprano: cualquier acción requiere al menos VIEW_TEAM o MANAGE_ANY.
		// El service revalida con can_decide(), pero detenemos aquí a usuarios sin
		// permisos básicos para no exponer códigos de error a su input.
		if ( ! current_user_can( VacationsCapabilities::VIEW_TEAM )
			&& ! current_user_can( VacationsCapabilities::MANAGE_ANY ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );

		$op    = isset( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
		$id    = isset( $post['request_id'] ) ? (int) $post['request_id'] : 0;
		$note  = isset( $post['note'] ) ? (string) $post['note'] : '';
		$actor = get_current_user_id();

		$result = null;

		switch ( $op ) {
			case 'approve':
			case 'reject':
				if ( ! current_user_can( VacationsCapabilities::APPROVE_TEAM )
					&& ! current_user_can( VacationsCapabilities::MANAGE_ANY ) ) {
					$result = new \WP_Error( 'forbidden', __( 'No tienes permisos para decidir.', 'welow-rrhh' ) );
					break;
				}
				$result = 'approve' === $op
					? $this->approvals->approve( $id, $actor, $note )
					: $this->approvals->reject( $id, $actor, $note );
				break;
			case 'cancel':
				if ( ! current_user_can( VacationsCapabilities::MANAGE_ANY ) ) {
					$result = new \WP_Error( 'forbidden', __( 'No tienes permisos.', 'welow-rrhh' ) );
					break;
				}
				$result = $this->requests->cancel_request( $id, $actor, $note );
				break;
			default:
				$result = new \WP_Error( 'invalid_op', __( 'Operación no válida.', 'welow-rrhh' ) );
		}

		$notice = is_wp_error( $result ) ? 'error' : 'updated';
		$msg    = is_wp_error( $result )
			? $result->get_error_message()
			: __( 'Solicitud actualizada.', 'welow-rrhh' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'welow_vac_notice' => $notice,
					'welow_vac_msg'    => rawurlencode( $msg ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Notices a partir de los query args.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['welow_vac_notice'] ) ? sanitize_key( (string) $_GET['welow_vac_notice'] ) : '';
		if ( '' === $notice ) {
			return;
		}
		$class = 'updated' === $notice ? 'success' : 'error';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['welow_vac_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['welow_vac_msg'] ) ) : '';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $msg ) );
	}

	/**
	 * Formato numérico compacto (entero si lo es).
	 *
	 * @param float $v Valor.
	 * @return string
	 */
	private static function n( float $v ): string {
		if ( abs( $v - round( $v ) ) < 0.001 ) {
			return (string) (int) round( $v );
		}
		return rtrim( rtrim( number_format( $v, 1, '.', '' ), '0' ), '.' );
	}
}
