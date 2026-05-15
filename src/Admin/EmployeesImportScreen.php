<?php
/**
 * Pantalla de importación CSV de empleados (§15.1).
 *
 * Flujo en tres pasos:
 *   1) upload: el admin sube un CSV.
 *   2) preview: se parsea, se ejecuta dry-run y se muestra el reporte
 *      (con stats: a crear / a vincular / ya existen / errores).
 *   3) confirm: se ejecuta el import realmente y se muestra el reporte final.
 *
 * Las filas validadas se guardan en un transient por usuario entre los
 * pasos preview→confirm para no requerir re-subir el archivo.
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Importers\EmployeeCsvParser;
use Welow\RRHH\Importers\EmployeeImporter;
use Welow\RRHH\Roles\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Pantalla de import CSV.
 */
final class EmployeesImportScreen {

	public const PAGE_SLUG         = 'welow-rrhh-import-employees';
	public const UPLOAD_ACTION     = 'welow_rrhh_csv_upload';
	public const CONFIRM_ACTION    = 'welow_rrhh_csv_confirm';
	private const UPLOAD_NONCE     = 'welow_rrhh_csv_upload_nonce';
	private const CONFIRM_NONCE    = 'welow_rrhh_csv_confirm_nonce';
	private const TRANSIENT_PREFIX = 'welow_rrhh_csv_import_';
	private const TRANSIENT_TTL    = 30 * MINUTE_IN_SECONDS;
	private const MAX_FILE_SIZE    = 5 * 1024 * 1024; // 5 MB.

	/**
	 * Importer (resuelto en runtime desde el container).
	 *
	 * @var EmployeeImporter
	 */
	private EmployeeImporter $importer;

	/**
	 * Constructor.
	 *
	 * @param EmployeeImporter $importer Importer.
	 */
	public function __construct( EmployeeImporter $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Handler de subida (admin_post_<UPLOAD_ACTION>).
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::UPLOAD_NONCE );

		if ( ! isset( $_FILES['welow_csv'] ) || ! is_array( $_FILES['welow_csv'] ) ) {
			$this->redirect_with_error( __( 'No se ha recibido ningún archivo.', 'welow-rrhh' ) );
		}

