<?php
/**
 * Estados posibles de una solicitud de vacaciones (§8.2).
 *
 * @package Welow\RRHH\Modules\Vacations\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Request status.
 */
enum RequestStatus: string {
	case PENDING   = 'pending';
	case APPROVED  = 'approved';
	case REJECTED  = 'rejected';
	case CANCELLED = 'cancelled';

	/**
	 * Hidrata desde cadena BD/REST.
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
			case self::PENDING:
				return __( 'Pendiente', 'welow-rrhh' );
			case self::APPROVED:
				return __( 'Aprobada', 'welow-rrhh' );
			case self::REJECTED:
				return __( 'Rechazada', 'welow-rrhh' );
			case self::CANCELLED:
				return __( 'Cancelada', 'welow-rrhh' );
		}
		return '';
	}

	/**
	 * Indica si el estado consume saldo (sólo APPROVED descuenta).
	 *
	 * PENDING reserva saldo lógicamente pero no se materializa en used; la
	 * comprobación de "saldo disponible" en BalanceCalculator restará
	 * pending + approved a `accrued + carry_over`.
	 *
	 * @return bool
	 */
	public function consumes_used(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return self::APPROVED === $this;
	}
}
