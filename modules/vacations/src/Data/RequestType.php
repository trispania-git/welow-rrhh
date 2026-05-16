<?php
/**
 * Tipo de solicitud de ausencia (§8.1).
 *
 * El conjunto canónico cubre los tipos contemplados en la especificación. Si
 * integradores necesitan tipos adicionales, deben mantenerlos vía meta del
 * empleado o un módulo propio: este enum es la fuente de verdad para
 * validaciones del core de Vacaciones.
 *
 * @package Welow\RRHH\Modules\Vacations\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Request type.
 */
enum RequestType: string {
	case VACATION       = 'vacation';
	case PERSONAL_LEAVE = 'personal_leave';
	case SICK           = 'sick';
	case UNPAID         = 'unpaid';

	/**
	 * Hidrata desde cadena BD/REST, devolviendo null si no hay match.
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
			case self::VACATION:
				return __( 'Vacaciones', 'welow-rrhh' );
			case self::PERSONAL_LEAVE:
				return __( 'Asuntos propios', 'welow-rrhh' );
			case self::SICK:
				return __( 'Baja médica', 'welow-rrhh' );
			case self::UNPAID:
				return __( 'Sin sueldo', 'welow-rrhh' );
		}
		return '';
	}

	/**
	 * Indica si el tipo consume del saldo anual de vacaciones.
	 *
	 * Por defecto sólo VACATION consume saldo; las otras categorías quedan
	 * registradas para histórico pero no descuentan días.
	 *
	 * @return bool
	 */
	public function consumes_balance(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return self::VACATION === $this;
	}
}
