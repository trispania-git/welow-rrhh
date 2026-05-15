<?php
/**
 * DTO inmutable que representa un departamento (welow_departments, §4.1).
 *
 * @package Welow\RRHH\Support\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Support\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Department DTO.
 */
final class Department {

	/**
	 * Constructor.
	 *
	 * @param int|null                $id              PK; null si aún no persistido.
	 * @param string                  $name            Nombre.
	 * @param string                  $slug            Slug único.
	 * @param int|null                $parent_id       Departamento padre (jerarquía).
	 * @param int|null                $manager_user_id WP_User id del manager.
	 * @param \DateTimeImmutable|null $created_at      Timestamp de creación.
	 * @param \DateTimeImmutable|null $updated_at      Timestamp de última edición.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly string $slug,
		public readonly ?int $parent_id,
		public readonly ?int $manager_user_id,
		public readonly ?\DateTimeImmutable $created_at = null,
		public readonly ?\DateTimeImmutable $updated_at = null
	) {}
}
