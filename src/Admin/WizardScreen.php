<?php
/**
 * Wizard de instalación de Welow RRHH (5 pasos, §6.1).
 *
 * Pasos:
 *   1. Datos de empresa.
 *   2. Configuración de vacaciones.
 *   3. Configuración de fichajes.
 *   4. Importación de empleados (enlace al importador o saltar).
 *   5. Importación de festivos (enlace al importador o saltar).
 *
 * El progreso se persiste en la opción `welow_rrhh_setup_progress`:
 *   { 'completed' => bool, 'step' => int (1..6) }
 *
 * El wizard se reabre desde "Welow RRHH → Ajustes → enlace al wizard" o
 * automáticamente tras la activación (mientras `completed` siga en false).
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Roles\Capabilities;
use Welow\RRHH\Settings\CompanySettings;
use Welow\RRHH\Settings\SettingsSanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Pantalla del wizard.
 */
final class WizardScreen {

	public const PAGE_SLUG   = 'welow-rrhh-wizard';
	public const SAVE_ACTION = 'welow_rrhh_wizard_save';
	private const SAVE_NONCE = 'welow_rrhh_wizard_save_nonce';

	private const PROGRESS_OPTION = 'welow_rrhh_setup_progress';
	private const TOTAL_STEPS     = 5;

	/**
	 * Wrapper de settings.
	 *
	 * @var CompanySettings
	 */
	private CompanySettings $settings;

