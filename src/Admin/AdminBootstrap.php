<?php
/**
 * Punto de entrada del backend (wp-admin) de Welow RRHH.
 *
 * Registra el menú principal "Welow RRHH" y los handlers asociados a la
 * pantalla de empleados (POST de form, acciones GET delete/terminate,
 * avisos y CSS admin).
 *
 * Se instancia en Plugin::boot() sólo si is_admin().
 *
 * @package Welow\RRHH\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Admin;

use Welow\RRHH\Container;
use Welow\RRHH\Roles\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Inicializa los hooks del backend.
 */
final class AdminBootstrap {

	/**
	 * Prefijo común para slugs de páginas del plugin (filtro de assets).
	 */
	public const SLUG_PREFIX   = 'welow-rrhh';
	public const MENU_ICON     = 'dashicons-businessman';
	public const MENU_POSITION = 65;

	/**
	 * Contenedor de servicios (para lazy-load de EmployeesScreen).
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Pantalla de empleados (resuelta perezosamente).
	 *
	 * @var EmployeesScreen|null
	 */
	private ?EmployeesScreen $employees_screen = null;

	/**
	 * Pantalla de import CSV (resuelta perezosamente).
	 *
	 * @var EmployeesImportScreen|null
	 */
	private ?EmployeesImportScreen $import_screen = null;

	/**
	 * Pantalla de departamentos (resuelta perezosamente).
	 *
	 * @var DepartmentsScreen|null
	 */
	private ?DepartmentsScreen $departments_screen = null;

	/**
	 * Pantalla de festivos (resuelta perezosamente).
	 *
	 * @var HolidaysScreen|null
	 */
	private ?HolidaysScreen $holidays_screen = null;

	/**
	 * Pantalla de import CSV de festivos (resuelta perezosamente).
	 *
	 * @var HolidaysImportScreen|null
	 */
	private ?HolidaysImportScreen $holidays_import_screen = null;

	/**
	 * Constructor.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Engancha todos los hooks de admin.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . EmployeesScreen::SAVE_ACTION, array( $this, 'handle_post_save' ) );
		add_action( 'admin_post_' . EmployeesImportScreen::UPLOAD_ACTION, array( $this, 'handle_csv_upload' ) );
		add_action( 'admin_post_' . EmployeesImportScreen::CONFIRM_ACTION, array( $this, 'handle_csv_confirm' ) );
		add_action( 'admin_post_' . DepartmentsScreen::SAVE_ACTION, array( $this, 'handle_department_save' ) );
		add_action( 'admin_post_' . HolidaysScreen::SAVE_ACTION, array( $this, 'handle_holiday_save' ) );
		add_action( 'admin_post_' . HolidaysImportScreen::UPLOAD_ACTION, array( $this, 'handle_holidays_csv_upload' ) );
		add_action( 'admin_post_' . HolidaysImportScreen::CONFIRM_ACTION, array( $this, 'handle_holidays_csv_confirm' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registra el menú principal + submenús.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// add_menu_page crea automáticamente un primer subitem con el mismo slug,
		// que luego renombramos a "Empleados". Los futuros submenús (Departamentos,
		// Festivos, Ajustes…) se añadirán con add_submenu_page usando este parent.
		$hook_suffix = add_menu_page(
			__( 'Welow RRHH', 'welow-rrhh' ),
			__( 'Welow RRHH', 'welow-rrhh' ),
			Capabilities::CAP_MANAGE_EMPLOYEES,
			EmployeesScreen::PAGE_SLUG,
			array( $this->employees_screen(), 'render' ),
			self::MENU_ICON,
			self::MENU_POSITION
		);

		// Renombrar el primer subitem (que por defecto hereda el label del top-level).
		global $submenu;
		if ( isset( $submenu[ EmployeesScreen::PAGE_SLUG ][0] ) ) {
			$submenu[ EmployeesScreen::PAGE_SLUG ][0][0] = __( 'Empleados', 'welow-rrhh' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this->employees_screen(), 'handle_actions' ) );
			add_action( 'admin_notices', array( $this->employees_screen(), 'render_notices' ) );
		}

		$import_hook = add_submenu_page(
			EmployeesScreen::PAGE_SLUG,
			__( 'Importar empleados', 'welow-rrhh' ),
			__( 'Importar', 'welow-rrhh' ),
			Capabilities::CAP_MANAGE_EMPLOYEES,
			EmployeesImportScreen::PAGE_SLUG,
			array( $this->import_screen(), 'render' )
		);

		if ( $import_hook ) {
			add_action( 'load-' . $import_hook, array( $this->import_screen(), 'handle_actions' ) );
		}

		$departments_hook = add_submenu_page(
			EmployeesScreen::PAGE_SLUG,
			__( 'Departamentos', 'welow-rrhh' ),
			__( 'Departamentos', 'welow-rrhh' ),
			Capabilities::CAP_MANAGE_EMPLOYEES,
			DepartmentsScreen::PAGE_SLUG,
			array( $this->departments_screen(), 'render' )
		);

		if ( $departments_hook ) {
			add_action( 'load-' . $departments_hook, array( $this->departments_screen(), 'handle_actions' ) );
			add_action( 'admin_notices', array( $this->departments_screen(), 'render_notices' ) );
		}

		$holidays_hook = add_submenu_page(
			EmployeesScreen::PAGE_SLUG,
			__( 'Festivos', 'welow-rrhh' ),
			__( 'Festivos', 'welow-rrhh' ),
			Capabilities::CAP_MANAGE_HOLIDAYS,
			HolidaysScreen::PAGE_SLUG,
			array( $this->holidays_screen(), 'render' )
		);
		if ( $holidays_hook ) {
			add_action( 'load-' . $holidays_hook, array( $this->holidays_screen(), 'handle_actions' ) );
			add_action( 'admin_notices', array( $this->holidays_screen(), 'render_notices' ) );
		}

		$holidays_import_hook = add_submenu_page(
			EmployeesScreen::PAGE_SLUG,
			__( 'Importar festivos', 'welow-rrhh' ),
			__( 'Importar festivos', 'welow-rrhh' ),
			Capabilities::CAP_MANAGE_HOLIDAYS,
			HolidaysImportScreen::PAGE_SLUG,
			array( $this->holidays_import_screen(), 'render' )
		);
		if ( $holidays_import_hook ) {
			add_action( 'load-' . $holidays_import_hook, array( $this->holidays_import_screen(), 'handle_actions' ) );
		}
	}

	/**
	 * Handler del form POST (delegado).
	 *
	 * @return void
	 */
	public function handle_post_save(): void {
		$this->employees_screen()->handle_post_save();
	}

