<?php
/**
 * Driver PDF (dompdf opcional).
 *
 * §6.6: "PDF vía librería ligera tipo dompdf embebida vía Composer, declarada
 * como dependencia opcional con fallback a CSV si no está disponible."
 *
 * Esta implementación detecta `Dompdf\Dompdf` en runtime. Si no está
 * instalado:
 *   - is_available() devuelve false.
 *   - render() lanza RuntimeException con instrucciones.
 *
 * El orquestador (Exporter) consulta is_available() antes de invocar.
 *
 * @package Welow\RRHH\Exporters
 */

declare( strict_types=1 );

namespace Welow\RRHH\Exporters;

defined( 'ABSPATH' ) || exit;

/**
 * PdfDriver.
 */
final class PdfDriver implements ExporterDriverInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'pdf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function content_type(): string {
		return 'application/pdf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function file_extension(): string {
		return 'pdf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return class_exists( '\\Dompdf\\Dompdf' );
	}

	/**
	 * Renderiza un PDF a partir de una tabla HTML simple.
	 *
	 * @param string[] $headers Cabecera.
	 * @param iterable $rows    Filas.
	 * @param string   $title   Título.
	 * @return string Bytes PDF.
	 *
	 * @throws \RuntimeException Si dompdf no está instalado.
	 */
	public function render( array $headers, iterable $rows, string $title ): string {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException(
				'PdfDriver requiere dompdf/dompdf. Instálalo con: composer require dompdf/dompdf'
			);
		}

		$html = $this->build_html( $headers, $rows, $title );

		$class = '\\Dompdf\\Dompdf';
		// La clase existe; instanciamos por reflexión para evitar autoload statíco si la lib no estuviera.
		$dompdf = new $class();
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'landscape' );
		$dompdf->render();
		return (string) $dompdf->output();
	}

	/**
	 * Construye un HTML simple para volcarlo a PDF.
	 *
	 * @param string[] $headers Cabecera.
	 * @param iterable $rows    Filas.
	 * @param string   $title   Título.
	 * @return string
	 */
	private function build_html( array $headers, iterable $rows, string $title ): string {
		$out  = '<!doctype html><html><head><meta charset="UTF-8"><style>';
		$out .= 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;}';
		$out .= 'h1{font-size:16px;margin:0 0 12px;}';
		$out .= 'table{border-collapse:collapse;width:100%;}';
		$out .= 'th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;}';
		$out .= 'th{background:#f5f5f5;}';
		$out .= '</style></head><body>';
		$out .= '<h1>' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . '</h1>';
		$out .= '<table><thead><tr>';
		foreach ( $headers as $h ) {
			$out .= '<th>' . htmlspecialchars( (string) $h, ENT_QUOTES, 'UTF-8' ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr>';
			foreach ( (array) $row as $cell ) {
				$out .= '<td>' . htmlspecialchars( (string) $cell, ENT_QUOTES, 'UTF-8' ) . '</td>';
			}
			$out .= '</tr>';
		}
		$out .= '</tbody></table></body></html>';
		return $out;
	}
}
