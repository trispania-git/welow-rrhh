<?php
/**
 * Módulo "skeleton" — ejemplo mínimo que valida el ciclo de vida del sistema
 * de módulos de Welow RRHH.
 *
 * No está incluido en `welow_rrhh_active_modules` por defecto. Sirve como:
 *   1) Plantilla para crear nuevos módulos.
 *   2) Pieza de prueba para verificar discover/boot/activate/deactivate.
 *
 * Cuando se active manualmente desde Ajustes → Welow RRHH → Módulos, el hook
 * `welow_rrhh/skeleton_booted` se dispara en cada carga del plugin.
 *
 * @package Welow\RRHH\Modules\Skeleton
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Skeleton;

use Welow\RRHH\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Módulo de ejemplo.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'skeleton';
	}

	public function name(): string {
		return __( 'Skeleton', 'welow-rrhh' );
	}

	public function description(): string {
		return __(
			'Módulo de ejemplo que valida el ciclo de vida del sistema de módulos. No habilitar en producción.',
			'welow-rrhh'
		);
	}

	public function version(): string {
		return '0.1.0';
	}

	/**
	 * Registra los hooks del módulo.
	 *
	 * En esta plantilla no hace nada visible; sólo dispara una acción que
	 * los tests/integradores pueden enganchar para verificar el arranque.
	 */
	public function boot(): void {
		/**
		 * Disparado cuando el módulo skeleton ha arrancado correctamente.
		 *
		 * @since 0.1.0
		 *
		 * @param self $module Instancia del módulo.
		 */
		do_action( 'welow_rrhh/skeleton_booted', $this );
	}
}
