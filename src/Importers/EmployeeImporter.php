<?php
/**
 * Importador de empleados desde filas parseadas (CSV) — §15.1.
 *
 * Expone dos modos:
 *   - dry_run(): analiza cada fila y predice el outcome (create / link
 *     existing user / skip ya existe / error de validación). No toca BD.
 *   - execute(): aplica realmente, devolviendo el reporte por fila.
 *
 * El reporte por fila tiene la forma:
 *   array{
 *     line: int,
 *     outcome: 'create'|'link_existing'|'skip_exists'|'error',
 *     message: string,
 *     row: array<string, mixed>,
 *     user_id?: int,
 *     employee_id?: int,
 *   }
 *
 * @package Welow\RRHH\Importers
 */

declare( strict_types=1 );

namespace Welow\RRHH\Importers;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Employees\EmployeeService;
use Welow\RRHH\Support\Data\Employee;
use Welow\RRHH\Support\Validation\Dni;
use Welow\RRHH\Support\Validation\EmployeeValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Procesa un batch de filas CSV.
 */
final class EmployeeImporter {

	public const OUTCOME_CREATE        = 'create';
	public const OUTCOME_LINK_EXISTING = 'link_existing';
	public const OUTCOME_SKIP_EXISTS   = 'skip_exists';
	public const OUTCOME_ERROR         = 'error';

	/**
	 * Servicio de empleados.
	 *
	 * @var EmployeeService
	 */
	private EmployeeService $service;

	/**
	 * Repositorio de empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $repository;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param EmployeeService    $service    Servicio.
	 * @param EmployeeRepository $repository Repositorio.
	 * @param AuditLogger        $audit      Audit logger.
	 */
	public function __construct( EmployeeService $service, EmployeeRepository $repository, AuditLogger $audit ) {
		$this->service    = $service;
		$this->repository = $repository;
		$this->audit      = $audit;
	}

	/**
	 * Análisis sin efectos (predicción del outcome de cada fila).
	 *
	 * @param array<int, array<string, mixed>> $rows Filas parseadas.
	 * @return array<int, array<string, mixed>>
	 */
	public function dry_run( array $rows ): array {
		$report = array();
		foreach ( $rows as $row ) {
			$report[] = $this->analyze_row( $row );
		}
		return $report;
	}

	/**
	 * Ejecuta el import aplicando los cambios en BD.
	 *
	 * @param array<int, array<string, mixed>> $rows Filas parseadas.
	 * @return array<int, array<string, mixed>> Reporte por fila.
	 */
	public function execute( array $rows ): array {
		$report = array();
		foreach ( $rows as $row ) {
			$report[] = $this->process_row( $row );
		}

		$this->audit->log(
			'csv_import',
			'employee',
			null,
			array(
				'total' => count( $rows ),
				'stats' => self::count_outcomes( $report ),
			)
		);

		return $report;
	}

