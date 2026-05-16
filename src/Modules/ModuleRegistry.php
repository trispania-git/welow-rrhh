<?php
/**
 * Registro de módulos del plugin Welow RRHH.
 *
 * Responsable de:
 *   - Descubrir los módulos disponibles en /modules/<slug>/Module.php
 *   - Mantener el estado de activación (opción `welow_rrhh_active_modules`)
 *   - Resolver dependencias topológicamente para arrancar en orden correcto
 *   - Disparar el ciclo de vida (activate/deactivate/migrate/boot)
 *
 * TODO(welow): la convención de namespace de los módulos en /modules/<slug>/
 * no es PSR-4 estricto porque los slugs son kebab-case. Por ahora se cargan
 * vía require_once manual y cada Module.php declara su propio namespace
 * (sugerencia: Welow\RRHH\Modules\PascalCase). Cuando los módulos crezcan
 * y necesiten clases auxiliares, evaluar registrar autoload dinámico por
 * módulo.
 *
 * @package Welow\RRHH\Modules
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Registro de módulos.
 */
class ModuleRegistry {

	/**
	 * Nombre de la opción que almacena el listado ordenado de módulos activos.
	 */
	private const OPTION_ACTIVE = 'welow_rrhh_active_modules';

	/**
	 * Nombre de la opción que almacena la versión instalada por módulo.
	 */
	private const OPTION_VERSIONS = 'welow_rrhh_module_versions';

	/**
	 * Ruta absoluta al directorio raíz que contiene los módulos.
	 *
	 * @var string
	 */
	private string $modules_dir;

	/**
	 * Módulos descubiertos indexados por slug.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * Indica si ya se ha realizado el descubrimiento.
	 *
	 * @var bool
	 */
	private bool $discovered = false;

	/**
	 * Constructor.
	 *
	 * @param string $modules_dir Ruta absoluta al directorio /modules/.
	 */
	public function __construct( string $modules_dir ) {
		$this->modules_dir = untrailingslashit( $modules_dir );
	}

	/**
	 * Descubre los módulos disponibles en disco.
	 *
	 * Carga cada modules/<slug>/Module.php (debe declarar una clase que
	 * implemente ModuleInterface). Idempotente.
	 *
	 * @return void
	 */
	public function discover(): void {
		if ( $this->discovered ) {
			return;
		}

		$this->discovered = true;

		if ( ! is_dir( $this->modules_dir ) ) {
			return;
		}

		$pattern = $this->modules_dir . '/*/Module.php';
		$files   = glob( $pattern );

		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			$slug = basename( dirname( $file ) );

			$instance = $this->load_module( $file, $slug );
			if ( null === $instance ) {
				continue;
			}

			$this->modules[ $instance->slug() ] = $instance;
		}

		/**
		 * Permite a integradores registrar módulos adicionales descubiertos
		 * fuera del directorio /modules/.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, ModuleInterface> $modules    Módulos indexados por slug.
		 * @param ModuleRegistry                 $registry   Instancia del registro.
		 */
		$filtered = apply_filters( 'welow_rrhh/modules', $this->modules, $this );

