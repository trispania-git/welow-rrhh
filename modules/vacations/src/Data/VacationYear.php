<?php
/**
 * DTO inmutable que representa la configuración de un año de vacaciones.
 *
 * Persistido en la opción `welow_rrhh_vacation_years` (array indexado por
 * año, decodificado a VacationYear[] por VacationYearsConfig).
 *
 * @package Welow\RRHH\Modules\Vacations\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Vacation year config DTO.
 */
final class VacationYear {

	/**
	 * Constructor.
	 *
	 * @param int                     $year                Año (e.g. 2026).
	 * @param bool                    $is_open             Si está abierto para nuevas solicitudes.
	 * @param \DateTimeImmutable|null $request_deadline    Última fecha para solicitar (inclusive); null = sin límite.
	 * @param int|null                $accrual_days        Días anuales (null = usar default empresa).
	 * @param bool                    $carry_over_enabled  Si los días no usados pasan al año siguiente.
	 * @param int|null                $carry_over_max_days Máximo a arrastrar (null = sin límite).
	 * @param \DateTimeImmutable|null $carry_over_deadline Última fecha para gastar arrastrados; null = sin caducidad.
	 */
	public function __construct(
		public readonly int $year,
		public readonly bool $is_open,
		public readonly ?\DateTimeImmutable $request_deadline,
		public readonly ?int $accrual_days,
		public readonly bool $carry_over_enabled,
		public readonly ?int $carry_over_max_days,
		public readonly ?\DateTimeImmutable $carry_over_deadline
	) {}

	/**
	 * Serializa a array para persistencia en option.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'year'                => $this->year,
			'is_open'             => $this->is_open,
			'request_deadline'    => null === $this->request_deadline ? null : $this->request_deadline->format( 'Y-m-d' ),
			'accrual_days'        => $this->accrual_days,
			'carry_over_enabled'  => $this->carry_over_enabled,
			'carry_over_max_days' => $this->carry_over_max_days,
			'carry_over_deadline' => null === $this->carry_over_deadline ? null : $this->carry_over_deadline->format( 'Y-m-d' ),
		);
	}

	/**
	 * Hidrata desde array previamente serializado.
	 *
	 * @param array<string,mixed> $raw Datos crudos.
	 * @return self|null Null si el año es inválido.
	 */
	public static function from_array( array $raw ): ?self {
		$year = isset( $raw['year'] ) ? (int) $raw['year'] : 0;
		if ( $year < 1900 || $year > 9999 ) {
			return null;
		}
		$tz = wp_timezone();
		return new self(
			$year,
			! empty( $raw['is_open'] ),
			self::parse_date( $raw['request_deadline'] ?? null, $tz ),
			isset( $raw['accrual_days'] ) && null !== $raw['accrual_days'] ? max( 0, (int) $raw['accrual_days'] ) : null,
			! empty( $raw['carry_over_enabled'] ),
			isset( $raw['carry_over_max_days'] ) && null !== $raw['carry_over_max_days'] ? max( 0, (int) $raw['carry_over_max_days'] ) : null,
			self::parse_date( $raw['carry_over_deadline'] ?? null, $tz )
		);
	}

	/**
	 * Parsea una cadena YYYY-MM-DD a DateTimeImmutable en la TZ dada.
	 *
	 * @param mixed         $raw Valor crudo.
	 * @param \DateTimeZone $tz  Timezone.
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_date( $raw, \DateTimeZone $tz ): ?\DateTimeImmutable {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $tz );
		return false === $dt ? null : $dt->setTime( 0, 0, 0 );
	}
}
