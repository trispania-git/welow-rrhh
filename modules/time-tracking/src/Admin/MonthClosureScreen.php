<?php
/**
 * Pantalla "Cierre de mes" (cap welow_close_time_periods).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Admin;

use Welow\RRHH\Modules\TimeTracking\Closure\MonthClosure;
use Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * MonthClosureScreen.
 */
final class MonthClosureScreen {

	public const PAGE_SLUG   = 'welow-rrhh-time-closure';
	public const SAVE_ACTION = 'welow_rrhh_time_closure_save';
	private const SAVE_NONCE = 'welow_rrhh_time_closure_save_nonce';

	/**
	 * Servicio.
	 *
	 * @var MonthClosure
	 */
	private MonthClosure $closure;

	/**
	 * Constructor.
	 *
	 * @param MonthClosure $closure Servicio.
	 */
	public function __construct( MonthClosure $closure ) {
		$this->closure = $closure;
	}

	/**
	 * Render principal.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( TimeTrackingCapabilities::CLOSE_PERIOD ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		$closed = $this->closure->closed_months();
		$now    = new \DateTimeImmutable( 'now', wp_timezone() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cierre de mes — Fichajes', 'welow-rrhh' ); ?></h1>

			<p><?php esc_html_e( 'Al cerrar un mes, los registros de ese periodo pasan a sólo lectura. Sólo administradores Welow podrán editarlos posteriormente con motivo extendido.', 'welow-rrhh' ); ?></p>

			<h2><?php esc_html_e( 'Cerrar un mes', 'welow-rrhh' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="op" value="close" />
				<?php wp_nonce_field( self::SAVE_NONCE ); ?>
				<p>
					<label>
						<?php esc_html_e( 'Año:', 'welow-rrhh' ); ?>
						<input type="number" name="year" min="2000" max="2100" required value="<?php echo (int) $now->modify( '-1 month' )->format( 'Y' ); ?>" />
					</label>
					<label>
						<?php esc_html_e( 'Mes:', 'welow-rrhh' ); ?>
						<input type="number" name="month" min="1" max="12" required value="<?php echo (int) $now->modify( '-1 month' )->format( 'n' ); ?>" />
					</label>
					<?php submit_button( __( 'Cerrar mes', 'welow-rrhh' ), 'primary', 'submit', false ); ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Meses cerrados', 'welow-rrhh' ); ?></h2>
			<?php if ( empty( $closed ) ) : ?>
				<p><em><?php esc_html_e( 'Aún no se ha cerrado ningún mes.', 'welow-rrhh' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Periodo', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Acción', 'welow-rrhh' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $closed as $period ) : ?>
						<?php list( $y, $m ) = array_map( 'intval', explode( '-', $period ) ); ?>
						<tr>
							<td><strong><?php echo esc_html( $period ); ?></strong></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
									<input type="hidden" name="op" value="reopen" />
									<input type="hidden" name="year" value="<?php echo (int) $y; ?>" />
									<input type="hidden" name="month" value="<?php echo (int) $m; ?>" />
									<?php wp_nonce_field( self::SAVE_NONCE ); ?>
									<input type="text" name="reason" required placeholder="<?php esc_attr_e( 'Motivo (obligatorio)', 'welow-rrhh' ); ?>" />
									<?php submit_button( __( 'Reabrir', 'welow-rrhh' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handler POST.
	 *
	 * @return void
	 */
	public function handle_post_save(): void {
		if ( ! current_user_can( TimeTrackingCapabilities::CLOSE_PERIOD ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );

		$op       = isset( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
		$year     = isset( $post['year'] ) ? (int) $post['year'] : 0;
		$month    = isset( $post['month'] ) ? (int) $post['month'] : 0;
		$actor_id = get_current_user_id();

		if ( 'close' === $op ) {
			$result = $this->closure->close( $year, $month, $actor_id );
		} elseif ( 'reopen' === $op ) {
			$reason = isset( $post['reason'] ) ? (string) $post['reason'] : '';
			$result = $this->closure->reopen( $year, $month, $actor_id, $reason );
		} else {
			$result = new \WP_Error( 'welow_invalid_op', __( 'Operación no válida.', 'welow-rrhh' ) );
		}

		$notice = is_wp_error( $result ) ? 'error' : 'updated';
		$msg    = is_wp_error( $result )
			? $result->get_error_message()
			: ( 'close' === $op ? __( 'Mes cerrado.', 'welow-rrhh' ) : __( 'Mes reabierto.', 'welow-rrhh' ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE_SLUG,
					'welow_tt_notice' => $notice,
					'welow_tt_msg'    => rawurlencode( $msg ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Notices (reusa el patrón de la otra pantalla).
	 *
	 * @return void
	 */
	public function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['welow_tt_notice'] ) ? sanitize_key( (string) $_GET['welow_tt_notice'] ) : '';
		if ( '' === $notice ) {
			return;
		}
		$class = 'updated' === $notice ? 'success' : 'error';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['welow_tt_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['welow_tt_msg'] ) ) : '';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $msg ) );
	}
}
