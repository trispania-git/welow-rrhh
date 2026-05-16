<?php
/**
 * Orquestador del subsistema de exportación (§6.6).
 *
 * Mantiene registro de drivers (CSV, PDF) y sources (Empleados, Festivos,
 * Fichajes, …) y aplica filtros para que los módulos añadan los suyos:
 *
 *   - welow_rrhh/exporters/drivers
 *   - welow_rrhh/exporters/sources
 *
 * @package Welow\RRHH\Exporters
 */

declare( strict_types=1 );

namespace Welow\RRHH\Exporters;

use Welow\RRHH\Audit\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Exporter.
 */
final class Exporter {

	/**
	 * Drivers registrados por slug.
	 *
	 * @var array<string, ExporterDriverInterface>
	 */
	private array $drivers = array();

	/**
	 * Sources registradas por slug.
	 *
	 * @var array<string, ExportSourceInterface>
	 */
	private array $sources = array();

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param ExporterDriverInterface[] $drivers Drivers a registrar.
	 * @param ExportSourceInterface[]   $sources Sources a registrar.
	 * @param AuditLogger               $audit   Audit logger.
	 */
	public function __construct( array $drivers, array $sources, AuditLogger $audit ) {
		foreach ( $drivers as $driver ) {
			$this->register_driver( $driver );
		}
		foreach ( $sources as $source ) {
			$this->register_source( $source );
		}
		$this->audit = $audit;
	}

	/**
	 * Registra un driver.
	 *
	 * @param ExporterDriverInterface $driver Driver.
	 * @return void
	 */
	public function register_driver( ExporterDriverInterface $driver ): void {
		$this->drivers[ $driver->slug() ] = $driver;
	}

	/**
	 * Registra una source.
	 *
	 * @param ExportSourceInterface $source Source.
	 * @return void
	 */
	public function register_source( ExportSourceInterface $source ): void {
		$this->sources[ $source->slug() ] = $source;
	}

	/**
	 * Devuelve las sources disponibles tras aplicar el filtro de extensión.
	 *
	 * @return array<string, ExportSourceInterface>
	 */
	public function sources(): array {
		/**
		 * Permite a los módulos registrar sources adicionales.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, ExportSourceInterface> $sources Sources actuales.
		 */
		$filtered = apply_filters( 'welow_rrhh/exporters/sources', $this->sources );
		return self::filter_instances( $filtered, ExportSourceInterface::class );
	}

	/**
	 * Devuelve los drivers disponibles tras aplicar el filtro de extensión.
	 *
	 * @return array<string, ExporterDriverInterface>
	 */
	public function drivers(): array {
		/**
		 * Permite a los módulos registrar drivers adicionales.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, ExporterDriverInterface> $drivers Drivers actuales.
		 */
		$filtered = apply_filters( 'welow_rrhh/exporters/drivers', $this->drivers );
		return self::filter_instances( $filtered, ExporterDriverInterface::class );
	}

	/**
	 * Ejecuta una exportación.
	 *
	 * @param string        $source_slug Slug de la source.
	 * @param string        $format      Slug del driver (csv|pdf|…).
	 * @param \WP_User|null $user        Usuario que solicita (default: current_user).
	 * @return array{content:string, filename:string, content_type:string}|\WP_Error
	 */
	public function export( string $source_slug, string $format, ?\WP_User $user = null ) {
		if ( null === $user ) {
			$user_id = get_current_user_id();
			$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		}
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'welow_export_forbidden', __( 'Se requiere usuario autenticado.', 'welow-rrhh' ) );
		}

		$sources = $this->sources();
		$drivers = $this->drivers();

		$source = $sources[ $source_slug ] ?? null;
		if ( null === $source ) {
			return new \WP_Error( 'welow_export_source_not_found', __( 'Fuente de exportación no registrada.', 'welow-rrhh' ) );
		}

		if ( ! $source->can_export( $user ) ) {
			return new \WP_Error( 'welow_export_forbidden', __( 'No tienes permisos para exportar esta fuente.', 'welow-rrhh' ) );
		}

		$driver = $drivers[ $format ] ?? null;
		if ( null === $driver ) {
			return new \WP_Error( 'welow_export_driver_not_found', __( 'Formato de exportación no disponible.', 'welow-rrhh' ) );
		}

		if ( ! $driver->is_available() ) {
			// Fallback automático a CSV (§6.6).
			$driver = $drivers['csv'] ?? null;
			if ( null === $driver ) {
				return new \WP_Error( 'welow_export_driver_unavailable', __( 'El driver solicitado no está disponible y no hay fallback a CSV.', 'welow-rrhh' ) );
			}
		}

		try {
			$content = $driver->render( $source->headers(), $source->rows(), $source->name() );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_export_failed', $e->getMessage() );
		}

		$filename = sprintf(
			'welow-rrhh-%s-%s.%s',
			$source->slug(),
			gmdate( 'Ymd-His' ),
			$driver->file_extension()
		);

		$this->audit->log(
			'export',
			$source->slug(),
			null,
			array(
				'format'   => $driver->slug(),
				'filename' => $filename,
				'user_id'  => $user->ID,
			)
		);

		return array(
			'content'      => $content,
			'filename'     => $filename,
			'content_type' => $driver->content_type(),
		);
	}

	/**
	 * Filtra el array dejando sólo instancias del tipo esperado, indexadas por slug.
	 *
	 * @param mixed  $arr  Valor a filtrar.
	 * @param string $type Nombre de la clase/interface a filtrar.
	 * @return array<string, object>
	 */
	private static function filter_instances( $arr, string $type ): array {
		$out = array();
		if ( ! is_array( $arr ) ) {
			return $out;
		}
		foreach ( $arr as $item ) {
			if ( $item instanceof $type ) {
				$out[ $item->slug() ] = $item;
			}
		}
		return $out;
	}
}
