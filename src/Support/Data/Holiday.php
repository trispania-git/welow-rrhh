<?php
/**
 * DTO inmutable que representa un festivo (welow_holidays).
 *
 * @package Welow\RRHH\Support\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Support\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Holiday DTO.
 */
final class Holiday {

	/**
	 * Constructor.
	 *
	 * @param int|null                $id          PK.
	 * @param \DateTimeImmutable      $date        Fecha del festivo.
	 * @param string                  $name        Descripción.
	 * @param HolidayScope            $scope       Ámbito.
	 * @param int                     $year        Año (denormalizado para queries rápidas).
	 * @param \DateTimeImmutable|null $created_at  Timestamp.
	 * @param \DateTimeImmutable|null $updated_at  Timestamp.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly \DateTimeImmutable $date,
		public readonly string $name,
		public readonly HolidayScope $scope,
		public readonly int $year,
		public readonly ?\DateTimeImmutable $created_at = null,
		public readonly ?\DateTimeImmutable $updated_at = null
	) {}
}