		if ( is_array( $filtered ) ) {
			$this->modules = $this->normalize_external_modules( $filtered );
		}
	}

	/**
	 * Devuelve todos los módulos descubiertos.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Recupera un módulo por su slug.
	 *
	 * @param string $slug Slug del módulo.
	 * @return ModuleInterface|null
	 */
	public function get( string $slug ): ?ModuleInterface {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * Indica si un módulo está marcado como activo en opciones.
	 *
	 * @param string $slug Slug del módulo.
	 * @return bool
	 */
	public function is_active( string $slug ): bool {
		return in_array( $slug, $this->active_slugs(), true );
	}

	/**
	 * Devuelve la lista ordenada de slugs activos (según opción).
	 *
	 * @return string[]
	 */
	public function active_slugs(): array {
		$stored = get_option( self::OPTION_ACTIVE, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$stored,
				static fn( $slug ): bool => is_string( $slug ) && '' !== $slug
			)
		);
	}

	/**
	 * Arranca los módulos activos en orden de dependencias.
	 *
	 * Ejecuta `migrate()` si la versión instalada difiere de la declarada y
	 * luego `boot()` para registrar hooks.
	 *
	 * @return void
	 */
	public function boot_active(): void {
		$active = $this->resolve_boot_order( $this->active_slugs() );

		$versions = get_option( self::OPTION_VERSIONS, array() );
		if ( ! is_array( $versions ) ) {
			$versions = array();
		}

		foreach ( $active as $slug ) {
			$module = $this->modules[ $slug ] ?? null;
			if ( null === $module ) {
				continue;
			}

			$installed = $versions[ $slug ] ?? null;
			if ( $installed !== $module->version() ) {
				$module->migrate();
				$versions[ $slug ] = $module->version();
			}

			$module->boot();

			/**
			 * Disparado tras arrancar un módulo.
			 *
			 * @since 0.1.0
			 *
			 * @param string          $slug   Slug del módulo arrancado.
			 * @param ModuleInterface $module Instancia del módulo.
			 */
			do_action( 'welow_rrhh/module_booted', $slug, $module );
		}

		update_option( self::OPTION_VERSIONS, $versions, false );
	}

	/**
	 * Activa un módulo, validando dependencias y ejecutando su activate().
	 *
	 * @param string $slug Slug del módulo a activar.
	 * @return true|\WP_Error true si se activó correctamente, WP_Error si no.
	 */
	public function activate( string $slug ) {
		if ( ! isset( $this->modules[ $slug ] ) ) {
			return new \WP_Error(
				'welow_rrhh_module_not_found',
				/* translators: %s: module slug. */
				sprintf( __( 'Módulo "%s" no encontrado.', 'welow-rrhh' ), $slug )
			);
		}

		$module = $this->modules[ $slug ];

		foreach ( $module->dependencies() as $dep ) {
			if ( ! $this->is_active( $dep ) ) {
				return new \WP_Error(
					'welow_rrhh_missing_dependency',
					sprintf(
						/* translators: 1: module slug, 2: missing dependency slug. */
						__( 'El módulo "%1$s" requiere "%2$s" activo.', 'welow-rrhh' ),
						$slug,
						$dep
					)
				);
			}
		}

		try {
			$module->activate();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_rrhh_activation_failed', $e->getMessage() );
		}

		$active = $this->active_slugs();
		if ( ! in_array( $slug, $active, true ) ) {
			$active[] = $slug;
			update_option( self::OPTION_ACTIVE, $active, false );
		}

		/**
		 * Disparado tras activar un módulo.
		 *
		 * @since 0.1.0
		 *
		 * @param string $slug Slug del módulo recién activado.
		 */
		do_action( 'welow_rrhh/module_activated', $slug );

		return true;
	}

	/**
	 * Desactiva un módulo (no destructivo). Falla si otro módulo activo depende de él.
	 *
	 * @param string $slug Slug del módulo a desactivar.
	 * @return true|\WP_Error
	 */
	public function deactivate( string $slug ) {
		if ( ! isset( $this->modules[ $slug ] ) ) {
			return new \WP_Error(
				'welow_rrhh_module_not_found',
				/* translators: %s: module slug. */
				sprintf( __( 'Módulo "%s" no encontrado.', 'welow-rrhh' ), $slug )
			);
		}

		// Comprobar dependencias inversas.
		foreach ( $this->active_slugs() as $active_slug ) {
			if ( $active_slug === $slug ) {
				continue;
			}
			$active_module = $this->modules[ $active_slug ] ?? null;
			if ( null === $active_module ) {
				continue;
			}
			if ( in_array( $slug, $active_module->dependencies(), true ) ) {
				return new \WP_Error(
					'welow_rrhh_dependent_active',
					sprintf(
						/* translators: 1: module slug being deactivated, 2: dependent module slug. */
						__( 'No se puede desactivar "%1$s": el módulo "%2$s" depende de él.', 'welow-rrhh' ),
						$slug,
						$active_slug
					)
				);
			}
		}

		$module = $this->modules[ $slug ];

		try {
			$module->deactivate();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_rrhh_deactivation_failed', $e->getMessage() );
		}

		$active = array_values(
			array_filter(
				$this->active_slugs(),
				static fn( string $s ): bool => $s !== $slug
			)
		);
		update_option( self::OPTION_ACTIVE, $active, false );

		/**
		 * Disparado tras desactivar un módulo.
		 *
		 * @since 0.1.0
		 *
		 * @param string $slug Slug del módulo desactivado.
		 */
		do_action( 'welow_rrhh/module_deactivated', $slug );

		return true;
	}

	/**
	 * Carga el archivo Module.php de un módulo y devuelve la instancia.
	 *
	 * Estrategia:
	 *   1) require_once del Module.php.
	 *   2) Intentamos resolver por convención: para un slug "time-tracking"
	 *      esperamos la clase \Welow\RRHH\Modules\TimeTracking\Module.
	 *   3) Como fallback (para módulos que no respeten la convención),
	 *      buscamos cualquier clase nueva declarada por el require.
	 *
	 * @param string $file Ruta absoluta a modules/<slug>/Module.php.
	 * @param string $slug Slug derivado del nombre de la carpeta.
	 * @return ModuleInterface|null
	 */
	private function load_module( string $file, string $slug ): ?ModuleInterface {
		$classes_before = get_declared_classes();
		require_once $file;
		$classes_after = get_declared_classes();

		$url = WELOW_RRHH_PLUGIN_URL . 'modules/' . $slug;

		// 1) Convención FQN: Welow\RRHH\Modules\<PascalCase>\Module.
		$pascal = str_replace( ' ', '', ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
		$fqn    = '\\Welow\\RRHH\\Modules\\' . $pascal . '\\Module';
		if ( class_exists( $fqn ) ) {
			$reflection = new \ReflectionClass( $fqn );
			if ( ! $reflection->isAbstract() && $reflection->implementsInterface( ModuleInterface::class ) ) {
				return new $fqn( dirname( $file ), $url );
			}
		}

		// 2) Fallback: primera clase concreta nueva que implemente ModuleInterface.
		$new_classes = array_diff( $classes_after, $classes_before );
		foreach ( $new_classes as $class ) {
			if ( ! is_subclass_of( $class, ModuleInterface::class ) ) {
				continue;
			}
			$reflection = new \ReflectionClass( $class );
			if ( $reflection->isAbstract() ) {
				continue;
			}
			return new $class( dirname( $file ), $url );
		}

		return null;
	}

	/**
	 * Resuelve el orden de arranque en función de las dependencias declaradas.
	 *
	 * Algoritmo topológico simple (Kahn). Los ciclos se omiten silenciosamente
	 * y los módulos sin dependencias respetan el orden original.
	 *
	 * @param string[] $slugs Slugs activos en orden de inserción.
	 * @return string[]
	 */
	private function resolve_boot_order( array $slugs ): array {
		$graph    = array();
		$indegree = array();

		foreach ( $slugs as $slug ) {
			$module = $this->modules[ $slug ] ?? null;
			if ( null === $module ) {
				continue;
			}
			$indegree[ $slug ] = 0;
			$graph[ $slug ]    = array();
		}

		foreach ( array_keys( $graph ) as $slug ) {
			foreach ( $this->modules[ $slug ]->dependencies() as $dep ) {
				if ( ! isset( $graph[ $dep ] ) ) {
					continue; // Dependencia no activa: se ignora aquí, ya se validó en activate().
				}
				$graph[ $dep ][] = $slug;
				++$indegree[ $slug ];
			}
		}

		$queue   = array();
		$ordered = array();

		foreach ( $slugs as $slug ) {
			if ( isset( $indegree[ $slug ] ) && 0 === $indegree[ $slug ] ) {
				$queue[] = $slug;
			}
		}

		while ( ! empty( $queue ) ) {
			$current   = array_shift( $queue );
			$ordered[] = $current;

			foreach ( $graph[ $current ] as $dependant ) {
				--$indegree[ $dependant ];
				if ( 0 === $indegree[ $dependant ] ) {
					$queue[] = $dependant;
				}
			}
		}

		return $ordered;
	}

	/**
	 * Filtra el resultado del filtro `welow_rrhh/modules` para descartar
	 * entradas inválidas y reindexar por slug declarado por el propio módulo.
	 *
	 * @param array<int|string, mixed> $modules Resultado del filtro.
	 * @return array<string, ModuleInterface>
	 */
	private function normalize_external_modules( array $modules ): array {
		$normalized = array();
		foreach ( $modules as $module ) {
			if ( $module instanceof ModuleInterface ) {
				$normalized[ $module->slug() ] = $module;
			}
		}
		return $normalized;
	}
}
