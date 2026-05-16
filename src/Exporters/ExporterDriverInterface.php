<?php
/**
 * Contrato común para drivers de exportación (CSV, PDF, etc.).
 *
 * @package Welow\RRHH\Exporters
 */

declare( strict_types=1 );

namespace Welow\RRHH\Exporters;

defined( 'ABSPATH' ) || exit;

/**
 * Driver de exportación.
 */
interface ExporterDriverInterface {

	/**
	 * Slug del driver (csv|pdf|xlsx|json).
	 */
	public function slug(): string;

	/**
	 * MIME type del archivo generado.
	 */
	public function content_type(): string;

	/**
	 * Extensión del archivo (sin punto).
	 */
	public function file_extension(): string;

	/**
	 * Indica si el driver puede usarse en este entorno (dependencias presentes, etc.).
	 */
	public function is_available(): bool;

	/**
	 * Genera el contenido del archivo en memoria.
	 *
	 * @param string[] $headers Cabecera (nombres de columna).
	 * @param iterable $rows    Filas (cada fila = array de columnas alineadas con headers).
	 * @param string   $title   Título del documento (útil para PDF).
	 * @return string Bytes del archivo (incluido posible BOM).
	 *
	 * @throws \RuntimeException Si la generación falla.
	 */
	public function render( array $headers, iterable $rows, string $title ): string;
}
