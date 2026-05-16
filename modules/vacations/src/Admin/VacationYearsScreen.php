<?php
/**
 * Pantalla "Años de vacaciones" en wp-admin.
 *
 * Permite a HR (cap CONFIGURE) declarar, año a año:
 *   - Si está abierto a nuevas solicitudes.
 *   - Fecha límite de envío (deadline).
 *   - Días anuales acreditados (override del default empresa).
 *   - Si los días no usados pueden arrastrarse al año siguiente.
 *   - Máximo de días arrastrables.
 *   - Fecha límite para gastar los días arrastrados (caducidad).
 *
 * @package Welow\RRHH\Modules\Vacations\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Admin;

use Welow\RRHH\Modules\Vacations\Config\VacationYearsConfig;
use Welow\RRHH\Modules\Vacations\Data\VacationYear;
use Welow\RRHH\Modules\Vacations\VacationsCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * VacationYearsScreen.
 */
final class VacationYearsScreen {

	public const PAGE_SLUG   = 'welow-rrhh-vacations-years';
	public const SAVE_ACTION = 'welow_rrhh_vacations_year_save';
	private const SAVE_NONCE = 'welow_rrhh_vacations_year_save_nonce';

	/**
	 * Config.
	 *
	 * @var VacationYearsConfig
	 */
	private VacationYearsConfig $config;

	/**
	 * Constructor.
	 *
	 * @param VacationYearsConfig $config Config.
	 */
	public function __construct( VacationYearsConfig $config ) {
		$this->config = $config;
	}

