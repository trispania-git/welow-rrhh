<?php
/**
 * Estado laboral de un empleado.
 *
 * Refleja la columna `welow_employees.status` (§4.1).
 *
 * @package Welow\RRHH\Support\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Support\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Enum tipado con valores serializables a base de datos.
 */
enum EmployeeStatus: string {
	case ACTIVE   = 'active';
	case INACTIVE = 'inactive';
	case ON_LEAVE = 'on_leave';

	/**
	 * Devuelve el estado por defecto al crear un empleado.
	 *
	 * @return self
	 */
	public static function get_default(): self {
		return self::ACTIVE;
	}

	/**
	 * Crea un estado desde una cadena de BD, cayendo al default si no es válida.
	 *
	 * @param string|null $value Valor crudo.
	 * @return self
	 */
	public static function from_db( ?string $value ): self {
		if ( null === $value ) {
			return self::get_default();
		}
		return self::tryFrom( $value ) ?? self::get_default();
	}

	/**
	 * Etiqueta humana traducible.
	 *
	 * Nota: PHPCompatibility marca `$this` como uso fuera de objeto al
	 * analizar enums PHP 8.1+, pero es legítimo dentro de métodos de
	 * instancia de un enum.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		switch ( $this ) {
			case self::ACTIVE:
				return __( 'Activo', 'welow-rrhh' );
			case self::INACTIVE:
				return __( 'Inactivo', 'welow-rrhh' );
			case self::ON_LEAVE:
				return __( 'De baja', 'welow-rrhh' );
		}
		return '';
	}
}
