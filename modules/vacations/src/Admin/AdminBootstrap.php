<?php
/**
 * Bootstrap admin del módulo Vacaciones.
 *
 * Registra dos submenús bajo "Welow RRHH":
 *   - Vacaciones (lista + decisión; cap view_team).
 *   - Años de vacaciones (configuración; cap CONFIGURE).
 *
 * @package Welow\RRHH\Modules\Vacations\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Admin;

use Welow\RRHH\Admin\EmployeesScreen;
use Welow\RRHH\Container;
use Welow\RRHH\Modules\Vacations\VacationsCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Admin bootstrap.
 */
final class AdminBootstrap {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Pantalla vacaciones (lazy).
	 *
	 * @var VacationsScreen|null
	 */
	private ?VacationsScreen $vacations_screen = null;

	/**
	 * Pantalla años (lazy).
	 *
	 * @var VacationYearsScreen|null
	 */
	private ?VacationYearsScreen $years_screen = null;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Engancha hooks admin.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 25 );
		add_action( 'admin_post_' . VacationsScreen::SAVE_ACTION, array( $this, 'handle_vacations_save' ) );
		add_action( 'admin_post_' . VacationYearsScreen::SAVE_ACTION, array( $this, 'handle_years_save' ) );
	}

	/**
	 * Registra submenús.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			EmployeesScreen::PAGE_SLUG,
			__( 'Vacaciones', 'welow-rrhh' ),
			__( 'Vacaciones', 'welow-rrhh' ),
			VacationsCapabilities::VIEW_TEAM,
			VacationsScreen::PAGE_SLUG,
			array( $this->vacations_screen(), 'render' )
		);
		if ( $hook ) {
			add_action( 'admin_notices', array( $this->vacations_screen(), 'render_notices' ) );
		}

		add_submenu_page(
			EmployeesScreen::PAGE_SLUG,
			__( 'Años de vacaciones', 'welow-rrhh' ),
			__( 'Años de vacaciones', 'welow-rrhh' ),
			VacationsCapabilities::CONFIGURE,
			VacationYearsScreen::PAGE_SLUG,
			array( $this->years_screen(), 'render' )
		);
		add_action( 'admin_notices', array( $this->years_screen(), 'render_notices' ) );
	}

	/**
	 * Handler POST decisión.
	 *
	 * @return void
	 */
	public function handle_vacations_save(): void {
		$this->vacations_screen()->handle_post_save();
	}

	/**
	 * Handler POST config años.
	 *
	 * @return void
	 */
	public function handle_years_save(): void {
		$this->years_screen()->handle_post_save();
	}

	/**
	 * Lazy.
	 *
	 * @return VacationsScreen
	 */
	private function vacations_screen(): VacationsScreen {
		if ( null === $this->vacations_screen ) {
			$this->vacations_screen = new VacationsScreen(
				$this->container->get( 'vacations.request_service' ),
				$this->container->get( 'vacations.approval_service' ),
				$this->container->get( 'vacations.balance_calculator' ),
				$this->container->get( 'employees.repository' )
			);
		}
		return $this->vacations_screen;
	}

	/**
	 * Lazy.
	 *
	 * @return VacationYearsScreen
	 */
	private function years_screen(): VacationYearsScreen {
		if ( null === $this->years_screen ) {
			$this->years_screen = new VacationYearsScreen(
				$this->container->get( 'vacations.years_config' )
			);
		}
		return $this->years_screen;
	}
}