		// $_FILES no se "sanitiza" como un campo de texto; los componentes se validan más abajo
		// (extensión, MIME real, tamaño, is_uploaded_file). El nombre se pasa por sanitize_file_name.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = $_FILES['welow_csv'];

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->redirect_with_error( __( 'Error subiendo el archivo.', 'welow-rrhh' ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_FILE_SIZE ) {
			$this->redirect_with_error( __( 'Archivo demasiado grande (máx. 5 MB).', 'welow-rrhh' ) );
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

		$parser = new EmployeeCsvParser();
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
				'created_at'    => time(),
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
	 * Handler de confirmación (admin_post_<CONFIRM_ACTION>).
	 *
	 * @return void
	 */
	public function handle_confirm(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
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
	 * Handler de acciones GET (carga `load-<hook_suffix>`): descarga plantilla.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( 'download_template' !== $action ) {
			return;
		}
		if ( ! current_user_can( Capabilities::CAP_MANAGE_EMPLOYEES ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'welow_rrhh_csv_template' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="welow-rrhh-empleados-plantilla.csv"' );
		echo EmployeeCsvParser::template_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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
	 * Renderiza el formulario de subida + descarga de plantilla.
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
			'welow_rrhh_csv_template'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Importar empleados desde CSV', 'welow-rrhh' ); ?></h1>

			<?php $this->render_error_notice(); ?>

			<p>
				<?php esc_html_e( 'Sube un archivo CSV con la cabecera estándar. Verás un previo (dry-run) antes de aplicar los cambios.', 'welow-rrhh' ); ?>
			</p>
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
							<th scope="row"><label for="welow-csv"><?php esc_html_e( 'Archivo CSV', 'welow-rrhh' ); ?></label></th>
							<td>
								<input type="file" id="welow-csv" name="welow_csv" accept=".csv,text/csv" required />
								<p class="description"><?php esc_html_e( 'Tamaño máximo: 5 MB. Separadores admitidos: coma o punto y coma.', 'welow-rrhh' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Subir y previsualizar', 'welow-rrhh' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renderiza la preview (dry-run).
	 *
	 * @return void
	 */
	private function render_preview(): void {
		$data = get_transient( self::transient_key() );
		if ( ! is_array( $data ) || empty( $data['dry_run'] ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Sin previsualización activa', 'welow-rrhh' ) . '</h1>';
			echo '<p>' . esc_html__( 'Vuelve a subir el archivo CSV.', 'welow-rrhh' ) . '</p></div>';
			return;
		}

		$report = (array) $data['dry_run'];
		$stats  = EmployeeImporter::count_outcomes( $report );
		$total  = count( $report );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Previsualización del import', 'welow-rrhh' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: filename. */
					esc_html__( 'Archivo: %s', 'welow-rrhh' ),
					'<code>' . esc_html( (string) ( $data['original_name'] ?? '' ) ) . '</code>'
				);
				?>
			</p>

			<?php if ( ! empty( $data['parse_errors'] ) ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Errores de parseo:', 'welow-rrhh' ); ?></strong></p>
					<ul>
						<?php foreach ( (array) $data['parse_errors'] as $err ) : ?>
							<li><?php echo esc_html( (string) $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Resultado', 'welow-rrhh' ); ?></th>
						<th><?php esc_html_e( 'Filas', 'welow-rrhh' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><?php esc_html_e( 'Total', 'welow-rrhh' ); ?></td><td><strong><?php echo (int) $total; ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Se crearán', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ EmployeeImporter::OUTCOME_CREATE ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Se vincularán a usuario WP existente', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ EmployeeImporter::OUTCOME_LINK_EXISTING ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Ya existen (se omitirán)', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ EmployeeImporter::OUTCOME_SKIP_EXISTS ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Errores', 'welow-rrhh' ); ?></td><td><strong><?php echo (int) $stats[ EmployeeImporter::OUTCOME_ERROR ]; ?></strong></td></tr>
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
	 * Renderiza el reporte tras la ejecución.
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
		$stats  = EmployeeImporter::count_outcomes( $report );
		$total  = count( $report );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reporte de importación', 'welow-rrhh' ); ?></h1>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Resultado', 'welow-rrhh' ); ?></th>
						<th><?php esc_html_e( 'Filas', 'welow-rrhh' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><?php esc_html_e( 'Total', 'welow-rrhh' ); ?></td><td><strong><?php echo (int) $total; ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Creados', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ EmployeeImporter::OUTCOME_CREATE ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Vinculados a usuario existente', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ EmployeeImporter::OUTCOME_LINK_EXISTING ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Ya existían (omitidos)', 'welow-rrhh' ); ?></td><td><?php echo (int) $stats[ EmployeeImporter::OUTCOME_SKIP_EXISTS ]; ?></td></tr>
					<tr><td><?php esc_html_e( 'Errores', 'welow-rrhh' ); ?></td><td><strong><?php echo (int) $stats[ EmployeeImporter::OUTCOME_ERROR ]; ?></strong></td></tr>
				</tbody>
			</table>

			<h2 style="margin-top:24px;"><?php esc_html_e( 'Detalle por fila', 'welow-rrhh' ); ?></h2>
			<?php $this->render_report_table( $report ); ?>

			<p style="margin-top:16px;">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . EmployeesScreen::PAGE_SLUG ) ); ?>">
					<?php esc_html_e( 'Volver al listado de empleados', 'welow-rrhh' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
					<?php esc_html_e( 'Importar otro archivo', 'welow-rrhh' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Tabla común para preview y reporte.
	 *
	 * @param array<int, array<string, mixed>> $report Reporte.
	 * @return void
	 */
	private function render_report_table( array $report ): void {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( 'Línea', 'welow-rrhh' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Resultado', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Email', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Nombre', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Mensaje', 'welow-rrhh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report as $item ) : ?>
					<?php
					$outcome = (string) ( $item['outcome'] ?? '' );
					$row     = (array) ( $item['row'] ?? array() );
					$badge   = self::outcome_badge( $outcome );
					$name    = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
					?>
					<tr>
						<td><?php echo (int) ( $item['line'] ?? 0 ); ?></td>
						<td>
						<?php
						// $badge se construye en outcome_badge() con esc_attr/esc_html sobre cadenas controladas.
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $badge;
						?>
						</td>
						<td><?php echo esc_html( (string) ( $row['email'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( (string) ( $item['message'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Devuelve el HTML del badge según el outcome.
	 *
	 * @param string $outcome Outcome.
	 * @return string
	 */
	private static function outcome_badge( string $outcome ): string {
		$map = array(
			EmployeeImporter::OUTCOME_CREATE        => array( 'welow-rrhh__status--active', __( 'Crear', 'welow-rrhh' ) ),
			EmployeeImporter::OUTCOME_LINK_EXISTING => array( 'welow-rrhh__status--on_leave', __( 'Vincular', 'welow-rrhh' ) ),
			EmployeeImporter::OUTCOME_SKIP_EXISTS   => array( 'welow-rrhh__status--inactive', __( 'Omitir', 'welow-rrhh' ) ),
			EmployeeImporter::OUTCOME_ERROR         => array( 'welow-rrhh__status--inactive', __( 'Error', 'welow-rrhh' ) ),
		);
		if ( ! isset( $map[ $outcome ] ) ) {
			return esc_html( $outcome );
		}
		return sprintf(
			'<span class="welow-rrhh__status %s">%s</span>',
			esc_attr( $map[ $outcome ][0] ),
			esc_html( $map[ $outcome ][1] )
		);
	}

	/**
	 * Renderiza un notice de error si viene por query arg.
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
	 * Redirige al paso de upload con un mensaje de error.
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
	 * Clave del transient específico para el usuario actual.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return self::TRANSIENT_PREFIX . get_current_user_id();
	}
}
