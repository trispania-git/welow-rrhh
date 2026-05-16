<?php
/**
 * Módulo Fichajes (§7 de la especificación).
 *
 * Implementa el control horario completo conforme al Real Decreto-ley
 * 8/2019: registro de eventos (entrada/salida/pausas), edición auditada,
 * cierre de mes, exportación PDF mensual y restricciones geo/IP.
 *
 * En este hito (9.A) sólo arrancamos el ciclo de vida del módulo y
 * registramos los servicios de dominio (repository + service) en el
 * contenedor del plugin. Los siguientes hitos añaden política geo/IP,
 * REST, admin/frontend UI, cierre de mes y exportación PDF.
 *
 * @package Welow\RRHH\Modules\TimeTracking
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking;

use Welow\RRHH\Container;
use Welow\RRHH\Modules\AbstractModule;
use Welow\RRHH\Modules\TimeTracking\Repository\TimeEntryRepository;
use Welow\RRHH\Modules\TimeTracking\Schema\TimeTrackingSchema;
use Welow\RRHH\Modules\TimeTracking\Service\TimeEntryService;

defined( 'ABSPATH' ) || exit;

/**
 * Módulo Fichajes.
 */
final class Module extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'time-tracking';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Fichajes', 'welow-rrhh' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __(
			'Control horario conforme al RDL 8/2019: registro de eventos, restricciones geo/IP, cierre de mes y exportación PDF mensual.',
			'welow-rrhh'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return '0.1.0';
	}

	/**
	 * Capabilities introducidas por este módulo (lista plana — el mapeo
	 * a roles está en TimeTrackingCapabilities::role_map).
	 *
	 * @return array<string, string[]>|string[]
	 */
	public function capabilities(): array {
		return TimeTrackingCapabilities::all();
	}

	/**
	 * {@inheritDoc}
	 */
	public function activate(): void {
		TimeTrackingSchema::install();
		TimeTrackingCapabilities::install();
	}

	/**
	 * {@inheritDoc}
	 */
	public function migrate(): void {
		$installed = (string) get_option( TimeTrackingSchema::OPTION_DB_VERSION, '' );
		if ( TimeTrackingSchema::VERSION !== $installed ) {
			TimeTrackingSchema::install();
		}
		// Garantiza que los roles tengan las caps incluso si se añadieron tras la activación.
		TimeTrackingCapabilities::install();
	}

	/**
	 * Registra servicios del módulo en el contenedor del plugin y los
	 * hooks de runtime (los hooks específicos llegarán en 9.B / 9.C /
	 * 9.D / 9.E).
	 *
	 * @return void
	 */
	public function boot(): void {
		$container = welow_rrhh()->container();

		$container->set(
			'time_tracking.repository',
			static function (): TimeEntryRepository {
				global $wpdb;
				return new TimeEntryRepository( $wpdb );
			}
		);

		$container->set(
			'time_tracking.service',
			static function ( Container $c ): TimeEntryService {
				return new TimeEntryService(
					$c->get( 'time_tracking.repository' ),
					$c->get( 'audit.logger' )
				);
			}
		);

		/**
		 * Disparado cuando el módulo Fichajes ha terminado de arrancar.
		 *
		 * @since 0.1.0
		 */
		do_action( 'welow_rrhh/time_tracking/booted' );
	}
}
