<?php
/**
 * Implementación base reutilizable para módulos de Welow RRHH.
 *
 * @package Welow\RRHH\Modules
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Las subclases deben implementar al menos slug(), name(), description(),
 * version() y boot(). El resto del ciclo de vida tiene defaults seguros.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Ruta absoluta a la raíz del módulo (modules/<slug>/).
	 *
	 * @var string
	 */
	protected string $path;

	/**
	 * URL pública a la raíz del módulo.
	 *
	 * @var string
	 */
	protected string $url;

	/**
	 * Constructor.
	 *
	 * @param string $path Ruta absoluta al directorio del módulo.
	 * @param string $url  URL pública del módulo.
	 */
	public function __construct( string $path, string $url ) {
		$this->path = trailingslashit( $path );
		$this->url  = trailingslashit( $url );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function activate(): void {
		// Override en subclases si se requiere lógica de activación.
	}

	/**
	 * {@inheritDoc}
	 */
	public function deactivate(): void {
		// Override en subclases si se requiere lógica de desactivación.
	}

	/**
	 * {@inheritDoc}
	 */
	public function migrate(): void {
		// Override en subclases si se requiere lógica de migración.
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array();
	}

	/**
	 * Ruta absoluta a la raíz del módulo.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * URL pública del módulo.
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->url;
	}
}
