<?php
/**
 * Pantalla wp-admin de Ajustes del plugin (welow_rrhh_company_settings).
 *
 * Cinco tabs: Empresa, Calendario, Vacaciones, Fichajes, Notificaciones.
 * Cada tab es un form independiente que sólo modifica su sección.
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
 * Pantalla de Ajustes.
 */
final class SettingsScreen {

	public const PAGE_SLUG   = 'welow-rrhh-settings';
	public const SAVE_ACTION = 'welow_rrhh_settings_save';
	private const SAVE_NONCE = 'welow_rrhh_settings_save_nonce';

	private const TAB_COMPANY       = 'company';
	private const TAB_CALENDAR      = 'calendar';
	private const TAB_VACATIONS     = 'vacations';
	private const TAB_TIME_TRACKING = 'time_tracking';
	private const TAB_NOTIFICATIONS = 'notifications';

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
	 * Handler POST de save (admin_post_<SAVE_ACTION>).
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

		$tab = isset( $post['tab'] ) ? sanitize_key( (string) $post['tab'] ) : self::TAB_COMPANY;

		$values = match ( $tab ) {
			self::TAB_CALENDAR      => SettingsSanitizer::sanitize_calendar( (array) ( $post['calendar'] ?? array() ) ),
			self::TAB_VACATIONS     => SettingsSanitizer::sanitize_vacations( (array) ( $post['vacations'] ?? array() ) ),
			self::TAB_TIME_TRACKING => SettingsSanitizer::sanitize_time_tracking( (array) ( $post['time_tracking'] ?? array() ) ),
			self::TAB_NOTIFICATIONS => SettingsSanitizer::sanitize_notifications( (array) ( $post['notifications'] ?? array() ) ),
			default                 => SettingsSanitizer::sanitize_company( (array) ( $post['company'] ?? array() ) ),
		};

