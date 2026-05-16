<?php
/**
 * Tipo de evento de fichaje (§4.2).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Event type.
 */
enum EventType: string {
	case PUNCH_IN    = 'punch_in';
	case PUNCH_OUT   = 'punch_out';
	case BREAK_START = 'break_start';
	case BREAK_END   = 'break_end';

	/**
	 * Crea un EventType desde una cadena de BD/REST.
	 *
	 * @param string|null $value Valor crudo.
	 * @return self|null
	 */
	public static function from_db( ?string $value ): ?self {
		if ( null === $value ) {
			return null;
		}
		return self::tryFrom( strtolower( trim( $value ) ) );
	}

	/**
	 * Etiqueta humana traducible.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		switch ( $this ) {
			case self::PUNCH_IN:
				return __( 'Entrada', 'welow-rrhh' );
			case self::PUNCH_OUT:
				return __( 'Salida', 'welow-rrhh' );
			case self::BREAK_START:
				return __( 'Inicio pausa', 'welow-rrhh' );
			case self::BREAK_END:
				return __( 'Fin pausa', 'welow-rrhh' );
		}
		return '';
	}

	/**
	 * Indica si el evento marca apertura (entrada o inicio pausa).
	 *
	 * @return bool
	 */
	public function is_opening(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return self::PUNCH_IN === $this || self::BREAK_START === $this;
	}
}
