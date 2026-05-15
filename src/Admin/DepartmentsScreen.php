<?php
/**
 * Pantalla wp-admin de gestión de departamentos.
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Departments\DepartmentService;
use Welow\RRHH\Roles\Capabilities;
use Welow\RRHH\Support\Data\Department;

defined( 'ABSPATH' ) || exit;

/**
 * Pantalla de departamentos.
 */
final class DepartmentsScreen {

	public const PAGE_SLUG   = 'welow-rrhh-departments';
	public const SAVE_ACTION = 'welow_rrhh_department_save';
	private const SAVE_NONCE = 'welow_rrhh_department_save_nonce';

	/**
	 * Servicio.
	 *
	 * @var DepartmentService
	 */
	private DepartmentService $service;

	/**
	 * Constructor.
	 *
	 * @param DepartmentService $service Servicio.
	 */
	public function __construct( DepartmentService $service ) {
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

		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'welow_rrhh_department_delete_' . $id );

		$result = $this->service->delete( $id );
		$notice = is_wp_error( $result ) ? 'error' : 'deleted';
		$err    = is_wp_error( $result ) ? rawurlencode( wp_json_encode( $result->get_error_messages() ) ) : '';

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'page'              => self::PAGE_SLUG,
						'welow_rrhh_notice' => $notice,
						'welow_rrhh_error'  => '' === $err ? null : $err,
					),
					static fn( $v ): bool => null !== $v
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
		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );

		$id = isset( $post['department_id'] ) ? (int) $post['department_id'] : 0;

		$data = array(
			'name'            => isset( $post['name'] ) ? (string) $post['name'] : '',
			'slug'            => isset( $post['slug'] ) ? (string) $post['slug'] : '',
			'parent_id'       => isset( $post['parent_id'] ) ? (string) $post['parent_id'] : '',
			'manager_user_id' => isset( $post['manager_user_id'] ) ? (string) $post['manager_user_id'] : '',
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
	 * Imprime avisos admin.
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
			'created' => array( 'success', __( 'Departamento creado.', 'welow-rrhh' ) ),
			'updated' => array( 'success', __( 'Departamento actualizado.', 'welow-rrhh' ) ),
			'deleted' => array( 'success', __( 'Departamento eliminado.', 'welow-rrhh' ) ),
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
	 * Entry point del menú.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
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
			$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
			$dep = $id > 0 ? $this->service->repository()->find_by_id( $id ) : null;
			if ( null === $dep ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Departamento no encontrado', 'welow-rrhh' ) . '</h1></div>';
				return;
			}
			$this->render_form( $dep );
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
		$table = new DepartmentsListTable( $this->service->repository() );
		$table->prepare_items();
		$new_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Departamentos', 'welow-rrhh' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Añadir nuevo', 'welow-rrhh' ); ?></a>
			<hr class="wp-header-end">
			<?php $table->display(); ?>
		</div>
		<?php
	}

	/**
	 * Render del formulario de alta/edición.
	 *
	 * @param Department|null $department Departamento a editar, o null para alta.
	 * @return void
	 */
	private function render_form( ?Department $department ): void {
		$is_edit  = null !== $department;
		$title    = $is_edit ? __( 'Editar departamento', 'welow-rrhh' ) : __( 'Nuevo departamento', 'welow-rrhh' );
		$back_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$all      = $this->service->repository()->find_all();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Volver', 'welow-rrhh' ); ?></a>
			<hr class="wp-header-end">

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_NONCE ); ?>
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="department_id" value="<?php echo (int) $department->id; ?>" />
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="welow-dep-name"><?php esc_html_e( 'Nombre', 'welow-rrhh' ); ?> <span class="required">*</span></label></th>
							<td><input type="text" id="welow-dep-name" name="name" required class="regular-text" value="<?php echo esc_attr( $is_edit ? $department->name : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-dep-slug"><?php esc_html_e( 'Slug', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="text" id="welow-dep-slug" name="slug" value="<?php echo esc_attr( $is_edit ? $department->slug : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'Déjalo vacío para que se genere a partir del nombre.', 'welow-rrhh' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-dep-parent"><?php esc_html_e( 'Departamento padre', 'welow-rrhh' ); ?></label></th>
							<td>
								<select id="welow-dep-parent" name="parent_id">
									<option value=""><?php esc_html_e( '— Ninguno (raíz) —', 'welow-rrhh' ); ?></option>
									<?php foreach ( $all as $option ) : ?>
										<?php if ( $is_edit && $option->id === $department->id ) { continue; } // phpcs:ignore Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace, Squiz.ControlStructures.ControlSignature.SpaceAfterCloseBrace ?>
										<option value="<?php echo (int) $option->id; ?>" <?php selected( $is_edit ? $department->parent_id : null, $option->id ); ?>>
											<?php echo esc_html( $option->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-dep-manager"><?php esc_html_e( 'Manager', 'welow-rrhh' ); ?></label></th>
							<td>
								<?php $this->render_manager_dropdown( $is_edit ? $department->manager_user_id : null ); ?>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( $is_edit ? __( 'Guardar cambios', 'welow-rrhh' ) : __( 'Crear departamento', 'welow-rrhh' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Imprime un <select> de managers candidatos.
	 *
	 * @param int|null $selected_id ID seleccionado.
	 * @return void
	 */
	private function render_manager_dropdown( ?int $selected_id ): void {
		$users = get_users(
			array(
				'role__in' => array(
					Capabilities::ROLE_MANAGER,
					Capabilities::ROLE_HR,
					Capabilities::ROLE_ADMIN,
					'administrator',
				),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'number'   => 200,
			)
		);
		echo '<select id="welow-dep-manager" name="manager_user_id">';
		echo '<option value="">' . esc_html__( '— Sin manager —', 'welow-rrhh' ) . '</option>';
		foreach ( $users as $user ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $user->ID,
				selected( $selected_id, (int) $user->ID, false ),
				esc_html( $user->display_name . ' (' . $user->user_email . ')' )
			);
		}
		echo '</select>';
	}
}