		$mapped_tab = self::TAB_COMPANY === $tab ? CompanySettings::SECTION_COMPANY : $tab;
		$this->settings->update_section( $mapped_tab, $values );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE_SLUG,
					'tab'               => $tab,
					'welow_rrhh_notice' => 'updated',
				),
				admin_url( 'admin.php' )
			)
		);
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
		if ( 'updated' !== $notice ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Ajustes guardados.', 'welow-rrhh' )
			. '</p></div>';
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
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : self::TAB_COMPANY;

		$tabs = array(
			self::TAB_COMPANY       => __( 'Empresa', 'welow-rrhh' ),
			self::TAB_CALENDAR      => __( 'Calendario', 'welow-rrhh' ),
			self::TAB_VACATIONS     => __( 'Vacaciones', 'welow-rrhh' ),
			self::TAB_TIME_TRACKING => __( 'Fichajes', 'welow-rrhh' ),
			self::TAB_NOTIFICATIONS => __( 'Notificaciones', 'welow-rrhh' ),
		);

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = self::TAB_COMPANY;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ajustes de Welow RRHH', 'welow-rrhh' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
					<?php
					$url = add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => $tab_slug,
						),
						admin_url( 'admin.php' )
					);
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo $tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php wp_nonce_field( self::SAVE_NONCE ); ?>

				<?php
				switch ( $tab ) {
					case self::TAB_CALENDAR:
						$this->render_calendar();
						break;
					case self::TAB_VACATIONS:
						$this->render_vacations();
						break;
					case self::TAB_TIME_TRACKING:
						$this->render_time_tracking();
						break;
					case self::TAB_NOTIFICATIONS:
						$this->render_notifications();
						break;
					default:
						$this->render_company();
				}
				?>

				<?php submit_button( __( 'Guardar cambios', 'welow-rrhh' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render tab Empresa.
	 *
	 * @return void
	 */
	private function render_company(): void {
		$s = $this->settings->section( CompanySettings::SECTION_COMPANY );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="welow-company-name"><?php esc_html_e( 'Nombre de la empresa', 'welow-rrhh' ); ?></label></th>
				<td><input type="text" id="welow-company-name" name="company[name]" class="regular-text" value="<?php echo esc_attr( (string) ( $s['name'] ?? '' ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-company-cif"><?php esc_html_e( 'CIF', 'welow-rrhh' ); ?></label></th>
				<td><input type="text" id="welow-company-cif" name="company[cif]" value="<?php echo esc_attr( (string) ( $s['cif'] ?? '' ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-company-address"><?php esc_html_e( 'Dirección', 'welow-rrhh' ); ?></label></th>
				<td><textarea id="welow-company-address" name="company[address]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $s['address'] ?? '' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-company-logo"><?php esc_html_e( 'ID adjunto del logo', 'welow-rrhh' ); ?></label></th>
				<td>
					<input type="number" id="welow-company-logo" name="company[logo_attachment_id]" min="0"
						value="<?php echo esc_attr( null !== ( $s['logo_attachment_id'] ?? null ) ? (string) $s['logo_attachment_id'] : '' ); ?>" />
					<p class="description"><?php esc_html_e( 'ID de la imagen en Medios. Por ahora introduce el ID manualmente; el picker visual llegará más adelante.', 'welow-rrhh' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render tab Calendario.
	 *
	 * @return void
	 */
	private function render_calendar(): void {
		$s    = $this->settings->section( CompanySettings::SECTION_CALENDAR );
		$ccaa = SettingsSanitizer::ccaa_choices();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="welow-calendar-timezone"><?php esc_html_e( 'Zona horaria', 'welow-rrhh' ); ?></label></th>
				<td>
					<select id="welow-calendar-timezone" name="calendar[timezone]">
						<?php
						$current = (string) ( $s['timezone'] ?? 'Europe/Madrid' );
						foreach ( timezone_identifiers_list() as $tz ) :
							?>
							<option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $current, $tz ); ?>>
								<?php echo esc_html( $tz ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-calendar-first-day"><?php esc_html_e( 'Primer día de la semana', 'welow-rrhh' ); ?></label></th>
				<td>
					<select id="welow-calendar-first-day" name="calendar[first_day_of_week]">
						<?php
						$days         = array(
							0 => __( 'Domingo', 'welow-rrhh' ),
							1 => __( 'Lunes', 'welow-rrhh' ),
							2 => __( 'Martes', 'welow-rrhh' ),
							3 => __( 'Miércoles', 'welow-rrhh' ),
							4 => __( 'Jueves', 'welow-rrhh' ),
							5 => __( 'Viernes', 'welow-rrhh' ),
							6 => __( 'Sábado', 'welow-rrhh' ),
						);
						$selected_day = isset( $s['first_day_of_week'] ) ? (int) $s['first_day_of_week'] : 1;
						foreach ( $days as $index => $label ) :
							?>
							<option value="<?php echo (int) $index; ?>" <?php selected( $selected_day, $index ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-calendar-ccaa"><?php esc_html_e( 'Comunidad Autónoma', 'welow-rrhh' ); ?></label></th>
				<td>
					<select id="welow-calendar-ccaa" name="calendar[ccaa]">
						<?php
						$current_ccaa = (string) ( $s['ccaa'] ?? 'ES-MD' );
						foreach ( $ccaa as $code => $label ) :
							?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_ccaa, $code ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render tab Vacaciones.
	 *
	 * @return void
	 */
	private function render_vacations(): void {
		$s     = $this->settings->section( CompanySettings::SECTION_VACATIONS );
		$flow  = is_array( $s['approval_flow'] ?? null ) ? $s['approval_flow'] : array();
		$roles = SettingsSanitizer::approval_roles();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="welow-vac-days"><?php esc_html_e( 'Días por defecto al año', 'welow-rrhh' ); ?></label></th>
				<td><input type="number" id="welow-vac-days" name="vacations[default_days_per_year]" min="0" max="365" value="<?php echo (int) ( $s['default_days_per_year'] ?? 22 ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-vac-comp"><?php esc_html_e( 'Cómputo', 'welow-rrhh' ); ?></label></th>
				<td>
					<select id="welow-vac-comp" name="vacations[computation]">
						<option value="working_days" <?php selected( (string) ( $s['computation'] ?? 'working_days' ), 'working_days' ); ?>><?php esc_html_e( 'Días laborables', 'welow-rrhh' ); ?></option>
						<option value="natural_days" <?php selected( (string) ( $s['computation'] ?? 'working_days' ), 'natural_days' ); ?>><?php esc_html_e( 'Días naturales', 'welow-rrhh' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Arrastre de días no usados', 'welow-rrhh' ); ?></th>
				<td>
					<label><input type="checkbox" name="vacations[allow_carry_over]" value="1" <?php checked( ! empty( $s['allow_carry_over'] ) ); ?> /> <?php esc_html_e( 'Permitir arrastre al año siguiente', 'welow-rrhh' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-vac-co-max"><?php esc_html_e( 'Máx. días arrastrables', 'welow-rrhh' ); ?></label></th>
				<td><input type="number" id="welow-vac-co-max" name="vacations[carry_over_max_days]" min="0" max="365" value="<?php echo (int) ( $s['carry_over_max_days'] ?? 5 ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-vac-co-deadline"><?php esc_html_e( 'Fecha límite arrastre (MM-DD)', 'welow-rrhh' ); ?></label></th>
				<td><input type="text" id="welow-vac-co-deadline" name="vacations[carry_over_deadline]" pattern="(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])" placeholder="03-31" value="<?php echo esc_attr( (string) ( $s['carry_over_deadline'] ?? '03-31' ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-vac-notice"><?php esc_html_e( 'Días mínimos de antelación', 'welow-rrhh' ); ?></label></th>
				<td><input type="number" id="welow-vac-notice" name="vacations[min_request_notice_days]" min="0" max="365" value="<?php echo (int) ( $s['min_request_notice_days'] ?? 7 ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-vac-maxcon"><?php esc_html_e( 'Máx. días consecutivos por solicitud', 'welow-rrhh' ); ?></label></th>
				<td><input type="number" id="welow-vac-maxcon" name="vacations[max_consecutive_days]" min="1" max="365" value="<?php echo (int) ( $s['max_consecutive_days'] ?? 30 ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Flujo de aprobación', 'welow-rrhh' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Define hasta 4 niveles secuenciales. Roles válidos: manager_direct, hr, admin. Para usuario específico usa user:ID.', 'welow-rrhh' ); ?></p>
					<?php for ( $i = 0; $i < 4; $i++ ) : ?>
						<?php $row_role = isset( $flow[ $i ]['role'] ) ? (string) $flow[ $i ]['role'] : ''; ?>
						<p>
							<label><?php /* translators: %d level number */ printf( esc_html__( 'Nivel %d:', 'welow-rrhh' ), (int) ( $i + 1 ) ); ?></label>
							<select name="vacations[approval_flow][<?php echo (int) $i; ?>][role]">
								<option value=""><?php esc_html_e( '— Sin nivel —', 'welow-rrhh' ); ?></option>
								<?php foreach ( $roles as $role ) : ?>
									<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $row_role, $role ); ?>><?php echo esc_html( $role ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" name="vacations[approval_flow][<?php echo (int) $i; ?>][role_custom]" placeholder="user:42" />
						</p>
					<?php endfor; ?>
					<p class="description"><?php esc_html_e( 'Si pones "user:42" en el segundo input, sustituye al rol canónico.', 'welow-rrhh' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render tab Fichajes.
	 *
	 * @return void
	 */
	private function render_time_tracking(): void {
		$s = $this->settings->section( CompanySettings::SECTION_TIME_TRACKING );

		$ips      = is_array( $s['ip_allowlist'] ?? null ) ? $s['ip_allowlist'] : array();
		$ips_text = implode( "\n", $ips );

		$offices      = is_array( $s['office_locations'] ?? null ) ? $s['office_locations'] : array();
		$offices_text = '';
		foreach ( $offices as $o ) {
			$offices_text .= sprintf(
				"%s|%s|%s|%s\n",
				$o['name'] ?? '',
				$o['lat'] ?? '',
				$o['lng'] ?? '',
				$o['radius'] ?? 200
			);
		}
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Restricciones', 'welow-rrhh' ); ?></th>
				<td>
					<label><input type="checkbox" name="time_tracking[require_geo]" value="1" <?php checked( ! empty( $s['require_geo'] ) ); ?> /> <?php esc_html_e( 'Exigir geolocalización al fichar', 'welow-rrhh' ); ?></label><br>
					<label><input type="checkbox" name="time_tracking[require_ip_allowlist]" value="1" <?php checked( ! empty( $s['require_ip_allowlist'] ) ); ?> /> <?php esc_html_e( 'Exigir IP en la lista permitida', 'welow-rrhh' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-tt-ips"><?php esc_html_e( 'Lista de IPs permitidas', 'welow-rrhh' ); ?></label></th>
				<td><textarea id="welow-tt-ips" name="time_tracking[ip_allowlist]" rows="4" class="large-text" placeholder="192.168.1.10&#10;10.0.0.0/24"><?php echo esc_textarea( $ips_text ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Una IP por línea. Se ignoran las inválidas.', 'welow-rrhh' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-tt-radius"><?php esc_html_e( 'Radio geo por defecto (metros)', 'welow-rrhh' ); ?></label></th>
				<td><input type="number" id="welow-tt-radius" name="time_tracking[geo_radius_meters]" min="10" max="50000" value="<?php echo (int) ( $s['geo_radius_meters'] ?? 200 ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-tt-offices"><?php esc_html_e( 'Ubicaciones de oficina', 'welow-rrhh' ); ?></label></th>
				<td>
					<textarea id="welow-tt-offices" name="time_tracking[office_locations]" rows="4" class="large-text" placeholder="Madrid HQ|40.4168|-3.7038|200"><?php echo esc_textarea( $offices_text ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Una por línea con formato: nombre|lat|lng|radio. También se acepta JSON.', 'welow-rrhh' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-tt-autoclose"><?php esc_html_e( 'Día de cierre automático mensual', 'welow-rrhh' ); ?></label></th>
				<td><input type="number" id="welow-tt-autoclose" name="time_tracking[auto_close_month_day]" min="1" max="28" value="<?php echo (int) ( $s['auto_close_month_day'] ?? 5 ); ?>" />
				<p class="description"><?php esc_html_e( 'Día del mes en que se cierra el mes anterior y deja los registros en sólo-lectura.', 'welow-rrhh' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-tt-maxhours"><?php esc_html_e( 'Aviso si jornada supera (horas)', 'welow-rrhh' ); ?></label></th>
				<td><input type="number" id="welow-tt-maxhours" name="time_tracking[max_daily_hours_warning]" step="0.25" min="1" max="24" value="<?php echo esc_attr( (string) ( $s['max_daily_hours_warning'] ?? 10 ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-tt-breakafter"><?php esc_html_e( 'Pausa obligatoria tras (horas)', 'welow-rrhh' ); ?></label></th>
				<td><input type="number" id="welow-tt-breakafter" name="time_tracking[mandatory_break_after_hours]" step="0.25" min="1" max="24" value="<?php echo esc_attr( (string) ( $s['mandatory_break_after_hours'] ?? 6 ) ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render tab Notificaciones.
	 *
	 * @return void
	 */
	private function render_notifications(): void {
		$s = $this->settings->section( CompanySettings::SECTION_NOTIFICATIONS );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="welow-not-name"><?php esc_html_e( 'Nombre remitente email', 'welow-rrhh' ); ?></label></th>
				<td><input type="text" id="welow-not-name" name="notifications[email_from_name]" class="regular-text" value="<?php echo esc_attr( (string) ( $s['email_from_name'] ?? '' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Si está vacío, se usa el nombre del sitio.', 'welow-rrhh' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="welow-not-email"><?php esc_html_e( 'Email remitente', 'welow-rrhh' ); ?></label></th>
				<td><input type="email" id="welow-not-email" name="notifications[email_from_address]" class="regular-text" value="<?php echo esc_attr( (string) ( $s['email_from_address'] ?? '' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Si está vacío, se usa el del admin de WordPress.', 'welow-rrhh' ); ?></p></td>
			</tr>
		</table>
		<?php
	}
}
