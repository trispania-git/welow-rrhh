<?php
/**
 * Importador de festivos desde filas parseadas.
 *
 * Outcomes:
 *   - create / skip_exists (mismo fecha+scope) / error
 *
 * @package Welow\RRHH\Importers
 */

declare( strict_types=1 );

namespace Welow\RRHH\Importers;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Holidays\HolidayRepository;
use Welow\RRHH\Holidays\HolidayService;
use Welow\RRHH\Support\Data\HolidayScope;

defined( 'ABSPATH' ) || exit;

/**
 * Procesa un batch de filas CSV de festivos.
 */
final class HolidayImporter {

	public const OUTCOME_CREATE      = 'create';
	public const OUTCOME_SKIP_EXISTS = 'skip_exists';
	public const OUTCOME_ERROR       = 'error';

	/**
	 * Servicio.
	 *
	 * @var HolidayService
	 */
	private HolidayService $service;

	/**
	 * Repositorio.
	 *
	 * @var HolidayRepository
	 */
	private HolidayRepository $repository;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param HolidayService    $service    Servicio.
	 * @param HolidayRepository $repository Repositorio.
	 * @param AuditLogger       $audit      Audit logger.
	 */
	public function __construct( HolidayService $service, HolidayRepository $repository, AuditLogger $audit ) {
		$this->service    = $service;
		$this->repository = $repository;
		$this->audit      = $audit;
	}

	/**
	 * Análisis sin efectos (predicción del outcome).
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
	 * Ejecuta el import.
	 *
	 * @param array<int, array<string, mixed>> $rows Filas.
	 * @return array<int, array<string, mixed>>
	 */
	public function execute( array $rows ): array {
		$report = array();
		foreach ( $rows as $row ) {
			$report[] = $this->process_row( $row );
		}
		$this->audit->log(
			'csv_import',
			'holiday',
			null,
			array(
				'total' => count( $rows ),
				'stats' => self::count_outcomes( $report ),
			)
		);
		return $report;
	}

	/**
	 * Suma de outcomes.
	 *
	 * @param array<int, array<string, mixed>> $report Reporte.
	 * @return array<string, int>
	 */
	public static function count_outcomes( array $report ): array {
		$counts = array(
			self::OUTCOME_CREATE      => 0,
			self::OUTCOME_SKIP_EXISTS => 0,
			self::OUTCOME_ERROR       => 0,
		);
		foreach ( $report as $item ) {
			$outcome            = (string) ( $item['outcome'] ?? '' );
			$counts[ $outcome ] = ( $counts[ $outcome ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * Predice el outcome de una fila.
	 *
	 * @param array<string, mixed> $row Fila.
	 * @return array<string, mixed>
	 */
	private function analyze_row( array $row ): array {
		$line = isset( $row['__line'] ) ? (int) $row['__line'] : 0;

		$date_str = isset( $row['date'] ) ? trim( (string) $row['date'] ) : '';
		$name     = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
		if ( '' === $date_str ) {
			return self::result( $line, self::OUTCOME_ERROR, __( 'Fecha vacía.', 'welow-rrhh' ), $row );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date_str );
		if ( false === $date ) {
			return self::result( $line, self::OUTCOME_ERROR, __( 'Fecha con formato inválido (use YYYY-MM-DD).', 'welow-rrhh' ), $row );
		}
		if ( '' === $name ) {
			return self::result( $line, self::OUTCOME_ERROR, __( 'Nombre del festivo vacío.', 'welow-rrhh' ), $row );
		}

		$scope_raw = isset( $row['scope'] ) ? strtolower( trim( (string) $row['scope'] ) ) : '';
		$scope     = '' === $scope_raw ? HolidayScope::get_default() : HolidayScope::tryFrom( $scope_raw );
		if ( null === $scope ) {
			return self::result( $line, self::OUTCOME_ERROR, __( 'Ámbito inválido (national/regional/local/company).', 'welow-rrhh' ), $row );
		}

		$existing = $this->repository->find_by_date_scope( $date->format( 'Y-m-d' ), $scope );
		if ( null !== $existing ) {
			return self::result( $line, self::OUTCOME_SKIP_EXISTS, __( 'Ya existe; se omite.', 'welow-rrhh' ), $row );
		}

		return self::result( $line, self::OUTCOME_CREATE, __( 'Crear festivo.', 'welow-rrhh' ), $row );
	}

	/**
	 * Procesa una fila aplicando los cambios.
	 *
	 * @param array<string, mixed> $row Fila.
	 * @return array<string, mixed>
	 */
	private function process_row( array $row ): array {
		$analysis = $this->analyze_row( $row );
		if ( in_array( $analysis['outcome'], array( self::OUTCOME_ERROR, self::OUTCOME_SKIP_EXISTS ), true ) ) {
			return $analysis;
		}

		$result = $this->service->create(
			array(
				'date'  => isset( $row['date'] ) ? (string) $row['date'] : '',
				'name'  => isset( $row['name'] ) ? (string) $row['name'] : '',
				'scope' => isset( $row['scope'] ) ? (string) $row['scope'] : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::result(
				(int) $analysis['line'],
				self::OUTCOME_ERROR,
				implode( '; ', $result->get_error_messages() ),
				$row
			);
		}

		return array_merge( $analysis, array( 'holiday_id' => $result->id ) );
	}

	/**
	 * Estructura uniforme de un item.
	 *
	 * @param int                  $line    Línea original.
	 * @param string               $outcome Outcome.
	 * @param string               $message Mensaje.
	 * @param array<string, mixed> $row     Fila.
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
