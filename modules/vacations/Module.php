<?php
/**
 * Módulo Vacaciones (§8 de la especificación).
 *
 * Saldo anual + solicitudes con flujo de aprobación + carry-over con
 * caducidad configurable año a año. Esta clase actúa como bootstrap del
 * módulo: registra servicios en el contenedor del Core, instala/migra
 * tablas propias y engancha integraciones con dashboard/REST/admin a
 * medida que se incorporan en hitos siguientes (10.B–F).
 *
 * @package Welow\RRHH\Modules\Vacations
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations;

use Welow\RRHH\Container;
use Welow\RRHH\Modules\AbstractModule;
use Welow\RRHH\Modules\Vacations\Config\VacationYearsConfig;
use Welow\RRHH\Modules\Vacations\Repository\VacationBalanceRepository;
use Welow\RRHH\Modules\Vacations\Repository\VacationRequestRepository;
use Welow\RRHH\Modules\Vacations\Schema\VacationsSchema;
use Welow\RRHH\Modules\Vacations\Notifications\VacationNotifications;
use Welow\RRHH\Modules\Vacations\Service\ApprovalService;
use Welow\RRHH\Modules\Vacations\Service\BalanceCalculator;
use Welow\RRHH\Modules\Vacations\Service\RequestService;

defined( 'ABSPATH' ) || exit;

/**
 * Módulo Vacaciones.
 */
final class Module extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'vacations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Vacaciones', 'welow-rrhh' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __(
			'Gestión de vacaciones con saldo anual, solicitudes, flujo de aprobación y carry-over con caducidad configurable año a año.',
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
	 * Lista de capabilities introducidas por el módulo (§5).
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return VacationsCapabilities::all();
	}

	/**
	 * {@inheritDoc}
	 */
	public function activate(): void {
		VacationsSchema::install();
		VacationsCapabilities::install();
	}

	/**
	 * {@inheritDoc}
	 */
	public function migrate(): void {
		$installed = (string) get_option( VacationsSchema::OPTION_DB_VERSION, '' );
		if ( VacationsSchema::VERSION !== $installed ) {
			VacationsSchema::install();
		}
		// Reasegura caps si se han añadido entre versiones.
		VacationsCapabilities::install();
	}

	/**
	 * Registra servicios y hooks runtime.
	 *
	 * En 10.A sólo registramos repositorios + config en el contenedor; los
	 * services / guards / controllers se añaden en 10.B–D.
	 *
	 * @return void
	 */
	public function boot(): void {
		$container = welow_rrhh()->container();

		$container->set(
			'vacations.request_repository',
			static function (): VacationRequestRepository {
				global $wpdb;
				return new VacationRequestRepository( $wpdb );
			}
		);

		$container->set(
			'vacations.balance_repository',
			static function (): VacationBalanceRepository {
				global $wpdb;
				return new VacationBalanceRepository( $wpdb );
			}
		);

		$container->set(
			'vacations.years_config',
			static function (): VacationYearsConfig {
				return new VacationYearsConfig();
			}
		);

		$container->set(
			'vacations.balance_calculator',
			static function ( Container $c ): BalanceCalculator {
				return new BalanceCalculator(
					$c->get( 'vacations.request_repository' ),
					$c->get( 'vacations.balance_repository' ),
					$c->get( 'employees.repository' ),
					$c->get( 'holidays.repository' ),
					$c->get( 'vacations.years_config' ),
					$c->get( 'settings.company' )
				);
			}
		);

		$container->set(
			'vacations.request_service',
			static function ( Container $c ): RequestService {
				return new RequestService(
					$c->get( 'vacations.request_repository' ),
					$c->get( 'vacations.balance_calculator' ),
					$c->get( 'vacations.years_config' ),
					$c->get( 'settings.company' ),
					$c->get( 'audit.logger' )
				);
			}
		);

		$container->set(
			'vacations.approval_service',
			static function ( Container $c ): ApprovalService {
				return new ApprovalService(
					$c->get( 'vacations.request_repository' ),
					$c->get( 'vacations.balance_calculator' ),
					$c->get( 'employees.repository' ),
					$c->get( 'audit.logger' )
				);
			}
		);

		$container->set(
			'vacations.notifications',
			static function ( Container $c ): VacationNotifications {
				return new VacationNotifications(
					$c->get( 'notifications.dispatcher' ),
					$c->get( 'vacations.approval_service' )
				);
			}
		);

		// Engancha los listeners de notificaciones a los actions del módulo.
		$container->get( 'vacations.notifications' )->register_hooks();

		/**
		 * Disparado cuando el módulo Vacaciones ha terminado de arrancar.
		 *
		 * Las integraciones (admin, REST, frontend) se incorporan en hitos
		 * posteriores; este action está disponible desde 10.A para
		 * extensiones tempranas.
		 *
		 * @since 0.1.0
		 *
		 * @param Container $container Container del Core.
		 */
		do_action( 'welow_rrhh/vacations/booted', $container );
	}
}
