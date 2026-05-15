<?php
/**
 * Parser de CSV de empleados (§15.1 de la especificación).
 *
 * Detecta BOM UTF-8 y delimitador (, o ;), valida cabecera y normaliza
 * los nombres de columnas. Devuelve filas asociativas + errores de
 * parseo por línea. No realiza validaciones de negocio (las hace el
 * Importer/Service).
 *
 * @package Welow\RRHH\Importers
 */

declare( strict_types=1 );

namespace Welow\RRHH\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Parser CSV.
 */
final class EmployeeCsvParser {

	/**
	 * Columnas requeridas.
	 */
	public const REQUIRED_COLUMNS = array( 'email', 'first_name', 'last_name' );

	/**
	 * Columnas opcionales reconocidas (cualquier otra columna se ignora).
	 */
	public const OPTIONAL_COLUMNS = array(
		'dni_nie',
		'employee_code',
		'department',
		'position',
		'manager_email',
		'hire_date',
		'weekly_hours',
		'vacation_days_override',
		'role',
	);

	/**
	 * Parsea un archivo CSV en disco.
	 *
	 * Estructura devuelta:
	 *   ['rows' => array<int, array<string, mixed>>, 'errors' => array<string|int, string>]
	 *
	 * Cada fila contiene la clave `__line` con el número de línea original
	 * (1-indexed, contando la cabecera como 1).
	 *
	 * @param string $filepath Ruta al CSV.
	 * @return array{rows: array<int, array<string, mixed>>, errors: array<string|int, string>}
	 */
	public function parse_file( string $filepath ): array {
		if ( ! is_readable( $filepath ) ) {
			return array(
				'rows'   => array(),
				'errors' => array( 'file' => __( 'No se puede leer el archivo subido.', 'welow-rrhh' ) ),
			);
		}

		$handle = fopen( $filepath, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array(
				'rows'   => array(),
				'errors' => array( 'file' => __( 'No se pudo abrir el archivo.', 'welow-rrhh' ) ),
			);
		}

		// Detectar y descartar BOM UTF-8. WP_Filesystem no encaja aquí: estamos
		// procesando un archivo subido por el usuario que aún no está en la jerarquía WP.
		$bom = "\xEF\xBB\xBF";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$head    = fread( $handle, 3 );
		$has_bom = ( $head === $bom );
		if ( ! $has_bom ) {
			rewind( $handle );
		}

		// Detectar delimitador inspeccionando la primera línea.
		$pos        = ftell( $handle );
		$first_line = fgets( $handle );
		fseek( $handle, $pos );
		$delim = self::detect_delimiter( (string) $first_line );

		// Leer cabecera.
		$header_row = fgetcsv( $handle, 0, $delim );
		if ( false === $header_row || array() === $header_row ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array(
				'rows'   => array(),
				'errors' => array( 'empty' => __( 'El CSV está vacío.', 'welow-rrhh' ) ),
			);
		}
		$header = array_map(
			static fn( $h ): string => strtolower( trim( (string) $h ) ),
			$header_row
		);

		// Validar columnas requeridas.
		foreach ( self::REQUIRED_COLUMNS as $req ) {
			if ( ! in_array( $req, $header, true ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return array(
					'rows'   => array(),
					'errors' => array(
						'header' => sprintf(
							/* translators: %s: column name. */
							__( 'Falta la columna requerida: %s', 'welow-rrhh' ),
							$req
						),
					),
				);
			}
		}

		// Leer filas.
		$rows     = array();
		$errors   = array();
		$line_num = 1;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $row = fgetcsv( $handle, 0, $delim ) ) ) {
			++$line_num;
			if ( null === $row || ( 1 === count( $row ) && null === $row[0] ) ) {
				// Línea vacía.
				continue;
			}
			if ( count( $row ) !== count( $header ) ) {
				$errors[ $line_num ] = sprintf(
					/* translators: 1: line number, 2: expected columns, 3: found columns. */
					__( 'Línea %1$d: número de columnas inválido (esperado %2$d, encontrado %3$d).', 'welow-rrhh' ),
					$line_num,
					count( $header ),
					count( $row )
				);
				continue;
			}
			$assoc = array_combine(
				$header,
				array_map(
					static fn( $v ): string => trim( (string) $v ),
					$row
				)
			);
			if ( empty( $assoc['email'] ) && empty( $assoc['first_name'] ) && empty( $assoc['last_name'] ) ) {
				// Fila completamente vacía: ignorar sin error.
				continue;
			}
			$assoc['__line'] = $line_num;
			$rows[]          = $assoc;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * Heurística simple para elegir entre coma y punto y coma como delimitador.
	 *
	 * @param string $line Primera línea (cabecera).
	 * @return string ',' o ';'.
	 */
	private static function detect_delimiter( string $line ): string {
		$commas     = substr_count( $line, ',' );
		$semicolons = substr_count( $line, ';' );
		return $semicolons > $commas ? ';' : ',';
	}

	/**
	 * Devuelve el contenido CSV de la plantilla descargable (con BOM UTF-8).
	 *
	 * @return string
	 */
	public static function template_content(): string {
		$header = array_merge( self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS );
		$sample = array(
			'ana@empresa.com',
			'Ana',
			'García López',
			'12345678Z',
			'EMP001',
			'Marketing',
			'Diseñadora',
			'manager@empresa.com',
			'2024-09-01',
			'40',
			'22',
			'welow_employee',
		);

		$buf = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $buf, $header );
		fputcsv( $buf, $sample );
		rewind( $buf );
		$content = (string) stream_get_contents( $buf );
		fclose( $buf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return "\xEF\xBB\xBF" . $content;
	}
}
