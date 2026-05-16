<?php
/**
 * Módulo Fichajes (§7 de la especificación).
 *
 * Implementa el control horario completo conforme al Real Decreto-ley
 * 8/2019: registro de eventos (entrada/salida/pausas), edición auditada,
 * cierre de mes, exportación PDF mensual y restricciones geo/IP.
 *
 * @package Welow\RRHH\Modules\TimeTracking
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking;

use Welow\RRHH\Container;
use Welow\RRHH\Frontend\Frontend as CoreFrontend;
use Welow\RRHH\Modules\AbstractModule;
use Welow\RRHH\Modules\TimeTracking\Admin\AdminBootstrap as TimeTrackingAdmin;
use Welow\RRHH\Modules\TimeTracking\Closure\ClosureGuard;
use Welow\RRHH\Modules\TimeTracking\Closure\MonthClosure;
use Welow\RRHH\Modules\TimeTracking\Exporters\MonthlyReport;
use Welow\RRHH\Modules\TimeTracking\Frontend\MyTimeEntriesTab;
use Welow\RRHH\Modules\TimeTracking\Frontend\PunchTab;
use Welow\RRHH\Modules\TimeTracking\Policy\PunchGuard;
use Welow\RRHH\Modules\TimeTracking\Policy\PunchPolicyResolver;
use Welow\RRHH\Modules\TimeTracking\REST\PunchesController;
use Welow\RRHH\Modules\TimeTracking\REST\RateLimiter;
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
	 * Registra servicios + hooks runtime del módulo.
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

		$container->set(
			'time_tracking.policy_resolver',
			static function ( Container $c ): PunchPolicyResolver {
				return new PunchPolicyResolver(
					$c->get( 'settings.company' ),
					$c->get( 'employees.repository' )
				);
			}
		);

		$container->set(
			'time_tracking.punch_guard',
			static function ( Container $c ): PunchGuard {
				return new PunchGuard(
					$c->get( 'time_tracking.policy_resolver' ),
					$c->get( 'audit.logger' )
				);
			}
		);

		$container->set(
			'time_tracking.rate_limiter_punch',
			static function (): RateLimiter {
				return new RateLimiter( 'punch', 10, 60 );
			}
		);

		$container->set(
			'time_tracking.rest_controller',
			static function ( Container $c ): PunchesController {
				return new PunchesController(
					$c->get( 'time_tracking.service' ),
					$c->get( 'employees.repository' ),
					$c->get( 'time_tracking.rate_limiter_punch' )
				);
			}
		);

		// Engancha guard al filtro can_punch (9.B).
		$container->get( 'time_tracking.punch_guard' )->register_hooks();

		// Cierre de mes (9.D).
		$container->set(
			'time_tracking.month_closure',
			static function ( Container $c ): MonthClosure {
				return new MonthClosure( $c->get( 'audit.logger' ) );
			}
		);
		$container->set(
			'time_tracking.closure_guard',
			static function ( Container $c ): ClosureGuard {
				return new ClosureGuard( $c->get( 'time_tracking.month_closure' ) );
			}
		);
		$container->get( 'time_tracking.closure_guard' )->register_hooks();

		// Reporte mensual (9.E).
		$container->set(
			'time_tracking.monthly_report',
			static function ( Container $c ): MonthlyReport {
				return new MonthlyReport(
					$c->get( 'time_tracking.repository' ),
					$c->get( 'employees.repository' ),
					$c->get( 'departments.repository' ),
					$c->get( 'settings.company' )
				);
			}
		);

		// Admin del módulo (lista, edición, cierre de mes) — sólo cuando is_admin.
		if ( is_admin() ) {
			$admin = new TimeTrackingAdmin( $container );
			$admin->register_hooks();
		}

		// Registra controller REST vía filtro del Core.
		add_filter(
			'welow_rrhh/rest/controllers',
			static function ( array $controllers ) use ( $container ): array {
				$controllers[] = $container->get( 'time_tracking.rest_controller' );
				return $controllers;
			}
		);

		// Añade tabs al dashboard frontend (9.C).
		add_filter(
			'welow_rrhh/dashboard/tabs',
			static function ( array $tabs ) use ( $container ): array {
				$service  = $container->get( 'time_tracking.service' );
				$resolver = $container->get( 'time_tracking.policy_resolver' );
				$extra    = array(
					new PunchTab( $service, $resolver ),
					new MyTimeEntriesTab( $service ),
				);
				return array_merge( $tabs, $extra );
			}
		);

		// Encola CSS+JS del módulo cuando hay shortcode del dashboard en la página.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 20 );

		/**
		 * Disparado cuando el módulo Fichajes ha terminado de arrancar.
		 *
		 * @since 0.1.0
		 */
		do_action( 'welow_rrhh/time_tracking/booted' );
	}

	/**
	 * Carga CSS+JS del módulo si la página renderiza el shortcode del dashboard.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, CoreFrontend::SHORTCODE ) ) {
			return;
		}

		wp_enqueue_style(
			'welow-time-tracking',
			WELOW_RRHH_PLUGIN_URL . 'modules/time-tracking/assets/css/time-tracking.css',
			array( 'welow-rrhh-frontend' ),
			WELOW_RRHH_VERSION
		);

		wp_enqueue_script(
			'welow-time-tracking',
			WELOW_RRHH_PLUGIN_URL . 'modules/time-tracking/assets/js/time-tracking.js',
			array( 'jquery', 'welow-rrhh-frontend' ),
			WELOW_RRHH_VERSION,
			true
		);
	}
}
