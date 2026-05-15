<?php
/**
 * Pantalla de importación CSV de festivos.
 *
 * Mismo flujo que la de empleados: upload → preview (dry-run) → confirm.
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Importers\HolidayCsvParser;
use Welow\RRHH\Importers\HolidayImporter;
use Welow\RRHH\Roles\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Pantalla import CSV festivos.
 */
final class HolidaysImportScreen {

	public const PAGE_SLUG         = 'welow-rrhh-import-holidays';
	public const UPLOAD_ACTION     = 'welow_rrhh_holidays_csv_upload';
	public const CONFIRM_ACTION    = 'welow_rrhh_holidays_csv_confirm';
	private const UPLOAD_NONCE     = 'welow_rrhh_holidays_csv_upload_nonce';
	private const CONFIRM_NONCE    = 'welow_rrhh_holidays_csv_confirm_nonce';
	private const TRANSIENT_PREFIX = 'welow_rrhh_holidays_csv_';
	private const TRANSIENT_TTL    = 30 * MINUTE_IN_SECONDS;
	private const MAX_FILE_SIZE    = 2 * 1024 * 1024; // 2 MB.

	/**
	 * Importer.
	 *
	 * @var HolidayImporter
	 */
	private HolidayImporter $importer;

	/**
	 * Constructor.
	 *
	 * @param HolidayImporter $importer Importer.
	 */
	public function __construct( HolidayImporter $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Procesa upload.
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_HOLIDAYS ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::UPLOAD_NONCE );

		if ( ! isset( $_FILES['welow_csv'] ) || ! is_array( $_FILES['welow_csv'] ) ) {
			$this->redirect_with_error( __( 'No se ha recibido ningún archivo.', 'welow-rrhh' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = $_FILES['welow_csv'];

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->redirect_with_error( __( 'Error subiendo el archivo.', 'welow-rrhh' ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_FILE_SIZE ) {
			$this->redirect_with_error( __( 'Archivo demasiado grande (máx. 2 MB).', 'welow-rrhh' ) );
		}

		$name      = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$tmp_name  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'txt' ), true ) ) {
			$this->redirect_with_error( __( 'Sólo se aceptan archivos .csv', 'welow-rrhh' ) );
		}
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			$this->redirect_with_error( __( 'Archivo temporal no válido.', 'welow-rrhh' ) );
		}

		$parser = new HolidayCsvParser();
		$parsed = $parser->parse_file( $tmp_name );
		if ( ! empty( $parsed['errors'] ) && empty( $parsed['rows'] ) ) {
			$this->redirect_with_error( implode( ' / ', $parsed['errors'] ) );
		}

		$report = $this->importer->dry_run( $parsed['rows'] );

