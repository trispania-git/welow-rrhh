<?php
/**
 * Driver CSV (UTF-8 con BOM).
 *
 * @package Welow\RRHH\Exporters
 */

declare( strict_types=1 );

namespace Welow\RRHH\Exporters;

defined( 'ABSPATH' ) || exit;

/**
 * CsvDriver.
 */
final class CsvDriver implements ExporterDriverInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'csv';
	}

	/**
	 * {@inheritDoc}
	 */
	public function content_type(): string {
		return 'text/csv; charset=UTF-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function file_extension(): string {
		return 'csv';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Renderiza un CSV con BOM UTF-8.
	 *
	 * @param string[] $headers Cabecera.
	 * @param iterable $rows    Filas.
	 * @param string   $title   Título (ignorado en CSV).
	 * @return string
	 *
	 * @throws \RuntimeException Si no se puede abrir el buffer.
	 */
	public function render( array $headers, iterable $rows, string $title ): string {
		unset( $title );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$buf = fopen( 'php://temp', 'r+' );
		if ( false === $buf ) {
			throw new \RuntimeException( 'CsvDriver: no se pudo abrir buffer.' );
		}

		fputcsv( $buf, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $buf, (array) $row );
		}
		rewind( $buf );
		$content = (string) stream_get_contents( $buf );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $buf );

		return "\xEF\xBB\xBF" . $content;
	}
}
