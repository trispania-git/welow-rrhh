<?php
/**
 * Ámbito de un festivo.
 *
 * @package Welow\RRHH\Support\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Support\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Ámbitos: national / regional / local / company.
 */
enum HolidayScope: string {
	case NATIONAL = 'national';
	case REGIONAL = 'regional';
	case LOCAL    = 'local';
	case COMPANY  = 'company';

	/**
	 * Ámbito por defecto.
	 *
	 * @return self
	 */
	public static function get_default(): self {
		return self::NATIONAL;
	}

	/**
	 * Crea un scope desde una cadena de BD/CSV, cayendo al default si no es válido.
	 *
	 * @param string|null $value Valor crudo.
	 * @return self
	 */
	public static function from_db( ?string $value ): self {
		if ( null === $value ) {
			return self::get_default();
		}
		return self::tryFrom( strtolower( trim( $value ) ) ) ?? self::get_default();
	}

	/**
	 * Etiqueta humana traducible.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		switch ( $this ) {
			case self::NATIONAL:
				return __( 'Nacional', 'welow-rrhh' );
			case self::REGIONAL:
				return __( 'Autonómico', 'welow-rrhh' );
			case self::LOCAL:
				return __( 'Local', 'welow-rrhh' );
			case self::COMPANY:
				return __( 'Empresa', 'welow-rrhh' );
		}
		return '';
	}
}