	/**
	 * Handler del POST de upload CSV (delegado).
	 *
	 * @return void
	 */
	public function handle_csv_upload(): void {
		$this->import_screen()->handle_upload();
	}

	/**
	 * Handler del POST de confirmación del import (delegado).
	 *
	 * @return void
	 */
	public function handle_csv_confirm(): void {
		$this->import_screen()->handle_confirm();
	}

	/**
	 * Handler del POST de save de departamento (delegado).
	 *
	 * @return void
	 */
	public function handle_department_save(): void {
		$this->departments_screen()->handle_post_save();
	}

	/**
	 * Handler del POST de save de festivo (delegado).
	 *
	 * @return void
	 */
	public function handle_holiday_save(): void {
		$this->holidays_screen()->handle_post_save();
	}

	/**
	 * Handler upload CSV festivos (delegado).
	 *
	 * @return void
	 */
	public function handle_holidays_csv_upload(): void {
		$this->holidays_import_screen()->handle_upload();
	}

	/**
	 * Handler confirm CSV festivos (delegado).
	 *
	 * @return void
	 */
	public function handle_holidays_csv_confirm(): void {
		$this->holidays_import_screen()->handle_confirm();
	}

	/**
	 * Encola CSS admin sólo en las páginas del plugin.
	 *
	 * @param string $hook_suffix Suffix del current screen.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// El hook_suffix de las páginas del plugin contiene siempre SLUG_PREFIX.
		if ( false === strpos( $hook_suffix, self::SLUG_PREFIX ) ) {
			return;
		}
		wp_enqueue_style(
			'welow-rrhh-admin',
			WELOW_RRHH_PLUGIN_URL . 'assets/css/welow-rrhh-admin.css',
			array(),
			WELOW_RRHH_VERSION
		);
	}

	/**
	 * Resolución perezosa de la pantalla de empleados.
	 *
	 * @return EmployeesScreen
	 */
	private function employees_screen(): EmployeesScreen {
		if ( null === $this->employees_screen ) {
			$this->employees_screen = new EmployeesScreen(
				$this->container->get( 'employees.service' ),
				$this->container->get( 'departments.repository' )
			);
		}
		return $this->employees_screen;
	}

	/**
	 * Resolución perezosa de la pantalla de import CSV.
	 *
	 * @return EmployeesImportScreen
	 */
	private function import_screen(): EmployeesImportScreen {
		if ( null === $this->import_screen ) {
			$this->import_screen = new EmployeesImportScreen( $this->container->get( 'employees.importer' ) );
		}
		return $this->import_screen;
	}

	/**
	 * Resolución perezosa de la pantalla de departamentos.
	 *
	 * @return DepartmentsScreen
	 */
	private function departments_screen(): DepartmentsScreen {
		if ( null === $this->departments_screen ) {
			$this->departments_screen = new DepartmentsScreen( $this->container->get( 'departments.service' ) );
		}
		return $this->departments_screen;
	}

	/**
	 * Resolución perezosa de la pantalla de festivos.
	 *
	 * @return HolidaysScreen
	 */
	private function holidays_screen(): HolidaysScreen {
		if ( null === $this->holidays_screen ) {
			$this->holidays_screen = new HolidaysScreen( $this->container->get( 'holidays.service' ) );
		}
		return $this->holidays_screen;
	}

	/**
	 * Resolución perezosa de la pantalla de import CSV de festivos.
	 *
	 * @return HolidaysImportScreen
	 */
	private function holidays_import_screen(): HolidaysImportScreen {
		if ( null === $this->holidays_import_screen ) {
			$this->holidays_import_screen = new HolidaysImportScreen( $this->container->get( 'holidays.importer' ) );
		}
		return $this->holidays_import_screen;
	}
}
