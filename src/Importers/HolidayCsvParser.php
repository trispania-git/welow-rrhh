<?php
/**
 * Parser de CSV de festivos.
 *
 * Cabecera esperada (en español, según §6.3 de la spec):
 *   fecha,nombre,tipo
 *
 * También se aceptan las variantes inglés: date, name, scope.
 *
 * @package Welow\RRHH\Importers
 */

declare( strict_types=1 );

namespace Welow\RRHH\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Parser CSV de festivos.
 */
final class HolidayCsvParser {

	/**
	 * Alias de cabecera por columna canónica.
	 */
	private const HEADER_ALIASES = array(
		'date'  => array( 'date', 'fecha' ),
		'name'  => array( 'name', 'nombre' ),
		'scope' => array( 'scope', 'tipo', 'tipo_festivo', 'ambito' ),
	);

	/**
	 * Parsea un archivo CSV en disco.
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

		// Detectar y descartar BOM UTF-8.
		$bom = "\xEF\xBB\xBF";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$head    = fread( $handle, 3 );
		$has_bom = ( $head === $bom );
		if ( ! $has_bom ) {
			rewind( $handle );
		}

		$pos        = ftell( $handle );
		$first_line = fgets( $handle );
		fseek( $handle, $pos );
		$delim = self::detect_delimiter( (string) $first_line );

		$header_row = fgetcsv( $handle, 0, $delim );
		if ( false === $header_row || array() === $header_row ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array(
				'rows'   => array(),
				'errors' => array( 'empty' => __( 'El CSV está vacío.', 'welow-rrhh' ) ),
			);
		}
		$raw_header = array_map(
			static fn( $h ): string => strtolower( trim( (string) $h ) ),
			$header_row
		);

		// Mapear índices de columna a nombre canónico.
		$canonical_by_index = array();
		foreach ( $raw_header as $i => $col ) {
			foreach ( self::HEADER_ALIASES as $canonical => $aliases ) {
				if ( in_array( $col, $aliases, true ) ) {
					$canonical_by_index[ $i ] = $canonical;
				}
			}
		}

		// Validar columnas requeridas.
		foreach ( array( 'date', 'name' ) as $req ) {
			if ( ! in_array( $req, $canonical_by_index, true ) ) {
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

		$rows     = array();
		$errors   = array();
		$line_num = 1;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $row = fgetcsv( $handle, 0, $delim ) ) ) {
			++$line_num;
			if ( null === $row || ( 1 === count( $row ) && null === $row[0] ) ) {
				continue;
			}
			if ( count( $row ) !== count( $raw_header ) ) {
				$errors[ $line_num ] = sprintf(
					/* translators: 1: line number, 2: expected columns, 3: found columns. */
					__( 'Línea %1$d: número de columnas inválido (esperado %2$d, encontrado %3$d).', 'welow-rrhh' ),
					$line_num,
					count( $raw_header ),
					count( $row )
				);
				continue;
			}

			$assoc = array();
			foreach ( $row as $i => $value ) {
				$canonical = $canonical_by_index[ $i ] ?? null;
				if ( null === $canonical ) {
					continue;
				}
				$assoc[ $canonical ] = trim( (string) $value );
			}

			if ( empty( $assoc['date'] ) && empty( $assoc['name'] ) ) {
				continue; // Fila vacía.
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
	 * Heurística simple coma/punto y coma.
	 *
	 * @param string $line Primera línea.
	 * @return string
	 */
	private static function detect_delimiter( string $line ): string {
		$commas     = substr_count( $line, ',' );
		$semicolons = substr_count( $line, ';' );
		return $semicolons > $commas ? ';' : ',';
	}

	/**
	 * CSV de plantilla descargable (con BOM, en español).
	 *
	 * @return string
	 */
	public static function template_content(): string {
		$header = array( 'fecha', 'nombre', 'tipo' );
		$year   = (int) gmdate( 'Y' );
		$rows   = array(
			array( $year . '-01-01', 'Año Nuevo', 'national' ),
			array( $year . '-01-06', 'Epifanía del Señor', 'national' ),
			array( $year . '-05-01', 'Día del Trabajo', 'national' ),
			array( $year . '-09-08', 'Fiesta local', 'local' ),
		);

		$buf = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $buf, $header );
		foreach ( $rows as $r ) {
			fputcsv( $buf, $r );
		}
		rewind( $buf );
		$content = (string) stream_get_contents( $buf );
		fclose( $buf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return "\xEF\xBB\xBF" . $content;
	}
}
