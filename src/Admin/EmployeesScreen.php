<?php
/**
 * Pantalla de gestión de empleados en wp-admin.
 *
 * Orquesta el listado (WP_List_Table), el formulario de alta/edición y
 * las acciones GET (delete/terminate) y POST (save) con validación
 * de nonces y capabilities.
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Departments\DepartmentRepository;
use Welow\RRHH\Employees\EmployeeService;
use Welow\RRHH\Roles\Capabilities;
use Welow\RRHH\Support\Data\Employee;
use Welow\RRHH\Support\Data\EmployeeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Pantalla de empleados.
 */
final class EmployeesScreen {

	public const PAGE_SLUG   = 'welow-rrhh-employees';
	public const SAVE_ACTION = 'welow_rrhh_employee_save';
	private const SAVE_NONCE = 'welow_rrhh_employee_save_nonce';

	/**
	 * Servicio de empleados.
	 *
	 * @var EmployeeService
	 */
	private EmployeeService $service;

	/**
	 * Repositorio de departamentos (para poblar el dropdown del form).
	 *
	 * @var DepartmentRepository
	 */
	private DepartmentRepository $departments;

	/**
	 * Constructor.
	 *
	 * @param EmployeeService      $service     Servicio de empleados.
	 * @param DepartmentRepository $departments Repositorio de departamentos.
	 */
	public function __construct( EmployeeService $service, DepartmentRepository $departments ) {
		$this->service     = $service;
		$this->departments = $departments;
	}

	/**
	 * Procesa acciones GET (delete, terminate) antes de renderizar la página.
	 *
	 * Engancha en `load-{hook_suffix}` para poder hacer wp_safe_redirect.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( ! in_array( $action, array( 'delete', 'terminate' ), true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( $id <= 0 ) {
			return;
		}

		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar empleados.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		$nonce_action = 'welow_rrhh_employee_' . $action . '_' . $id;
		check_admin_referer( $nonce_action );

		if ( 'delete' === $action ) {
			$result = $this->service->delete( $id, false );
			$notice = is_wp_error( $result ) ? 'error' : 'deleted';
		} else {
			$result = $this->service->terminate( $id );
			$notice = is_wp_error( $result ) ? 'error' : 'terminated';
		}

		$redirect = add_query_arg(
			array(
				'page'                 => self::PAGE_SLUG,
				'welow_rrhh_notice'    => $notice,
				'welow_rrhh_notice_id' => $id,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handler del form POST (admin_post_welow_rrhh_employee_save).
	 *
	 * @return void
	 */
	public function handle_post_save(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar empleados.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );

		$employee_id = isset( $post['employee_id'] ) ? (int) $post['employee_id'] : 0;

		$data = array(
			'email'                  => isset( $post['email'] ) ? (string) $post['email'] : '',
			'first_name'             => isset( $post['first_name'] ) ? (string) $post['first_name'] : '',
			'last_name'              => isset( $post['last_name'] ) ? (string) $post['last_name'] : '',
			'dni_nie'                => isset( $post['dni_nie'] ) ? (string) $post['dni_nie'] : '',
			'employee_code'          => isset( $post['employee_code'] ) ? (string) $post['employee_code'] : '',
			'position'               => isset( $post['position'] ) ? (string) $post['position'] : '',
			'department_id'          => isset( $post['department_id'] ) ? (string) $post['department_id'] : '',
			'manager_user_id'        => isset( $post['manager_user_id'] ) ? (string) $post['manager_user_id'] : '',
			'hire_date'              => isset( $post['hire_date'] ) ? (string) $post['hire_date'] : '',
			'weekly_hours'           => isset( $post['weekly_hours'] ) ? (string) $post['weekly_hours'] : '',
			'vacation_days_override' => isset( $post['vacation_days_override'] ) ? (string) $post['vacation_days_override'] : '',
			'status'                 => isset( $post['status'] ) ? (string) $post['status'] : '',
		);

