<?php
/**
 * Origen del evento de fichaje (§4.2).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Entry source.
 */
enum EntrySource: string {
	case WEB    = 'web';
	case MANUAL = 'manual';
	case IMPORT = 'import';
	case API    = 'api';

	/**
	 * Crea desde valor de BD/REST.
	 *
	 * @param string|null $value Valor.
	 * @return self
	 */
	public static function from_db( ?string $value ): self {
		if ( null === $value ) {
			return self::WEB;
		}
		return self::tryFrom( strtolower( trim( $value ) ) ) ?? self::WEB;
	}
}
