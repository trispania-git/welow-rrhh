<?php
/**
 * Bootstrap admin del módulo Fichajes.
 *
 * Registra submenús bajo "Welow RRHH":
 *   - Fichajes (lista + edición; cap view_team o view_all).
 *   - Cierre de mes (cap close_time_periods).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Admin
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Admin;

use Welow\RRHH\Admin\EmployeesScreen;
use Welow\RRHH\Container;
use Welow\RRHH\Modules\TimeTracking\TimeTrackingCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Admin bootstrap del módulo.
 */
final class AdminBootstrap {

	/**
	 * Container del plugin.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Pantalla de fichajes (lazy).
	 *
	 * @var TimeEntriesScreen|null
	 */
	private ?TimeEntriesScreen $entries_screen = null;

	/**
	 * Pantalla de cierre de mes (lazy).
	 *
	 * @var MonthClosureScreen|null
	 */
	private ?MonthClosureScreen $closure_screen = null;

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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_' . TimeEntriesScreen::SAVE_ACTION, array( $this, 'handle_entry_save' ) );
		add_action( 'admin_post_' . MonthClosureScreen::SAVE_ACTION, array( $this, 'handle_closure_save' ) );
	}

	/**
	 * Registra submenús.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$entries_hook = add_submenu_page(
			EmployeesScreen::PAGE_SLUG,
			__( 'Fichajes', 'welow-rrhh' ),
			__( 'Fichajes', 'welow-rrhh' ),
			TimeTrackingCapabilities::VIEW_TEAM,
			TimeEntriesScreen::PAGE_SLUG,
			array( $this->entries_screen(), 'render' )
		);
		if ( $entries_hook ) {
			add_action( 'admin_notices', array( $this->entries_screen(), 'render_notices' ) );
		}

		add_submenu_page(
			EmployeesScreen::PAGE_SLUG,
			__( 'Cierre de mes', 'welow-rrhh' ),
			__( 'Cierre de mes', 'welow-rrhh' ),
			TimeTrackingCapabilities::CLOSE_PERIOD,
			MonthClosureScreen::PAGE_SLUG,
			array( $this->closure_screen(), 'render' )
		);
		add_action( 'admin_notices', array( $this->closure_screen(), 'render_notices' ) );
	}

	/**
	 * Handler POST de edición de fichaje (delegado).
	 *
	 * @return void
	 */
	public function handle_entry_save(): void {
		$this->entries_screen()->handle_post_save();
	}

	/**
	 * Handler POST de acciones de cierre de mes (delegado).
	 *
	 * @return void
	 */
	public function handle_closure_save(): void {
		$this->closure_screen()->handle_post_save();
	}

	/**
	 * Pantalla de fichajes (lazy).
	 *
	 * @return TimeEntriesScreen
	 */
	private function entries_screen(): TimeEntriesScreen {
		if ( null === $this->entries_screen ) {
			$this->entries_screen = new TimeEntriesScreen(
				$this->container->get( 'time_tracking.service' ),
				$this->container->get( 'employees.repository' ),
				$this->container->get( 'time_tracking.month_closure' ),
				$this->container->get( 'time_tracking.monthly_report' )
			);
		}
		return $this->entries_screen;
	}

	/**
	 * Pantalla de cierre (lazy).
	 *
	 * @return MonthClosureScreen
	 */
	private function closure_screen(): MonthClosureScreen {
		if ( null === $this->closure_screen ) {
			$this->closure_screen = new MonthClosureScreen(
				$this->container->get( 'time_tracking.month_closure' )
			);
		}
		return $this->closure_screen;
	}
}