	/**
	 * Suma de outcomes para resumen del reporte.
	 *
	 * @param array<int, array<string, mixed>> $report Reporte.
	 * @return array<string, int>
	 */
	public static function count_outcomes( array $report ): array {
		$counts = array(
			self::OUTCOME_CREATE        => 0,
			self::OUTCOME_LINK_EXISTING => 0,
			self::OUTCOME_SKIP_EXISTS   => 0,
			self::OUTCOME_ERROR         => 0,
		);
		foreach ( $report as $item ) {
			$outcome            = (string) ( $item['outcome'] ?? '' );
			$counts[ $outcome ] = ( $counts[ $outcome ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * Predice el outcome de una fila (sin tocar BD).
	 *
	 * @param array<string, mixed> $row Fila parseada (con __line).
	 * @return array<string, mixed>
	 */
	private function analyze_row( array $row ): array {
		$line  = isset( $row['__line'] ) ? (int) $row['__line'] : 0;
		$email = isset( $row['email'] ) ? sanitize_email( (string) $row['email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return self::result( $line, self::OUTCOME_ERROR, __( 'Email inválido o vacío.', 'welow-rrhh' ), $row );
		}

		$format_errors = $this->validate_row_format( $row );
		if ( ! empty( $format_errors ) ) {
			return self::result( $line, self::OUTCOME_ERROR, implode( '; ', $format_errors ), $row );
		}

		$existing_user_id = email_exists( $email );
		if ( false === $existing_user_id ) {
			return self::result( $line, self::OUTCOME_CREATE, __( 'Creará usuario WP nuevo y empleado.', 'welow-rrhh' ), $row );
		}

		$existing_employee = $this->repository->find_by_user_id( (int) $existing_user_id );
		if ( null !== $existing_employee ) {
			return array_merge(
				self::result( $line, self::OUTCOME_SKIP_EXISTS, __( 'Ya existe un empleado con ese email; se omite.', 'welow-rrhh' ), $row ),
				array(
					'user_id'     => (int) $existing_user_id,
					'employee_id' => $existing_employee->id,
				)
			);
		}

		return array_merge(
			self::result( $line, self::OUTCOME_LINK_EXISTING, __( 'Vinculará el usuario WP existente al nuevo empleado.', 'welow-rrhh' ), $row ),
			array( 'user_id' => (int) $existing_user_id )
		);
	}

	/**
	 * Aplica realmente la fila.
	 *
	 * @param array<string, mixed> $row Fila parseada.
	 * @return array<string, mixed>
	 */
	private function process_row( array $row ): array {
		$analysis = $this->analyze_row( $row );
		$line     = (int) $analysis['line'];

		if ( in_array( $analysis['outcome'], array( self::OUTCOME_ERROR, self::OUTCOME_SKIP_EXISTS ), true ) ) {
			return $analysis;
		}

		$payload = $this->build_payload( $row );
		$opts    = array(
			'send_welcome_email' => false,
			'role'               => self::resolve_role( $row ),
		);

		if ( self::OUTCOME_LINK_EXISTING === $analysis['outcome'] ) {
			$result = $this->service->create_for_existing_user( (int) $analysis['user_id'], $payload, $opts );
		} else {
			$result = $this->service->create_with_user( $payload, $opts );
		}

		if ( is_wp_error( $result ) ) {
			return self::result( $line, self::OUTCOME_ERROR, implode( '; ', $result->get_error_messages() ), $row );
		}

		if ( $result instanceof Employee ) {
			return array_merge(
				self::result( $line, $analysis['outcome'], (string) $analysis['message'], $row ),
				array(
					'user_id'     => $result->user_id,
					'employee_id' => $result->id,
				)
			);
		}

		return self::result( $line, self::OUTCOME_ERROR, __( 'Resultado inesperado del servicio.', 'welow-rrhh' ), $row );
	}

	/**
	 * Validación de formato a nivel de fila.
	 *
	 * @param array<string, mixed> $row Fila.
	 * @return string[]
	 */
	private function validate_row_format( array $row ): array {
		$errors = array();

		if ( empty( $row['first_name'] ) ) {
			$errors[] = __( 'first_name vacío', 'welow-rrhh' );
		}
		if ( empty( $row['last_name'] ) ) {
			$errors[] = __( 'last_name vacío', 'welow-rrhh' );
		}
		if ( ! empty( $row['dni_nie'] ) && null === Dni::normalize( (string) $row['dni_nie'] ) ) {
			$errors[] = __( 'DNI/NIE no válido', 'welow-rrhh' );
		}
		if ( ! empty( $row['hire_date'] ) && false === EmployeeValidator::parse_date( (string) $row['hire_date'] ) ) {
			$errors[] = __( 'hire_date inválida (use YYYY-MM-DD)', 'welow-rrhh' );
		}
		if ( ! empty( $row['weekly_hours'] ) ) {
			$h = (float) $row['weekly_hours'];
			if ( $h <= 0 || $h > 168 ) {
				$errors[] = __( 'weekly_hours fuera de rango', 'welow-rrhh' );
			}
		}
		if ( ! empty( $row['vacation_days_override'] ) ) {
			$d = (int) $row['vacation_days_override'];
			if ( $d < 0 || $d > 365 ) {
				$errors[] = __( 'vacation_days_override fuera de rango', 'welow-rrhh' );
			}
		}
		if ( ! empty( $row['manager_email'] ) ) {
			$mgr = get_user_by( 'email', sanitize_email( (string) $row['manager_email'] ) );
			if ( false === $mgr ) {
				$errors[] = __( 'manager_email no encontrado entre los usuarios de WordPress', 'welow-rrhh' );
			}
		}

		return $errors;
	}

	/**
	 * Convierte una fila CSV en un payload listo para EmployeeService.
	 *
	 * @param array<string, mixed> $row Fila.
	 * @return array<string, mixed>
	 */
	private function build_payload( array $row ): array {
		$payload = array(
			'email'                  => sanitize_email( (string) ( $row['email'] ?? '' ) ),
			'first_name'             => (string) ( $row['first_name'] ?? '' ),
			'last_name'              => (string) ( $row['last_name'] ?? '' ),
			'dni_nie'                => (string) ( $row['dni_nie'] ?? '' ),
			'employee_code'          => (string) ( $row['employee_code'] ?? '' ),
			'position'               => (string) ( $row['position'] ?? '' ),
			'hire_date'              => (string) ( $row['hire_date'] ?? '' ),
			'weekly_hours'           => (string) ( $row['weekly_hours'] ?? '' ),
			'vacation_days_override' => (string) ( $row['vacation_days_override'] ?? '' ),
		);

		if ( ! empty( $row['manager_email'] ) ) {
			$mgr = get_user_by( 'email', sanitize_email( (string) $row['manager_email'] ) );
			if ( false !== $mgr ) {
				$payload['manager_user_id'] = (int) $mgr->ID;
			}
		}

		// TODO(welow): cuando el módulo de Departamentos exista (hito 4), resolver
		// `department` por nombre y enlazarlo. Por ahora guardamos el valor crudo en meta.
		if ( ! empty( $row['department'] ) ) {
			$payload['meta'] = array( 'department_pending' => (string) $row['department'] );
		}

		return $payload;
	}

	/**
	 * Resuelve el rol a asignar al WP_User creado / vinculado.
	 *
	 * @param array<string, mixed> $row Fila.
	 * @return string
	 */
	private static function resolve_role( array $row ): string {
		if ( empty( $row['role'] ) ) {
			return \Welow\RRHH\Roles\Capabilities::ROLE_EMPLOYEE;
		}
		$slug = sanitize_key( (string) $row['role'] );
		return '' !== $slug ? $slug : \Welow\RRHH\Roles\Capabilities::ROLE_EMPLOYEE;
	}

	/**
	 * Estructura uniforme de un item del reporte.
	 *
	 * @param int                  $line    Línea original.
	 * @param string               $outcome Outcome.
	 * @param string               $message Mensaje.
	 * @param array<string, mixed> $row     Fila original.
	 * @return array<string, mixed>
	 */
	private static function result( int $line, string $outcome, string $message, array $row ): array {
		return array(
			'line'    => $line,
			'outcome' => $outcome,
			'message' => $message,
			'row'     => $row,
		);
	}
}