	/**
	 * Constructor.
	 *
	 * @param CompanySettings $settings Settings.
	 */
	public function __construct( CompanySettings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Engancha en admin_init: si tras activación toca redirigir al wizard, lo hace.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'welow_rrhh_activation_redirect' ) ) {
			return;
		}
		// Una sola vez.
		delete_transient( 'welow_rrhh_activation_redirect' );

		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! is_admin() || ! current_user_can( Capabilities::CAP_MANAGE_PLUGIN ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		if ( self::PAGE_SLUG === $current_page ) {
			return;
		}
		if ( self::is_completed() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handler POST de save.
	 *
	 * @return void
	 */
	public function handle_post_save(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_PLUGIN ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );

		$step = isset( $post['step'] ) ? (int) $post['step'] : 1;
		$skip = ! empty( $post['skip'] );

		if ( ! $skip ) {
			switch ( $step ) {
				case 1:
					$values = SettingsSanitizer::sanitize_company( (array) ( $post['company'] ?? array() ) );
					$this->settings->update_section( CompanySettings::SECTION_COMPANY, $values );
					// CCAA y timezone los aprovechamos también en el paso 1 para acelerar setup.
					if ( ! empty( $post['calendar'] ) && is_array( $post['calendar'] ) ) {
						$cal = SettingsSanitizer::sanitize_calendar( (array) $post['calendar'] );
						$this->settings->update_section( CompanySettings::SECTION_CALENDAR, $cal );
					}
					break;
				case 2:
					$values = SettingsSanitizer::sanitize_vacations( (array) ( $post['vacations'] ?? array() ) );
					$this->settings->update_section( CompanySettings::SECTION_VACATIONS, $values );
					break;
				case 3:
					$values = SettingsSanitizer::sanitize_time_tracking( (array) ( $post['time_tracking'] ?? array() ) );
					$this->settings->update_section( CompanySettings::SECTION_TIME_TRACKING, $values );
					break;
			}
		}

		$next = $step + 1;
		if ( $next > self::TOTAL_STEPS ) {
			self::mark_completed();
		} else {
			self::set_step( $next );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Entry point.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_PLUGIN ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$override = isset( $_GET['restart'] ) ? (int) $_GET['restart'] : 0;
		if ( 1 === $override ) {
			self::set_step( 1 );
			self::mark_completed( false );
		}

		if ( self::is_completed() ) {
			$this->render_done();
			return;
		}

		$step = self::current_step();

		?>
		<div class="wrap welow-rrhh-wizard">
			<h1><?php esc_html_e( 'Asistente de configuración — Welow RRHH', 'welow-rrhh' ); ?></h1>

			<ol class="welow-rrhh-wizard__steps">
				<?php
				$labels = self::step_labels();
				foreach ( $labels as $idx => $label ) :
					$state = $idx < $step ? 'done' : ( $idx === $step ? 'current' : 'pending' );
					?>
					<li class="welow-rrhh-wizard__step welow-rrhh-wizard__step--<?php echo esc_attr( $state ); ?>">
						<span class="welow-rrhh-wizard__step-number"><?php echo (int) $idx; ?></span>
						<span class="welow-rrhh-wizard__step-label"><?php echo esc_html( $label ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="welow-rrhh-wizard__body">
				<?php
				switch ( $step ) {
					case 1:
						$this->render_step_company();
						break;
					case 2:
						$this->render_step_vacations();
						break;
					case 3:
						$this->render_step_time_tracking();
						break;
					case 4:
						$this->render_step_employees();
						break;
					case 5:
						$this->render_step_holidays();
						break;
					default:
						$this->render_done();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render paso 1 — Empresa + Calendario.
	 *
	 * @return void
	 */
	private function render_step_company(): void {
		$c    = $this->settings->section( CompanySettings::SECTION_COMPANY );
		$cal  = $this->settings->section( CompanySettings::SECTION_CALENDAR );
		$ccaa = SettingsSanitizer::ccaa_choices();
		?>
		<h2><?php esc_html_e( 'Datos de la empresa', 'welow-rrhh' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->print_form_header( 1 ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wzd-name"><?php esc_html_e( 'Nombre de la empresa', 'welow-rrhh' ); ?> <span class="required">*</span></label></th>
					<td><input type="text" id="wzd-name" name="company[name]" required class="regular-text" value="<?php echo esc_attr( (string) ( $c['name'] ?? '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wzd-cif"><?php esc_html_e( 'CIF', 'welow-rrhh' ); ?></label></th>
					<td><input type="text" id="wzd-cif" name="company[cif]" value="<?php echo esc_attr( (string) ( $c['cif'] ?? '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wzd-address"><?php esc_html_e( 'Dirección', 'welow-rrhh' ); ?></label></th>
					<td><textarea id="wzd-address" name="company[address]" rows="2" class="large-text"><?php echo esc_textarea( (string) ( $c['address'] ?? '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="wzd-ccaa"><?php esc_html_e( 'Comunidad Autónoma', 'welow-rrhh' ); ?></label></th>
					<td>
						<select id="wzd-ccaa" name="calendar[ccaa]">
							<?php
							$current = (string) ( $cal['ccaa'] ?? 'ES-MD' );
							foreach ( $ccaa as $code => $label ) :
								?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php $this->print_form_footer( 1, false ); ?>
		</form>
		<?php
	}

	/**
	 * Render paso 2 — Vacaciones.
	 *
	 * @return void
	 */
	private function render_step_vacations(): void {
		$v     = $this->settings->section( CompanySettings::SECTION_VACATIONS );
		$flow  = is_array( $v['approval_flow'] ?? null ) ? $v['approval_flow'] : array();
		$roles = SettingsSanitizer::approval_roles();
		?>
		<h2><?php esc_html_e( 'Configuración de vacaciones', 'welow-rrhh' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->print_form_header( 2 ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wzd-vac-days"><?php esc_html_e( 'Días por defecto al año', 'welow-rrhh' ); ?></label></th>
					<td><input type="number" id="wzd-vac-days" name="vacations[default_days_per_year]" min="0" max="365" value="<?php echo (int) ( $v['default_days_per_year'] ?? 22 ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Arrastre al año siguiente', 'welow-rrhh' ); ?></th>
					<td><label><input type="checkbox" name="vacations[allow_carry_over]" value="1" <?php checked( ! empty( $v['allow_carry_over'] ) ); ?> /> <?php esc_html_e( 'Permitir', 'welow-rrhh' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="wzd-vac-co-max"><?php esc_html_e( 'Máx. días arrastrables', 'welow-rrhh' ); ?></label></th>
					<td><input type="number" id="wzd-vac-co-max" name="vacations[carry_over_max_days]" min="0" max="365" value="<?php echo (int) ( $v['carry_over_max_days'] ?? 5 ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Flujo de aprobación', 'welow-rrhh' ); ?></th>
					<td>
						<?php for ( $i = 0; $i < 2; $i++ ) : ?>
							<?php $row_role = isset( $flow[ $i ]['role'] ) ? (string) $flow[ $i ]['role'] : ''; ?>
							<p>
								<label><?php /* translators: %d level number */ printf( esc_html__( 'Nivel %d:', 'welow-rrhh' ), (int) ( $i + 1 ) ); ?></label>
								<select name="vacations[approval_flow][<?php echo (int) $i; ?>][role]">
									<option value=""><?php esc_html_e( '— Sin nivel —', 'welow-rrhh' ); ?></option>
									<?php foreach ( $roles as $role ) : ?>
										<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $row_role, $role ); ?>><?php echo esc_html( $role ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
						<?php endfor; ?>
						<p class="description"><?php esc_html_e( 'manager_direct = manager directo del empleado · hr = cualquier RRHH · admin = administrador Welow.', 'welow-rrhh' ); ?></p>
					</td>
				</tr>
			</table>
			<?php $this->print_form_footer( 2, false ); ?>
		</form>
		<?php
	}

	/**
	 * Render paso 3 — Fichajes.
	 *
	 * @return void
	 */
	private function render_step_time_tracking(): void {
		$t        = $this->settings->section( CompanySettings::SECTION_TIME_TRACKING );
		$ips      = is_array( $t['ip_allowlist'] ?? null ) ? $t['ip_allowlist'] : array();
		$ips_text = implode( "\n", $ips );
		?>
		<h2><?php esc_html_e( 'Configuración de fichajes', 'welow-rrhh' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->print_form_header( 3 ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Restricciones', 'welow-rrhh' ); ?></th>
					<td>
						<label><input type="checkbox" name="time_tracking[require_geo]" value="1" <?php checked( ! empty( $t['require_geo'] ) ); ?> /> <?php esc_html_e( 'Exigir geolocalización', 'welow-rrhh' ); ?></label><br>
						<label><input type="checkbox" name="time_tracking[require_ip_allowlist]" value="1" <?php checked( ! empty( $t['require_ip_allowlist'] ) ); ?> /> <?php esc_html_e( 'Exigir IP en lista permitida', 'welow-rrhh' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wzd-tt-ips"><?php esc_html_e( 'Lista de IPs (una por línea)', 'welow-rrhh' ); ?></label></th>
					<td><textarea id="wzd-tt-ips" name="time_tracking[ip_allowlist]" rows="4" class="large-text"><?php echo esc_textarea( $ips_text ); ?></textarea></td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Podrás añadir ubicaciones de oficina con coordenadas más adelante desde Ajustes → Fichajes.', 'welow-rrhh' ); ?></p>
			<?php $this->print_form_footer( 3, false ); ?>
		</form>
		<?php
	}

	/**
	 * Render paso 4 — Empleados.
	 *
	 * @return void
	 */
	private function render_step_employees(): void {
		$import_url = admin_url( 'admin.php?page=' . EmployeesImportScreen::PAGE_SLUG );
		?>
		<h2><?php esc_html_e( 'Importar empleados', 'welow-rrhh' ); ?></h2>
		<p><?php esc_html_e( 'Puedes importar empleados desde un CSV ahora mismo, o saltar este paso y darlos de alta manualmente más adelante.', 'welow-rrhh' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $import_url ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Abrir importador de empleados (nueva pestaña)', 'welow-rrhh' ); ?>
			</a>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->print_form_header( 4 ); ?>
			<?php $this->print_form_footer( 4, true ); ?>
		</form>
		<?php
	}

	/**
	 * Render paso 5 — Festivos.
	 *
	 * @return void
	 */
	private function render_step_holidays(): void {
		$import_url = admin_url( 'admin.php?page=' . HolidaysImportScreen::PAGE_SLUG );
		?>
		<h2><?php esc_html_e( 'Importar festivos', 'welow-rrhh' ); ?></h2>
		<p><?php esc_html_e( 'Carga el calendario de festivos del año en curso. Puedes saltar este paso y añadirlos a mano luego.', 'welow-rrhh' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $import_url ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Abrir importador de festivos (nueva pestaña)', 'welow-rrhh' ); ?>
			</a>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->print_form_header( 5 ); ?>
			<?php $this->print_form_footer( 5, true ); ?>
		</form>
		<?php
	}

	/**
	 * Render paso final — Listo.
	 *
	 * @return void
	 */
	private function render_done(): void {
		$employees_url = admin_url( 'admin.php?page=' . EmployeesScreen::PAGE_SLUG );
		$settings_url  = admin_url( 'admin.php?page=' . SettingsScreen::PAGE_SLUG );
		$restart_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&restart=1' );
		?>
		<div class="wrap welow-rrhh-wizard welow-rrhh-wizard--done">
			<h1><?php esc_html_e( '¡Welow RRHH está listo!', 'welow-rrhh' ); ?></h1>
			<p><?php esc_html_e( 'La configuración inicial se ha completado correctamente. Puedes ajustar cualquier opción en cualquier momento desde el menú lateral.', 'welow-rrhh' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $employees_url ); ?>"><?php esc_html_e( 'Ir a Empleados', 'welow-rrhh' ); ?></a>
				<a class="button" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Abrir Ajustes', 'welow-rrhh' ); ?></a>
				<a class="button" href="<?php echo esc_url( $restart_url ); ?>"><?php esc_html_e( 'Volver a ejecutar el wizard', 'welow-rrhh' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Imprime el header común del form (action, nonce, step).
	 *
	 * @param int $step Paso actual.
	 * @return void
	 */
	private function print_form_header( int $step ): void {
		?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
		<input type="hidden" name="step" value="<?php echo (int) $step; ?>" />
		<?php wp_nonce_field( self::SAVE_NONCE ); ?>
		<?php
	}

	/**
	 * Imprime los botones de navegación.
	 *
	 * @param int  $step          Paso actual.
	 * @param bool $allow_skip    Si true, muestra botón "Saltar este paso".
	 * @return void
	 */
	private function print_form_footer( int $step, bool $allow_skip ): void {
		$is_last = self::TOTAL_STEPS === $step;
		?>
		<p class="submit welow-rrhh-wizard__nav">
			<?php if ( $allow_skip ) : ?>
				<button type="submit" name="skip" value="1" class="button"><?php esc_html_e( 'Saltar este paso', 'welow-rrhh' ); ?></button>
			<?php endif; ?>
			<button type="submit" class="button button-primary">
				<?php
				echo esc_html(
					$is_last
						? __( 'Finalizar', 'welow-rrhh' )
						: __( 'Guardar y continuar →', 'welow-rrhh' )
				);
				?>
			</button>
		</p>
		<?php
	}

	/**
	 * Step actual (1..5).
	 *
	 * @return int
	 */
	private static function current_step(): int {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		$step     = is_array( $progress ) && isset( $progress['step'] ) ? (int) $progress['step'] : 1;
		return max( 1, min( self::TOTAL_STEPS, $step ) );
	}

	/**
	 * Etiquetas humanas de cada paso (1-indexed).
	 *
	 * @return array<int, string>
	 */
	private static function step_labels(): array {
		return array(
			1 => __( 'Empresa', 'welow-rrhh' ),
			2 => __( 'Vacaciones', 'welow-rrhh' ),
			3 => __( 'Fichajes', 'welow-rrhh' ),
			4 => __( 'Empleados', 'welow-rrhh' ),
			5 => __( 'Festivos', 'welow-rrhh' ),
		);
	}

	/**
	 * ¿Wizard completado?
	 *
	 * @return bool
	 */
	private static function is_completed(): bool {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		return is_array( $progress ) && ! empty( $progress['completed'] );
	}

	/**
	 * Establece el step actual.
	 *
	 * @param int $step Paso.
	 * @return void
	 */
	private static function set_step( int $step ): void {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		if ( ! is_array( $progress ) ) {
			$progress = array();
		}
		$progress['step']      = max( 1, min( self::TOTAL_STEPS, $step ) );
		$progress['completed'] = false;
		update_option( self::PROGRESS_OPTION, $progress );
	}

	/**
	 * Marca/desmarca el wizard como completado.
	 *
	 * @param bool $completed True para marcar completado.
	 * @return void
	 */
	private static function mark_completed( bool $completed = true ): void {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		if ( ! is_array( $progress ) ) {
			$progress = array();
		}
		$progress['completed'] = $completed;
		$progress['step']      = $completed ? self::TOTAL_STEPS : 1;
		update_option( self::PROGRESS_OPTION, $progress );
	}
}
