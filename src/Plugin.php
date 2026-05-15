<?php
/**
 * Singleton de bootstrap del plugin Welow RRHH.
 *
 * Arranca el contenedor de servicios mínimo, ejecuta migraciones si el schema
 * está desfasado, registra el ModuleRegistry, descubre los módulos disponibles
 * en /modules/ y, en función de la opción `welow_rrhh_active_modules`, llama
 * a `boot()` solo en los activos.
 *
 * @package Welow\RRHH
 */

declare( strict_types=1 );

namespace Welow\RRHH;

use Welow\RRHH\Admin\AdminBootstrap;
use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Audit\AuditRepository;
use Welow\RRHH\Database\Migrator;
use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Employees\EmployeeService;
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
		$this->run_migrations();
		$this->load_textdomain();
		$this->boot_admin();
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

		$this->container->set(
			'migrator',
			static function (): Migrator {
				return new Migrator();
			}
		);

		$this->container->set(
			'audit.repository',
			static function (): AuditRepository {
				global $wpdb;
				return new AuditRepository( $wpdb );
			}
		);

		$this->container->set(
			'audit.logger',
			static function ( Container $c ): AuditLogger {
				return new AuditLogger( $c->get( 'audit.repository' ) );
			}
		);

		$this->container->set(
			'employees.repository',
			static function (): EmployeeRepository {
				global $wpdb;
				return new EmployeeRepository( $wpdb );
			}
		);

		$this->container->set(
			'employees.service',
			static function ( Container $c ): EmployeeService {
				return new EmployeeService(
					$c->get( 'employees.repository' ),
					$c->get( 'audit.logger' )
				);
			}
		);
	}

	/**
	 * Ejecuta migraciones del schema Core si la versión instalada está desfasada.
	 *
	 * Idempotente: si la versión coincide no se ejecuta nada.
	 *
	 * @return void
	 */
	private function run_migrations(): void {
		$migrator = $this->container->get( 'migrator' );
		$migrator->run_if_needed();
	}

	/**
	 * Carga la traducción del plugin.
	 *
	 * Los módulos cargan sus propios .mo desde /modules/<slug>/languages/
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
	 * Inicializa el backend (wp-admin) si estamos en ese contexto.
	 *
	 * @return void
	 */
	private function boot_admin(): void {
		if ( ! is_admin() ) {
			return;
		}
		$bootstrap = new AdminBootstrap( $this->container );
		$bootstrap->register_hooks();
	}

	/**
	 * Descubre los módulos en /modules/ y arranca los marcados como activos.
	 *
	 * @return void
	 */
	private function boot_modules(): void {
		$registry = $this->container->get( 'modules' );
		$registry->discover();
		$registry->boot_active();
	}
}
