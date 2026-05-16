<?php
/**
 * Reporte mensual de fichajes "Registro horario" (§7.5 / §7.6).
 *
 * Compila los eventos de un (empleado, mes), agrupa por día y calcula
 * entrada/salida/pausa/horas trabajadas. Produce datos tabulares
 * reutilizables como CSV o como HTML apto para PDF (con branding).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Exporters
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Exporters;

use Welow\RRHH\Departments\DepartmentRepository;
use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Modules\TimeTracking\Data\TimeEntry;
use Welow\RRHH\Modules\TimeTracking\Repository\TimeEntryRepository;
use Welow\RRHH\Modules\TimeTracking\Support\TimeFormat;
use Welow\RRHH\Settings\CompanySettings;

defined( 'ABSPATH' ) || exit;

/**
 * MonthlyReport.
 */
final class MonthlyReport {

	/**
	 * Repo fichajes.
	 *
	 * @var TimeEntryRepository
	 */
	private TimeEntryRepository $entries;

	/**
	 * Repo empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Repo departamentos.
	 *
	 * @var DepartmentRepository
	 */
	private DepartmentRepository $departments;

	/**
	 * Settings.
	 *
	 * @var CompanySettings
	 */
	private CompanySettings $settings;

	/**
	 * Constructor.
	 *
	 * @param TimeEntryRepository  $entries     Repo eventos.
	 * @param EmployeeRepository   $employees   Repo empleados.
	 * @param DepartmentRepository $departments Repo departamentos.
	 * @param CompanySettings      $settings    Settings.
	 */
	public function __construct(
		TimeEntryRepository $entries,
		EmployeeRepository $employees,
		DepartmentRepository $departments,
		CompanySettings $settings
	) {
		$this->entries     = $entries;
		$this->employees   = $employees;
		$this->departments = $departments;
		$this->settings    = $settings;
	}

