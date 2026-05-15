<?php
/**
 * Pantalla wp-admin de gestión de festivos.
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Holidays\HolidayService;
use Welow\RRHH\Roles\Capabilities;
use Welow\RRHH\Support\Data\Holiday;
use Welow\RRHH\Support\Data\HolidayScope;

defined( 'ABSPATH' ) || exit;

/**
 * Pantalla de festivos.
 */
final class HolidaysScreen {

	public const PAGE_SLUG   = 'welow-rrhh-holidays';
	public const SAVE_ACTION = 'welow_rrhh_holiday_save';
	private const SAVE_NONCE = 'welow_rrhh_holiday_save_nonce';

	/**
	 * Servicio.
	 *
	 * @var HolidayService
	 */
	private HolidayService $service;

	/**
	 * Constructor.
	 *
	 * @param HolidayService $service Servicio.
	 */
	public function __construct( HolidayService $service ) {
		$this->service = $service;
	}

	/**
	 * Procesa acciones GET (delete).
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( 'delete' !== $action ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( $id <= 0 ) {
			return;
		}

		if ( ! current_user_can( Capabilities::CAP_MANAGE_HOLIDAYS ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'welow_rrhh_holiday_delete_' . $id );

		$result = $this->service->delete( $id );
		$notice = is_wp_error( $result ) ? 'error' : 'deleted';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE_SLUG,
					'welow_rrhh_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handler del POST de save.
	 *
	 * @return void
	 */
	public function handle_post_save(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_HOLIDAYS ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );

		$id   = isset( $post['holiday_id'] ) ? (int) $post['holiday_id'] : 0;
		$data = array(
			'date'  => isset( $post['date'] ) ? (string) $post['date'] : '',
			'name'  => isset( $post['name'] ) ? (string) $post['name'] : '',
			'scope' => isset( $post['scope'] ) ? (string) $post['scope'] : '',
		);

		$result = $id > 0 ? $this->service->update( $id, $data ) : $this->service->create( $data );
		$notice = is_wp_error( $result ) ? 'error' : ( $id > 0 ? 'updated' : 'created' );

		$args = array(
			'page'              => self::PAGE_SLUG,
			'welow_rrhh_notice' => $notice,
		);
		if ( is_wp_error( $result ) ) {
			$args['action']           = $id > 0 ? 'edit' : 'new';
			$args['id']               = $id > 0 ? $id : null;
			$args['welow_rrhh_error'] = rawurlencode( wp_json_encode( $result->get_error_messages() ) );
		}

		wp_safe_redirect( add_query_arg( array_filter( $args, static fn( $v ): bool => null !== $v ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Imprime avisos.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['welow_rrhh_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['welow_rrhh_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}
		$messages = array(
			'created' => array( 'success', __( 'Festivo creado.', 'welow-rrhh' ) ),
			'updated' => array( 'success', __( 'Festivo actualizado.', 'welow-rrhh' ) ),
			'deleted' => array( 'success', __( 'Festivo eliminado.', 'welow-rrhh' ) ),
			'error'   => array( 'error', __( 'Ha habido errores.', 'welow-rrhh' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		[ $type, $message ] = $messages[ $notice ];
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'error' === $notice && ! empty( $_GET['welow_rrhh_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw     = sanitize_text_field( wp_unslash( (string) $_GET['welow_rrhh_error'] ) );
			$decoded = json_decode( rawurldecode( $raw ), true );
			if ( is_array( $decoded ) ) {
				echo '<div class="notice notice-error"><ul>';
				foreach ( $decoded as $msg ) {
					echo '<li>' . esc_html( (string) $msg ) . '</li>';
				}
				echo '</ul></div>';
			}
		}
	}

	/**
	 * Entry point.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_HOLIDAYS ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'new' === $action ) {
			$this->render_form( null );
			return;
		}
		if ( 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id      = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
			$holiday = $id > 0 ? $this->service->repository()->find_by_id( $id ) : null;
			if ( null === $holiday ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Festivo no encontrado', 'welow-rrhh' ) . '</h1></div>';
				return;
			}
			$this->render_form( $holiday );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render del listado.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$table = new HolidaysListTable( $this->service->repository() );
		$table->prepare_items();
		$new_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
		$import_url = admin_url( 'admin.php?page=' . HolidaysImportScreen::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Festivos', 'welow-rrhh' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Añadir nuevo', 'welow-rrhh' ); ?></a>
			<a href="<?php echo esc_url( $import_url ); ?>" class="page-title-action"><?php esc_html_e( 'Importar CSV', 'welow-rrhh' ); ?></a>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render del form de alta/edición.
	 *
	 * @param Holiday|null $holiday Festivo a editar o null.
	 * @return void
	 */
	private function render_form( ?Holiday $holiday ): void {
		$is_edit  = null !== $holiday;
		$title    = $is_edit ? __( 'Editar festivo', 'welow-rrhh' ) : __( 'Nuevo festivo', 'welow-rrhh' );
		$back_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Volver', 'welow-rrhh' ); ?></a>
			<hr class="wp-header-end">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_NONCE ); ?>
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="holiday_id" value="<?php echo (int) $holiday->id; ?>" />
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="welow-hol-date"><?php esc_html_e( 'Fecha', 'welow-rrhh' ); ?> <span class="required">*</span></label></th>
							<td>
								<input type="date" id="welow-hol-date" name="date" required
									value="<?php echo esc_attr( $is_edit ? $holiday->date->format( 'Y-m-d' ) : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-hol-name"><?php esc_html_e( 'Nombre', 'welow-rrhh' ); ?> <span class="required">*</span></label></th>
							<td>
								<input type="text" id="welow-hol-name" name="name" required class="regular-text"
									value="<?php echo esc_attr( $is_edit ? $holiday->name : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-hol-scope"><?php esc_html_e( 'Ámbito', 'welow-rrhh' ); ?></label></th>
							<td>
								<select id="welow-hol-scope" name="scope">
									<?php
									$current_scope = $is_edit ? $holiday->scope->value : HolidayScope::get_default()->value;
									foreach ( HolidayScope::cases() as $option ) :
										?>
										<option value="<?php echo esc_attr( $option->value ); ?>" <?php selected( $current_scope, $option->value ); ?>>
											<?php echo esc_html( $option->label() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( $is_edit ? __( 'Guardar cambios', 'welow-rrhh' ) : __( 'Crear festivo', 'welow-rrhh' ) ); ?>
			</form>
		</div>
		<?php
	}
}