		set_transient(
			self::transient_key(),
			array(
				'rows'          => $parsed['rows'],
				'parse_errors'  => $parsed['errors'],
				'dry_run'       => $report,
				'original_name' => $name,
			),
			self::TRANSIENT_TTL
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'step' => 'preview',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Procesa confirmación.
	 *
	 * @return void
	 */
	public function handle_confirm(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_HOLIDAYS ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CONFIRM_NONCE );

		$transient = get_transient( self::transient_key() );
		if ( ! is_array( $transient ) || empty( $transient['rows'] ) ) {
			$this->redirect_with_error( __( 'No hay un import pendiente. Vuelve a subir el archivo.', 'welow-rrhh' ) );
		}

		$report = $this->importer->execute( $transient['rows'] );

		set_transient(
			self::transient_key() . '_result',
			array(
				'report'        => $report,
				'parse_errors'  => $transient['parse_errors'] ?? array(),
				'original_name' => $transient['original_name'] ?? '',
			),
			self::TRANSIENT_TTL
		);
		delete_transient( self::transient_key() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'step' => 'report',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * GET actions (descarga plantilla).
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( 'download_template' !== $action ) {
			return;
		}
		if ( ! current_user_can( Capabilities::CAP_MANAGE_HOLIDAYS ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'welow_rrhh_holidays_csv_template' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="welow-rrhh-festivos-plantilla.csv"' );
		echo HolidayCsvParser::template_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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
		$step = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['step'] ) ) : 'upload';

		switch ( $step ) {
			case 'preview':
				$this->render_preview();
				break;
			case 'report':
				$this->render_report();
				break;
			default:
				$this->render_upload();
		}
	}

	/**
	 * Render del form upload.
	 *
	 * @return void
	 */
	private function render_upload(): void {
		$template_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => 'download_template',
				),
				admin_url( 'admin.php' )
			),
			'welow_rrhh_holidays_csv_template'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Importar festivos desde CSV', 'welow-rrhh' ); ?></h1>
			<?php $this->render_error_notice(); ?>
			<p><?php esc_html_e( 'Sube un CSV con las columnas fecha, nombre y tipo (national/regional/local/company).', 'welow-rrhh' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( $template_url ); ?>">
					<?php esc_html_e( 'Descargar plantilla CSV', 'welow-rrhh' ); ?>
				</a>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::UPLOAD_ACTION ); ?>" />
				<?php wp_nonce_field( self::UPLOAD_NONCE ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="welow-hol-csv"><?php esc_html_e( 'Archivo CSV', 'welow-rrhh' ); ?></label></th>
							<td><input type="file" id="welow-hol-csv" name="welow_csv" accept=".csv,text/csv" required /></td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Subir y previsualizar', 'welow-rrhh' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render dry-run.
	 *
	 * @return void
	 */
	private function render_preview(): void {
		$data = get_transient( self::transient_key() );
		if ( ! is_array( $data ) || empty( $data['dry_run'] ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Sin previsualización activa', 'welow-rrhh' ) . '</h1></div>';
			return;
		}
		$report = (array) $data['dry_run'];
		$stats  = HolidayImporter::count_outcomes( $report );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Previsualización del import', 'welow-rrhh' ); ?></h1>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Resultado', 'welow-rrhh' ); ?></th><th><?php esc_html_e( 'Filas', 'welow-rrhh' ); ?></th></tr></thead>
				<tbody>
					<tr><td><?php esc_html_e( 'Se crearán', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ HolidayImporter::OUTCOME_CREATE ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Ya existen (se omiten)', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ HolidayImporter::OUTCOME_SKIP_EXISTS ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Errores', 'welow-rrhh' ); ?></td><td><strong><?php echo (int) $stats[ HolidayImporter::OUTCOME_ERROR ]; ?></strong></td></tr>
				</tbody>
			</table>
			<h2 style="margin-top:24px;"><?php esc_html_e( 'Detalle por fila', 'welow-rrhh' ); ?></h2>
			<?php $this->render_report_table( $report ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CONFIRM_ACTION ); ?>" />
				<?php wp_nonce_field( self::CONFIRM_NONCE ); ?>
				<?php submit_button( __( 'Confirmar e importar', 'welow-rrhh' ), 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
					<?php esc_html_e( 'Cancelar', 'welow-rrhh' ); ?>
				</a>
			</form>
		</div>
		<?php
	}

	/**
	 * Render reporte post-ejecución.
	 *
	 * @return void
	 */
	private function render_report(): void {
		$data = get_transient( self::transient_key() . '_result' );
		if ( ! is_array( $data ) || empty( $data['report'] ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Sin reporte', 'welow-rrhh' ) . '</h1></div>';
			return;
		}
		$report = (array) $data['report'];
		$stats  = HolidayImporter::count_outcomes( $report );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reporte de importación', 'welow-rrhh' ); ?></h1>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Resultado', 'welow-rrhh' ); ?></th><th><?php esc_html_e( 'Filas', 'welow-rrhh' ); ?></th></tr></thead>
				<tbody>
					<tr><td><?php esc_html_e( 'Creados', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ HolidayImporter::OUTCOME_CREATE ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Omitidos (ya existían)', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ HolidayImporter::OUTCOME_SKIP_EXISTS ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Errores', 'welow-rrhh' ); ?></td><td><strong><?php echo (int) $stats[ HolidayImporter::OUTCOME_ERROR ]; ?></strong></td></tr>
				</tbody>
			</table>
			<h2 style="margin-top:24px;"><?php esc_html_e( 'Detalle por fila', 'welow-rrhh' ); ?></h2>
			<?php $this->render_report_table( $report ); ?>
			<p style="margin-top:16px;">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . HolidaysScreen::PAGE_SLUG ) ); ?>">
					<?php esc_html_e( 'Volver al listado de festivos', 'welow-rrhh' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Tabla compartida preview/report.
	 *
	 * @param array<int, array<string, mixed>> $report Reporte.
	 * @return void
	 */
	private function render_report_table( array $report ): void {
		$badges = array(
			HolidayImporter::OUTCOME_CREATE      => array( 'welow-rrhh__status--active', __( 'Crear', 'welow-rrhh' ) ),
			HolidayImporter::OUTCOME_SKIP_EXISTS => array( 'welow-rrhh__status--inactive', __( 'Omitir', 'welow-rrhh' ) ),
			HolidayImporter::OUTCOME_ERROR       => array( 'welow-rrhh__status--inactive', __( 'Error', 'welow-rrhh' ) ),
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( 'Línea', 'welow-rrhh' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Resultado', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Nombre', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Mensaje', 'welow-rrhh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report as $item ) : ?>
					<?php
					$outcome = (string) ( $item['outcome'] ?? '' );
					$row     = (array) ( $item['row'] ?? array() );
					$badge   = $badges[ $outcome ] ?? array( 'welow-rrhh__status--inactive', $outcome );
					?>
					<tr>
						<td><?php echo (int) ( $item['line'] ?? 0 ); ?></td>
						<td>
							<span class="welow-rrhh__status <?php echo esc_attr( $badge[0] ); ?>">
								<?php echo esc_html( (string) $badge[1] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (string) ( $row['date'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['scope'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $item['message'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Notice de error desde query arg.
	 *
	 * @return void
	 */
	private function render_error_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['welow_rrhh_csv_error'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = sanitize_text_field( wp_unslash( (string) $_GET['welow_rrhh_csv_error'] ) );
		echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Redirige al paso upload con error.
	 *
	 * @param string $message Mensaje.
	 * @return void
	 */
	private function redirect_with_error( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => self::PAGE_SLUG,
					'welow_rrhh_csv_error' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Transient key específico por usuario.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return self::TRANSIENT_PREFIX . get_current_user_id();
	}
}
