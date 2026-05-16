<?php
/**
 * Pantalla "Fichajes" en wp-admin (lista + edición con motivo).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Admin;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Exporters\CsvDriver;
use Welow\RRHH\Modules\TimeTracking\Closure\MonthClosure;
use Welow\RRHH\Modules\TimeTracking\Data\EventType;
use Welow\RRHH\Modules\TimeTracking\Data\TimeEntry;
use Welow\RRHH\Modules\TimeTracking\Exporters\MonthlyReport;
use Welow\RRHH\Modules\TimeTracking\Service\TimeEntryService;
use Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * TimeEntriesScreen.
 */
final class TimeEntriesScreen {

	public const PAGE_SLUG   = 'welow-rrhh-time-entries';
	public const SAVE_ACTION = 'welow_rrhh_time_entry_save';
	private const SAVE_NONCE = 'welow_rrhh_time_entry_save_nonce';

	/**
	 * Servicio.
	 *
	 * @var TimeEntryService
	 */
	private TimeEntryService $service;

	/**
	 * Repo empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Servicio de cierre.
	 *
	 * @var MonthClosure
	 */
	private MonthClosure $closure;

	/**
	 * Generador de reporte mensual.
	 *
	 * @var MonthlyReport
	 */
	private MonthlyReport $report;

	/**
	 * Constructor.
	 *
	 * @param TimeEntryService   $service   Servicio.
	 * @param EmployeeRepository $employees Repo empleados.
	 * @param MonthClosure       $closure   Cierre.
	 * @param MonthlyReport      $report    Reporte mensual.
	 */
	public function __construct( TimeEntryService $service, EmployeeRepository $employees, MonthClosure $closure, MonthlyReport $report ) {
		$this->service   = $service;
		$this->employees = $employees;
		$this->closure   = $closure;
		$this->report    = $report;
	}

	/**
	 * Render principal (decide list/edit).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( TimeTrackingCapabilities::VIEW_TEAM ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		if ( 'export' === $action ) {
			$this->handle_export();
			return;
		}
		if ( 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
			$entry = $id > 0 ? $this->service->repository()->find_by_id( $id ) : null;
			if ( null === $entry ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Fichaje no encontrado', 'welow-rrhh' ) . '</h1></div>';
				return;
			}
			$this->render_edit_form( $entry );
			return;
		}

		$this->render_list();
	}

	/**
	 * Handler POST de edición.
	 *
	 * @return void
	 */
	public function handle_post_save(): void {
		if ( ! current_user_can( TimeTrackingCapabilities::VIEW_TEAM ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );

		$id      = isset( $post['entry_id'] ) ? (int) $post['entry_id'] : 0;
		$entry   = $this->service->repository()->find_by_id( $id );
		$current = get_current_user_id();

		if ( null === $entry ) {
			$this->redirect_with_notice( 'error', __( 'Fichaje no encontrado.', 'welow-rrhh' ) );
		}

		$is_own = ( $entry->user_id === $current );
		if ( $is_own && ! current_user_can( TimeTrackingCapabilities::EDIT_OWN ) ) {
			$this->redirect_with_notice( 'error', __( 'No puedes editar tus propios fichajes.', 'welow-rrhh' ) );
		}
		if ( ! $is_own && ! current_user_can( TimeTrackingCapabilities::EDIT_ANY ) ) {
			$this->redirect_with_notice( 'error', __( 'No puedes editar fichajes ajenos.', 'welow-rrhh' ) );
		}

		$changes = array();
		if ( isset( $post['event_type'] ) ) {
			$changes['event_type'] = sanitize_key( (string) $post['event_type'] );
		}
		if ( isset( $post['occurred_at'] ) && '' !== $post['occurred_at'] ) {
			// El input datetime-local envía "Y-m-d\TH:i"; lo convertimos a "Y-m-d H:i:s".
			$raw  = (string) $post['occurred_at'];
			$norm = str_replace( 'T', ' ', $raw );
			if ( 16 === strlen( $norm ) ) {
				$norm .= ':00';
			}
			$changes['occurred_at'] = $norm;
		}
		if ( isset( $post['note'] ) ) {
			$changes['note'] = sanitize_textarea_field( (string) $post['note'] );
		}
		$reason = isset( $post['edit_reason'] ) ? sanitize_text_field( (string) $post['edit_reason'] ) : '';

		$result = $this->service->update_entry( $id, $changes, $current, $reason );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message(), $id );
		}