	/**
	 * Construye los datos del reporte para un (user_id, año, mes).
	 *
	 * @param int $user_id Usuario.
	 * @param int $year    Año (e.g. 2026).
	 * @param int $month   Mes (1-12).
	 * @return array{
	 *   user_id:int,
	 *   year:int,
	 *   month:int,
	 *   employee:array<string,mixed>,
	 *   company:array<string,mixed>,
	 *   days:array<string,array<string,mixed>>,
	 *   totals:array<string,int>
	 * }
	 */
	public function build( int $user_id, int $year, int $month ): array {
		$tz   = wp_timezone();
		$from = ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $tz ) )->setTime( 0, 0, 0 );
		$to   = $from->modify( 'last day of this month' )->setTime( 23, 59, 59 );

		$entries  = $this->entries->find_for_range( $user_id, $from, $to, 5000, 0 );
		$emp_data = $this->resolve_employee_data( $user_id );

		// Agrupa por día.
		$by_day = array();
		foreach ( $entries as $entry ) {
			$by_day[ $entry->date_key() ][] = $entry;
		}
		ksort( $by_day );

		$days        = array();
		$total_work  = 0;
		$total_break = 0;
		$cursor      = $from;
		while ( $cursor <= $to ) {
			$key          = $cursor->format( 'Y-m-d' );
			$day_events   = $by_day[ $key ] ?? array();
			$day_summary  = self::summarize_day( $day_events );
			$days[ $key ] = $day_summary;
			$total_work  += $day_summary['worked_seconds'];
			$total_break += $day_summary['break_seconds'];
			$cursor       = $cursor->modify( '+1 day' );
		}

		return array(
			'user_id'  => $user_id,
			'year'     => $year,
			'month'    => $month,
			'employee' => $emp_data,
			'company'  => $this->settings->section( CompanySettings::SECTION_COMPANY ),
			'days'     => $days,
			'totals'   => array(
				'worked_seconds' => $total_work,
				'break_seconds'  => $total_break,
			),
		);
	}

	/**
	 * Devuelve las filas CSV (header + filas) para el reporte.
	 *
	 * @param array<string,mixed> $report Output de build().
	 * @return array{headers:string[], rows:array<int,string[]>}
	 */
	public function to_csv_table( array $report ): array {
		$headers = array(
			__( 'Día', 'welow-rrhh' ),
			__( 'Entrada', 'welow-rrhh' ),
			__( 'Salida', 'welow-rrhh' ),
			__( 'Pausa', 'welow-rrhh' ),
			__( 'Horas trabajadas', 'welow-rrhh' ),
			__( 'Eventos', 'welow-rrhh' ),
			__( 'Notas', 'welow-rrhh' ),
		);

		$rows = array();
		foreach ( $report['days'] as $day => $summary ) {
			$rows[] = array(
				$day,
				$summary['first_in'] ?? '',
				$summary['last_out'] ?? '',
				TimeFormat::duration( (int) $summary['break_seconds'] ),
				TimeFormat::duration( (int) $summary['worked_seconds'] ),
				(string) count( $summary['events'] ),
				implode( ' | ', array_filter( array_map( static fn( $ev ): string => (string) ( $ev->note ?? '' ), $summary['events'] ) ) ),
			);
		}

		// Fila de totales al final.
		$rows[] = array(
			__( 'TOTAL', 'welow-rrhh' ),
			'',
			'',
			TimeFormat::duration( (int) $report['totals']['break_seconds'] ),
			TimeFormat::duration( (int) $report['totals']['worked_seconds'] ),
			'',
			'',
		);

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * HTML del Registro horario apto para PDF (con cabecera + tabla + firma).
	 *
	 * @param array<string,mixed> $report Output de build().
	 * @return string
	 */
	public function to_pdf_html( array $report ): string {
		$employee    = $report['employee'];
		$company     = $report['company'];
		$year        = (int) $report['year'];
		$month       = (int) $report['month'];
		$month_dt    = \DateTimeImmutable::createFromFormat( '!Y-m', sprintf( '%04d-%02d', $year, $month ) );
		$month_label = false !== $month_dt ? wp_date( 'F Y', $month_dt->getTimestamp() ) : sprintf( '%04d-%02d', $year, $month );

		$css = <<<'CSS'
		body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#1d2327;}
		.header{display:table;width:100%;border-bottom:2px solid #2271b1;padding-bottom:8px;margin-bottom:16px;}
		.header .company{display:table-cell;text-align:left;}
		.header .meta{display:table-cell;text-align:right;}
		.header h1{margin:0;font-size:16px;}
		.section{margin-bottom:16px;}
		.section h2{font-size:13px;margin:0 0 6px;border-bottom:1px solid #ccc;padding-bottom:4px;}
		.kv{display:table;width:100%;}
		.kv .row{display:table-row;}
		.kv .k,.kv .v{display:table-cell;padding:2px 8px 2px 0;}
		.kv .k{color:#555;width:120px;}
		table.entries{width:100%;border-collapse:collapse;font-size:10px;}
		table.entries th,table.entries td{border:1px solid #ddd;padding:4px 6px;text-align:left;}
		table.entries th{background:#f5f5f5;}
		table.entries tr.totals{background:#fafafa;font-weight:bold;}
		.footer{margin-top:24px;font-size:9px;color:#666;}
		.signatures{display:table;width:100%;margin-top:48px;}
		.signatures .col{display:table-cell;width:50%;text-align:center;border-top:1px solid #999;padding-top:6px;font-size:10px;}
		CSS;

		$html  = '<!doctype html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
		$html .= '<div class="header">';
		$html .= '<div class="company">';
		$html .= '<h1>' . self::e( (string) ( $company['name'] ?? '' ) ) . '</h1>';
		if ( ! empty( $company['cif'] ) ) {
			$html .= '<div>CIF: ' . self::e( (string) $company['cif'] ) . '</div>';
		}
		if ( ! empty( $company['address'] ) ) {
			$html .= '<div>' . nl2br( self::e( (string) $company['address'] ) ) . '</div>';
		}
		$html .= '</div>';
		$html .= '<div class="meta">';
		$html .= '<strong>' . self::e( __( 'REGISTRO HORARIO', 'welow-rrhh' ) ) . '</strong><br>';
		$html .= self::e( $month_label ) . '<br>';
		$html .= '<small>' . self::e( __( 'RDL 8/2019', 'welow-rrhh' ) ) . '</small>';
		$html .= '</div></div>';

		// Datos del empleado.
		$html .= '<div class="section"><h2>' . self::e( __( 'Empleado', 'welow-rrhh' ) ) . '</h2><div class="kv">';
		$html .= '<div class="row"><div class="k">' . self::e( __( 'Nombre', 'welow-rrhh' ) ) . '</div><div class="v">' . self::e( (string) ( $employee['name'] ?? '' ) ) . '</div></div>';
		if ( ! empty( $employee['code'] ) ) {
			$html .= '<div class="row"><div class="k">' . self::e( __( 'Código', 'welow-rrhh' ) ) . '</div><div class="v">' . self::e( (string) $employee['code'] ) . '</div></div>';
		}
		if ( ! empty( $employee['dni_nie'] ) ) {
			$html .= '<div class="row"><div class="k">' . self::e( __( 'DNI/NIE', 'welow-rrhh' ) ) . '</div><div class="v">' . self::e( (string) $employee['dni_nie'] ) . '</div></div>';
		}
		if ( ! empty( $employee['department'] ) ) {
			$html .= '<div class="row"><div class="k">' . self::e( __( 'Departamento', 'welow-rrhh' ) ) . '</div><div class="v">' . self::e( (string) $employee['department'] ) . '</div></div>';
		}
		if ( ! empty( $employee['hire_date'] ) ) {
			$html .= '<div class="row"><div class="k">' . self::e( __( 'Fecha alta', 'welow-rrhh' ) ) . '</div><div class="v">' . self::e( (string) $employee['hire_date'] ) . '</div></div>';
		}
		$html .= '</div></div>';

		// Tabla diaria.
		$html .= '<div class="section"><h2>' . self::e( __( 'Registro diario', 'welow-rrhh' ) ) . '</h2>';
		$html .= '<table class="entries"><thead><tr>';
		foreach ( array( __( 'Día', 'welow-rrhh' ), __( 'Entrada', 'welow-rrhh' ), __( 'Salida', 'welow-rrhh' ), __( 'Pausa', 'welow-rrhh' ), __( 'Horas', 'welow-rrhh' ), __( 'Notas', 'welow-rrhh' ) ) as $h ) {
			$html .= '<th>' . self::e( $h ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $report['days'] as $day => $summary ) {
			$notes = implode( ' | ', array_filter( array_map( static fn( $ev ): string => (string) ( $ev->note ?? '' ), $summary['events'] ) ) );
			$html .= '<tr>';
			$html .= '<td>' . self::e( $day ) . '</td>';
			$html .= '<td>' . self::e( (string) ( $summary['first_in'] ?? '' ) ) . '</td>';
			$html .= '<td>' . self::e( (string) ( $summary['last_out'] ?? '' ) ) . '</td>';
			$html .= '<td>' . self::e( TimeFormat::duration( (int) $summary['break_seconds'] ) ) . '</td>';
			$html .= '<td>' . self::e( TimeFormat::duration( (int) $summary['worked_seconds'] ) ) . '</td>';
			$html .= '<td>' . self::e( $notes ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '<tr class="totals">';
		$html .= '<td>' . self::e( __( 'TOTAL', 'welow-rrhh' ) ) . '</td><td></td><td></td>';
		$html .= '<td>' . self::e( TimeFormat::duration( (int) $report['totals']['break_seconds'] ) ) . '</td>';
		$html .= '<td>' . self::e( TimeFormat::duration( (int) $report['totals']['worked_seconds'] ) ) . '</td>';
		$html .= '<td></td>';
		$html .= '</tr>';
		$html .= '</tbody></table></div>';

		// Firmas.
		$html .= '<div class="signatures">';
		$html .= '<div class="col">' . self::e( __( 'Firma del trabajador', 'welow-rrhh' ) ) . '</div>';
		$html .= '<div class="col">' . self::e( __( 'Firma de la empresa', 'welow-rrhh' ) ) . '</div>';
		$html .= '</div>';

		$html .= '<div class="footer">';
		$html .= self::e( __( 'Documento generado conforme al Real Decreto-ley 8/2019, de 8 de marzo, de medidas urgentes de protección social y de lucha contra la precariedad laboral en la jornada de trabajo.', 'welow-rrhh' ) );
		$html .= '<br>';
		$html .= self::e( sprintf( __( 'Generado el %s', 'welow-rrhh' ), wp_date( 'Y-m-d H:i' ) ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
		$html .= '</div>';

		$html .= '</body></html>';
		return $html;
	}

	/**
	 * Resuelve los datos del empleado (nombre, dni, departamento, fecha alta).
	 *
	 * @param int $user_id Usuario.
	 * @return array<string,mixed>
	 */
	private function resolve_employee_data( int $user_id ): array {
		$user = get_userdata( $user_id );
		$emp  = $this->employees->find_by_user_id( $user_id );
		$dept = null;
		if ( $emp && null !== $emp->department_id ) {
			$dept = $this->departments->find_by_id( $emp->department_id );
		}
		return array(
			'name'       => $emp ? $emp->full_name() : ( $user ? $user->display_name : '' ),
			'code'       => $emp ? $emp->employee_code : '',
			'dni_nie'    => $emp ? $emp->dni_nie : '',
			'department' => $dept ? $dept->name : '',
			'hire_date'  => $emp && $emp->hire_date ? $emp->hire_date->format( 'Y-m-d' ) : '',
			'email'      => $user ? $user->user_email : '',
		);
	}

	/**
	 * Resume los eventos de un día: entrada, salida, pausa, horas trabajadas.
	 *
	 * @param TimeEntry[] $events Eventos del día (ordenados por occurred_at).
	 * @return array{first_in:?string,last_out:?string,break_seconds:int,worked_seconds:int,events:TimeEntry[]}
	 */
	private static function summarize_day( array $events ): array {
		$first_in   = null;
		$last_out   = null;
		$break_open = null;
		$break_sec  = 0;
		$work_open  = null;
		$work_sec   = 0;

		foreach ( $events as $ev ) {
			$ts = $ev->occurred_at->getTimestamp();
			switch ( $ev->event_type->value ) {
				case 'punch_in':
					if ( null === $first_in ) {
						$first_in = $ev->occurred_at->format( 'H:i' );
					}
					$work_open = $ts;
					break;
				case 'break_start':
					if ( null !== $work_open ) {
						$work_sec += $ts - $work_open;
						$work_open = null;
					}
					$break_open = $ts;
					break;
				case 'break_end':
					if ( null !== $break_open ) {
						$break_sec += $ts - $break_open;
						$break_open = null;
					}
					$work_open = $ts;
					break;
				case 'punch_out':
					if ( null !== $work_open ) {
						$work_sec += $ts - $work_open;
						$work_open = null;
					}
					$last_out = $ev->occurred_at->format( 'H:i' );
					break;
			}
		}

		return array(
			'first_in'       => $first_in,
			'last_out'       => $last_out,
			'break_seconds'  => max( 0, $break_sec ),
			'worked_seconds' => max( 0, $work_sec ),
			'events'         => $events,
		);
	}

	/**
	 * Helper de escape HTML para uso interno (PDF).
	 *
	 * @param string $s Texto.
	 * @return string
	 */
	private static function e( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
