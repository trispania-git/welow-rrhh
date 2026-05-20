<?php
/**
 * Base para tests de integración con WP_UnitTestCase.
 *
 * Activa los módulos del plugin antes de cada test y proporciona helpers
 * para crear empleados, festivos y resetear el estado entre tests.
 *
 * @package Welow\RRHH\Tests\Integration
 */

declare( strict_types=1 );

namespace Welow\RRHH\Tests\Integration;

use Welow\RRHH\Container;
use Welow\RRHH\Modules\Vacations\Config\VacationYearsConfig;

abstract class IntegrationTestCase extends \WP_UnitTestCase {

	/**
	 * Container del plugin.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * Set up: garantiza que los dos módulos están activos y arrancados.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$plugin          = welow_rrhh();
		$this->container = $plugin->container();

		$registry = $this->container->get( 'modules' );
		foreach ( array( 'time-tracking', 'vacations' ) as $slug ) {
			if ( ! $registry->is_active( $slug ) ) {
				$res = $registry->activate( $slug );
				if ( is_wp_error( $res ) ) {
					$this->fail( "No se pudo activar el módulo {$slug}: " . $res->get_error_message() );
				}
			}
		}
		// Re-arranca para que boot() registre todos los servicios en el container.
		$registry->boot_active();

		// Limpieza de datos del módulo Vacaciones entre tests.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}welow_vacation_requests" );  // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->prefix}welow_vacation_balances" );  // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->prefix}welow_time_entries" );       // phpcs:ignore WordPress.DB
		delete_option( VacationYearsConfig::OPTION_KEY );
		delete_option( 'welow_rrhh_time_tracking_closed_months' );
	}

	/**
	 * Crea un WP_User administrador y devuelve su id.
	 *
	 * @return int
	 */
	protected function make_admin(): int {
		return (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Crea un WP_User con rol welow_employee (o el indicado).
	 *
	 * @param string $role Rol.
	 * @return int
	 */
	protected function make_user( string $role = 'welow_employee' ): int {
		return (int) self::factory()->user->create( array( 'role' => $role ) );
	}
}