		$this->redirect_with_notice( 'updated', __( 'Fichaje actualizado.', 'welow-rrhh' ) );
	}

	/**
	 * Notices.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['welow_tt_notice'] ) ? sanitize_key( (string) $_GET['welow_tt_notice'] ) : '';
		if ( '' === $notice ) {
			return;
		}
		$class = 'updated' === $notice ? 'success' : 'error';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['welow_tt_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['welow_tt_msg'] ) ) : '';
		if ( '' === $msg ) {
			$msg = 'updated' === $notice ? __( 'Operación realizada.', 'welow-rrhh' ) : __( 'Ha habido un error.', 'welow-rrhh' );
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $msg ) );
	}

	/**
	 * Render list.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$table = new TimeEntriesListTable( $this->service->repository(), $this->employees, $this->closure );
		$table->prepare_items();

		// Botón "Exportar mes" sólo cuando hay un empleado filtrado.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$emp_id = isset( $_GET['emp'] ) ? (int) $_GET['emp'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from    = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
		$from_dt = '' !== $from ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $from ) : false;
		if ( false === $from_dt ) {
			$from_dt = new \DateTimeImmutable( 'now', wp_timezone() );
			$from_dt = $from_dt->modify( 'first day of this month' );
		}

		$export_args_base = array(
			'page'    => self::PAGE_SLUG,
			'action'  => 'export',
			'user_id' => $emp_id,
			'year'    => (int) $from_dt->format( 'Y' ),
			'month'   => (int) $from_dt->format( 'n' ),
		);
		$export_csv       = wp_nonce_url( add_query_arg( array_merge( $export_args_base, array( 'format' => 'csv' ) ), admin_url( 'admin.php' ) ), 'welow_rrhh_tt_export' );
		$export_pdf       = wp_nonce_url( add_query_arg( array_merge( $export_args_base, array( 'format' => 'pdf' ) ), admin_url( 'admin.php' ) ), 'welow_rrhh_tt_export' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Fichajes', 'welow-rrhh' ); ?></h1>
			<?php if ( $emp_id > 0 ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( $export_pdf ); ?>"><?php esc_html_e( 'Exportar mes (PDF)', 'welow-rrhh' ); ?></a>
				<a class="page-title-action" href="<?php echo esc_url( $export_csv ); ?>"><?php esc_html_e( 'Exportar mes (CSV)', 'welow-rrhh' ); ?></a>
			<?php else : ?>
				<span class="description" style="margin-left:8px;color:#777;">
					<?php esc_html_e( 'Filtra por un empleado para habilitar la exportación mensual.', 'welow-rrhh' ); ?>
				</span>
			<?php endif; ?>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handler de la descarga (action=export).
	 *
	 * @return void
	 */
	private function handle_export(): void {
		check_admin_referer( 'welow_rrhh_tt_export' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$format = isset( $_GET['format'] ) ? sanitize_key( (string) $_GET['format'] ) : 'pdf';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$year = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) wp_date( 'Y' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$month = isset( $_GET['month'] ) ? (int) $_GET['month'] : (int) wp_date( 'n' );

		if ( $user_id <= 0 || $month < 1 || $month > 12 ) {
			wp_die( esc_html__( 'Parámetros inválidos para la exportación.', 'welow-rrhh' ), '', array( 'response' => 400 ) );
		}

		// Permisos: VIEW_ALL ve cualquiera; VIEW_TEAM sólo su equipo o sí mismo.
		$current = get_current_user_id();
		$is_self = ( $user_id === $current );
		if ( ! $is_self ) {
			if ( ! current_user_can( TimeTrackingCapabilities::VIEW_ALL ) ) {
				if ( ! current_user_can( TimeTrackingCapabilities::VIEW_TEAM ) ) {
					wp_die( esc_html__( 'Sin permisos.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
				}
				$emp = $this->employees->find_by_user_id( $user_id );
				if ( null === $emp || $emp->manager_user_id !== $current ) {
					wp_die( esc_html__( 'Sólo puedes exportar fichajes de los empleados de tu equipo.', 'welow-rrhh' ), '', array( 'response' => 403 ) );
				}
			}
		}

		$report = $this->report->build( $user_id, $year, $month );
		$slug   = sprintf( 'fichajes-%04d-%02d-user%d', $year, $month, $user_id );

		if ( 'pdf' === $format && class_exists( '\\Dompdf\\Dompdf' ) ) {
			$html = $this->report->to_pdf_html( $report );
			self::send_pdf( $html, $slug . '.pdf' );
			return;
		}

		// Fallback CSV (también explícito si se pidió csv).
		$table = $this->report->to_csv_table( $report );
		$csv   = ( new CsvDriver() )->render( $table['headers'], $table['rows'], $slug );
		self::send_bytes( $csv, $slug . '.csv', 'text/csv; charset=UTF-8' );
	}

	/**
	 * Genera PDF con dompdf y lo descarga.
	 *
	 * @param string $html     HTML.
	 * @param string $filename Nombre.
	 * @return void
	 */
	private static function send_pdf( string $html, string $filename ): void {
		$class  = '\\Dompdf\\Dompdf';
		$dompdf = new $class();
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		$bytes = (string) $dompdf->output();
		self::send_bytes( $bytes, $filename, 'application/pdf' );
	}

	/**
	 * Envía bytes binarios con Content-Disposition attachment.
	 *
	 * @param string $bytes        Contenido.
	 * @param string $filename     Nombre.
	 * @param string $content_type MIME.
	 * @return void
	 */
	private static function send_bytes( string $bytes, string $filename, string $content_type ): void {
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $bytes;
		exit;
	}

	/**
	 * Render formulario de edición.
	 *
	 * @param TimeEntry $entry Evento.
	 * @return void
	 */
	private function render_edit_form( TimeEntry $entry ): void {
		$back_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$is_closed  = $this->closure->is_closed( $entry->occurred_at );
		$user       = get_userdata( $entry->user_id );
		$user_label = $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $entry->user_id;
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Editar fichaje', 'welow-rrhh' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Volver', 'welow-rrhh' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( $is_closed ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Mes cerrado.', 'welow-rrhh' ); ?></strong>
						<?php
						printf(
							/* translators: %d: minimum characters. */
							esc_html__( 'Sólo un usuario con permiso de cierre puede editar. El motivo debe tener al menos %d caracteres.', 'welow-rrhh' ),
							30
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="entry_id" value="<?php echo (int) $entry->id; ?>" />
				<?php wp_nonce_field( self::SAVE_NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Empleado', 'welow-rrhh' ); ?></th>
						<td><?php echo esc_html( $user_label ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="welow-tt-occurred-at"><?php esc_html_e( 'Fecha y hora', 'welow-rrhh' ); ?></label></th>
						<td><input type="datetime-local" id="welow-tt-occurred-at" name="occurred_at"
							value="<?php echo esc_attr( $entry->occurred_at->format( 'Y-m-d\TH:i' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="welow-tt-event"><?php esc_html_e( 'Tipo de evento', 'welow-rrhh' ); ?></label></th>
						<td>
							<select id="welow-tt-event" name="event_type">
								<?php foreach ( EventType::cases() as $ev ) : ?>
									<option value="<?php echo esc_attr( $ev->value ); ?>" <?php selected( $entry->event_type->value, $ev->value ); ?>>
										<?php echo esc_html( $ev->label() ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="welow-tt-note"><?php esc_html_e( 'Nota', 'welow-rrhh' ); ?></label></th>
						<td><textarea id="welow-tt-note" name="note" rows="3" class="large-text"><?php echo esc_textarea( (string) $entry->note ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="welow-tt-reason"><?php esc_html_e( 'Motivo de edición', 'welow-rrhh' ); ?> <span class="required">*</span></label></th>
						<td>
							<input type="text" id="welow-tt-reason" name="edit_reason" class="large-text" required
								placeholder="<?php esc_attr_e( 'Explica por qué se modifica este fichaje', 'welow-rrhh' ); ?>" />
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar cambios', 'welow-rrhh' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Redirect con notice via query args.
	 *
	 * @param string $type    updated|error.
	 * @param string $message Mensaje.
	 * @param int    $id      Si distinto de 0, vuelve al edit.
	 * @return void
	 */
	private function redirect_with_notice( string $type, string $message, int $id = 0 ): void {
		$args = array(
			'page'            => self::PAGE_SLUG,
			'welow_tt_notice' => $type,
			'welow_tt_msg'    => rawurlencode( $message ),
		);
		if ( $id > 0 ) {
			$args['action'] = 'edit';
			$args['id']     = $id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