		if ( $employee_id > 0 ) {
			$result = $this->service->update( $employee_id, $data );
			$notice = is_wp_error( $result ) ? 'error' : 'updated';
		} else {
			$result      = $this->service->create_with_user( $data, array( 'send_welcome_email' => true ) );
			$notice      = is_wp_error( $result ) ? 'error' : 'created';
			$employee_id = $result instanceof Employee ? (int) $result->id : 0;
		}

		$redirect_args = array(
			'page'                 => self::PAGE_SLUG,
			'welow_rrhh_notice'    => $notice,
			'welow_rrhh_notice_id' => $employee_id,
		);

		if ( is_wp_error( $result ) ) {
			$redirect_args['action']           = $employee_id > 0 ? 'edit' : 'new';
			$redirect_args['id']               = $employee_id > 0 ? $employee_id : null;
			$redirect_args['welow_rrhh_error'] = rawurlencode( wp_json_encode( $result->get_error_messages() ) );
		}

		wp_safe_redirect( add_query_arg( array_filter( $redirect_args, static fn( $v ): bool => null !== $v ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Imprime los avisos admin (si vienen vía query args).
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
			'created'    => array( 'success', __( 'Empleado creado correctamente.', 'welow-rrhh' ) ),
			'updated'    => array( 'success', __( 'Empleado actualizado correctamente.', 'welow-rrhh' ) ),
			'deleted'    => array( 'success', __( 'Empleado eliminado.', 'welow-rrhh' ) ),
			'terminated' => array( 'success', __( 'Empleado dado de baja.', 'welow-rrhh' ) ),
			'error'      => array( 'error', __( 'Ha habido errores. Revisa los datos.', 'welow-rrhh' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		[ $type, $message ] = $messages[ $notice ];

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);

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
	 * Punto de entrada (callback de add_submenu_page).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta sección.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'new' === $action ) {
			$this->render_form( null );
			return;
		}
		if ( 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
			$employee = $id > 0 ? $this->service->repository()->find_by_id( $id ) : null;
			if ( null === $employee ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Empleado no encontrado', 'welow-rrhh' ) . '</h1></div>';
				return;
			}
			$this->render_form( $employee );
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
		$repository = $this->service->repository();
		$table      = new EmployeesListTable( $repository );
		$table->prepare_items();

		$new_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Empleados', 'welow-rrhh' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Añadir nuevo', 'welow-rrhh' ); ?></a>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $table->search_box( __( 'Buscar', 'welow-rrhh' ), 'welow-rrhh-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render del formulario de alta/edición.
	 *
	 * @param Employee|null $employee Empleado a editar, o null para alta.
	 * @return void
	 */
	private function render_form( ?Employee $employee ): void {
		$is_edit  = null !== $employee;
		$title    = $is_edit ? __( 'Editar empleado', 'welow-rrhh' ) : __( 'Nuevo empleado', 'welow-rrhh' );
		$user     = $is_edit ? get_userdata( $employee->user_id ) : null;
		$back_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Volver al listado', 'welow-rrhh' ); ?></a>
			<hr class="wp-header-end">

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="welow-rrhh__form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_NONCE ); ?>
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="employee_id" value="<?php echo (int) $employee->id; ?>" />
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="welow-email"><?php esc_html_e( 'Email', 'welow-rrhh' ); ?> <span class="required">*</span></label></th>
							<td>
								<input type="email" id="welow-email" name="email" required class="regular-text"
									value="<?php echo esc_attr( $user ? $user->user_email : '' ); ?>"
									<?php disabled( $is_edit, true ); ?> />
								<?php if ( $is_edit ) : ?>
									<p class="description"><?php esc_html_e( 'El email se actualiza desde el perfil del usuario WordPress.', 'welow-rrhh' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-first-name"><?php esc_html_e( 'Nombre', 'welow-rrhh' ); ?> <span class="required">*</span></label></th>
							<td>
								<input type="text" id="welow-first-name" name="first_name" required class="regular-text"
									value="<?php echo esc_attr( $is_edit ? $employee->first_name : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-last-name"><?php esc_html_e( 'Apellidos', 'welow-rrhh' ); ?> <span class="required">*</span></label></th>
							<td>
								<input type="text" id="welow-last-name" name="last_name" required class="regular-text"
									value="<?php echo esc_attr( $is_edit ? $employee->last_name : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-dni-nie"><?php esc_html_e( 'DNI / NIE', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="text" id="welow-dni-nie" name="dni_nie" maxlength="20"
									value="<?php echo esc_attr( $is_edit && $employee->dni_nie ? $employee->dni_nie : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'Almacenado cifrado en base de datos.', 'welow-rrhh' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-employee-code"><?php esc_html_e( 'Código interno', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="text" id="welow-employee-code" name="employee_code" maxlength="50"
									value="<?php echo esc_attr( $is_edit && $employee->employee_code ? $employee->employee_code : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-position"><?php esc_html_e( 'Cargo', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="text" id="welow-position" name="position" class="regular-text" maxlength="150"
									value="<?php echo esc_attr( $is_edit ? $employee->position : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-department"><?php esc_html_e( 'Departamento', 'welow-rrhh' ); ?></label></th>
							<td>
								<?php $this->render_department_dropdown( $is_edit ? $employee->department_id : null ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-manager"><?php esc_html_e( 'Manager directo', 'welow-rrhh' ); ?></label></th>
							<td>
								<?php $this->render_manager_dropdown( $is_edit ? $employee->manager_user_id : null ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-hire-date"><?php esc_html_e( 'Fecha de alta', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="date" id="welow-hire-date" name="hire_date"
									value="<?php echo esc_attr( $is_edit && $employee->hire_date ? $employee->hire_date->format( 'Y-m-d' ) : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-weekly-hours"><?php esc_html_e( 'Horas semanales', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="number" id="welow-weekly-hours" name="weekly_hours" step="0.25" min="0" max="168"
									value="<?php echo esc_attr( $is_edit && null !== $employee->weekly_hours ? (string) $employee->weekly_hours : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-vacation-days"><?php esc_html_e( 'Días de vacaciones (override)', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="number" id="welow-vacation-days" name="vacation_days_override" min="0" max="365"
									value="<?php echo esc_attr( $is_edit && null !== $employee->vacation_days_override ? (string) $employee->vacation_days_override : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'Deja vacío para usar el valor de empresa.', 'welow-rrhh' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="welow-status"><?php esc_html_e( 'Estado', 'welow-rrhh' ); ?></label></th>
							<td>
								<select id="welow-status" name="status">
									<?php
									$current_status = $is_edit ? $employee->status->value : EmployeeStatus::ACTIVE->value;
									foreach ( EmployeeStatus::cases() as $status ) :
										?>
										<option value="<?php echo esc_attr( $status->value ); ?>" <?php selected( $current_status, $status->value ); ?>>
											<?php echo esc_html( $status->label() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<?php
				submit_button(
					$is_edit ? __( 'Guardar cambios', 'welow-rrhh' ) : __( 'Crear empleado', 'welow-rrhh' )
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Imprime un <select> con los departamentos disponibles.
	 *
	 * @param int|null $selected_id ID del departamento seleccionado.
	 * @return void
	 */
	private function render_department_dropdown( ?int $selected_id ): void {
		$all = $this->departments->find_all();
		echo '<select id="welow-department" name="department_id">';
		echo '<option value="">' . esc_html__( '— Sin departamento —', 'welow-rrhh' ) . '</option>';
		foreach ( $all as $dep ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $dep->id,
				selected( $selected_id, (int) $dep->id, false ),
				esc_html( $dep->name )
			);
		}
		echo '</select>';
	}

	/**
	 * Imprime un <select> de managers candidatos (usuarios con rol welow_manager o admin WP).
	 *
	 * @param int|null $selected_id ID del manager seleccionado.
	 * @return void
	 */
	private function render_manager_dropdown( ?int $selected_id ): void {
		$users = get_users(
			array(
				'role__in' => array( Capabilities::ROLE_MANAGER, Capabilities::ROLE_HR, Capabilities::ROLE_ADMIN, 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'number'   => 200,
			)
		);
		echo '<select id="welow-manager" name="manager_user_id">';
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