	/**
	 * Render principal.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( VacationsCapabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		$years = $this->config->all();
		$now   = new \DateTimeImmutable( 'now', wp_timezone() );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Años de vacaciones', 'welow-rrhh' ); ?></h1>
			<p><?php esc_html_e( 'Define qué años están abiertos a solicitudes y la política de arrastre (carry-over) al año siguiente. Un año que no aparezca aquí se considera cerrado.', 'welow-rrhh' ); ?></p>

			<h2><?php esc_html_e( 'Añadir o actualizar un año', 'welow-rrhh' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="welow-rrhh-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="op" value="save" />
				<?php wp_nonce_field( self::SAVE_NONCE ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th><label for="welow-vac-year"><?php esc_html_e( 'Año', 'welow-rrhh' ); ?></label></th>
							<td><input type="number" id="welow-vac-year" name="year" min="2000" max="2100" required value="<?php echo (int) $now->format( 'Y' ); ?>" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Abierto a solicitudes', 'welow-rrhh' ); ?></th>
							<td><label><input type="checkbox" name="is_open" value="1" checked /> <?php esc_html_e( 'Sí', 'welow-rrhh' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="welow-vac-deadline"><?php esc_html_e( 'Fecha límite de solicitud', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="date" id="welow-vac-deadline" name="request_deadline" />
								<p class="description"><?php esc_html_e( 'Opcional. A partir de esta fecha no se admitirán nuevas solicitudes para ese año.', 'welow-rrhh' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="welow-vac-accrual"><?php esc_html_e( 'Días acreditados', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="number" id="welow-vac-accrual" name="accrual_days" min="0" max="365" />
								<p class="description"><?php esc_html_e( 'Opcional. Si se deja vacío se usa el default global de la empresa.', 'welow-rrhh' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Permitir arrastre al año siguiente', 'welow-rrhh' ); ?></th>
							<td><label><input type="checkbox" name="carry_over_enabled" value="1" /> <?php esc_html_e( 'Sí', 'welow-rrhh' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="welow-vac-carry-max"><?php esc_html_e( 'Máximo a arrastrar (días)', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="number" id="welow-vac-carry-max" name="carry_over_max_days" min="0" max="365" />
								<p class="description"><?php esc_html_e( 'Opcional. Vacío = sin tope.', 'welow-rrhh' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="welow-vac-carry-deadline"><?php esc_html_e( 'Caducidad de los días arrastrados', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="date" id="welow-vac-carry-deadline" name="carry_over_deadline" />
								<p class="description"><?php esc_html_e( 'Opcional. Última fecha en la que pueden disfrutarse los días arrastrados desde el año anterior. Pasada esa fecha desaparecen del saldo.', 'welow-rrhh' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Guardar año', 'welow-rrhh' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Años configurados', 'welow-rrhh' ); ?></h2>
			<?php if ( empty( $years ) ) : ?>
				<p><em><?php esc_html_e( 'Aún no has configurado ningún año.', 'welow-rrhh' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Año', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Abierto', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Deadline solicitud', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Días acred.', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Carry-over', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Acciones', 'welow-rrhh' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $years as $y ) : ?>
							<tr>
								<td><strong><?php echo (int) $y->year; ?></strong></td>
								<td><?php echo $y->is_open ? '✅' : '❌'; ?></td>
								<td><?php echo esc_html( null === $y->request_deadline ? '—' : $y->request_deadline->format( 'Y-m-d' ) ); ?></td>
								<td><?php echo esc_html( null === $y->accrual_days ? __( 'default empresa', 'welow-rrhh' ) : (string) $y->accrual_days ); ?></td>
								<td>
									<?php if ( ! $y->carry_over_enabled ) : ?>
										<?php esc_html_e( 'No', 'welow-rrhh' ); ?>
									<?php else : ?>
										<?php
										$max      = null === $y->carry_over_max_days ? __( 'sin tope', 'welow-rrhh' ) : (string) $y->carry_over_max_days;
										$deadline = null === $y->carry_over_deadline ? __( 'sin caducidad', 'welow-rrhh' ) : $y->carry_over_deadline->format( 'Y-m-d' );
										/* translators: 1: max days, 2: deadline date. */
										echo esc_html( sprintf( __( 'máx %1$s · caducan %2$s', 'welow-rrhh' ), $max, $deadline ) );
										?>
									<?php endif; ?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar la configuración de este año?', 'welow-rrhh' ) ); ?>');">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
										<input type="hidden" name="op" value="delete" />
										<input type="hidden" name="year" value="<?php echo (int) $y->year; ?>" />
										<?php wp_nonce_field( self::SAVE_NONCE ); ?>
										<?php submit_button( __( 'Eliminar', 'welow-rrhh' ), 'delete small', 'submit', false ); ?>
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
		if ( ! current_user_can( VacationsCapabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );

		$op   = isset( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
		$year = isset( $post['year'] ) ? (int) $post['year'] : 0;

		$result = null;

		if ( 'delete' === $op ) {
			$ok     = $this->config->remove( $year );
			$result = $ok ? true : new \WP_Error( 'welow_vac_delete_failed', __( 'No se pudo eliminar.', 'welow-rrhh' ) );
			$msg_ok = __( 'Año eliminado.', 'welow-rrhh' );
		} elseif ( 'save' === $op ) {
			$tz       = wp_timezone();
			$req      = isset( $post['request_deadline'] ) ? (string) $post['request_deadline'] : '';
			$car      = isset( $post['carry_over_deadline'] ) ? (string) $post['carry_over_deadline'] : '';
			$accr_raw = isset( $post['accrual_days'] ) ? trim( (string) $post['accrual_days'] ) : '';
			$max_raw  = isset( $post['carry_over_max_days'] ) ? trim( (string) $post['carry_over_max_days'] ) : '';

			$cfg = new VacationYear(
				$year,
				! empty( $post['is_open'] ),
				self::parse_date( $req, $tz ),
				'' === $accr_raw ? null : (int) $accr_raw,
				! empty( $post['carry_over_enabled'] ),
				'' === $max_raw ? null : (int) $max_raw,
				self::parse_date( $car, $tz )
			);
			if ( $year < 2000 || $year > 2100 ) {
				$result = new \WP_Error( 'welow_vac_invalid_year', __( 'Año fuera de rango.', 'welow-rrhh' ) );
			} else {
				$this->config->save( $cfg );
				$result = true;
			}
			$msg_ok = __( 'Año guardado.', 'welow-rrhh' );
		} else {
			$result = new \WP_Error( 'welow_vac_invalid_op', __( 'Operación no válida.', 'welow-rrhh' ) );
			$msg_ok = '';
		}

		$notice = is_wp_error( $result ) ? 'error' : 'updated';
		$msg    = is_wp_error( $result ) ? $result->get_error_message() : $msg_ok;

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
	 * Notices.
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
	 * Parsea fecha YYYY-MM-DD a DateTimeImmutable.
	 *
	 * @param string        $raw Valor.
	 * @param \DateTimeZone $tz  Timezone.
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_date( string $raw, \DateTimeZone $tz ): ?\DateTimeImmutable {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $tz );
		return false === $dt ? null : $dt;
	}
}
