<?php
/**
 * Singleton de bootstrap del plugin Welow RRHH.
 *
 * Arranca el contenedor de servicios mínimo, registra el ModuleRegistry,
 * descubre los módulos disponibles en /modules/ y, en función de la opción
 * `welow_rrhh_active_modules`, llama a `boot()` solo en los activos.
 *
 * El Core (no implementado todavía en este scaffold) se registrará aquí
 * como un servicio más en futuras iteraciones.
 *
 * @package Welow\RRHH
 */

declare( strict_types=1 );

namespace Welow\RRHH;

use Welow\RRHH\Modules\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Plugin (singleton).
 */
final class Plugin {

	/**
	 * Instancia única.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Contenedor de servicios.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Flag de arranque para garantizar idempotencia de boot().
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor privado: singleton.
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * Evita clonar la instancia.
	 */
	private function __clone() {}

	/**
	 * Devuelve la instancia única del plugin.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Acceso al contenedor de servicios.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Arranque del plugin. Idempotente.
	 *
	 * Engancha en `plugins_loaded` (desde el archivo raíz). En este punto
	 * WordPress ya tiene cargados todos los plugins activos y la API está
	 * disponible.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->register_core_services();
		$this->load_textdomain();
		$this->boot_modules();

		$this->booted = true;

		/**
		 * Se dispara cuando el plugin ha terminado de arrancar.
		 *
		 * Los integradores pueden usar este hook para registrar comportamientos
		 * que dependan de que Welow RRHH esté completamente inicializado.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin Instancia del plugin.
		 */
		do_action( 'welow_rrhh/booted', $this );
	}

	/**
	 * Registra los servicios mínimos del núcleo en el contenedor.
	 *
	 * @return void
	 */
	private function register_core_services(): void {
		$this->container->set(
			'modules',
			static function (): ModuleRegistry {
				return new ModuleRegistry( WELOW_RRHH_PLUGIN_DIR . 'modules' );
			}
		);
	}

	/**
	 * Carga la traducción del plugin.
	 *
	 * Los módulos cargarán sus propios .mo desde /modules/<slug>/languages/
	 * en su método boot().
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'welow-rrhh',
			false,
			dirname( WELOW_RRHH_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Descubre los módulos en /modules/ y arranca los marcados como activos.
	 *
	 * @return void
	 */
	private function boot_modules(): void {
		/** @var ModuleRegistry $registry */
		$registry = $this->container->get( 'modules' );
		$registry->discover();
		$registry->boot_active();
	}
}
