<?php
/**
 * DTO inmutable que representa un empleado.
 *
 * Mapea la fila de `welow_employees` (§4.1). El `dni_nie` se expone aquí en
 * texto plano (descifrado por el Repository al hidratar). La serialización
 * a/desde base de datos y el cifrado/descifrado son responsabilidad del
 * EmployeeRepository.
 *
 * @package Welow\RRHH\Support\Data
 */

declare( strict_types=1 );

namespace Welow\RRHH\Support\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Employee DTO.
 */
final class Employee {

	/**
	 * Constructor.
	 *
	 * @param int|null                $id                     PK; null si aún no persistido.
	 * @param int                     $user_id                FK lógica a wp_users.ID.
	 * @param string|null             $employee_code          Código interno opcional, UNIQUE.
	 * @param string|null             $dni_nie                DNI/NIE en texto plano (puede ser null si no informado).
	 * @param string                  $first_name             Nombre.
	 * @param string                  $last_name              Apellidos.
	 * @param int|null                $department_id          FK a welow_departments.
	 * @param string                  $position               Cargo.
	 * @param int|null                $manager_user_id        FK lógica a wp_users (manager directo).
	 * @param \DateTimeImmutable|null $hire_date              Fecha de alta laboral.
	 * @param \DateTimeImmutable|null $termination_date       Fecha de baja (null si activo).
	 * @param float|null              $weekly_hours           Horas semanales contratadas.
	 * @param int|null                $vacation_days_override Override del default de empresa.
	 * @param array<mixed>|null       $geo_policy_override    Override de política geo.
	 * @param EmployeeStatus          $status                 Estado.
	 * @param array<string, mixed>    $meta                   Metadatos arbitrarios.
	 * @param \DateTimeImmutable|null $created_at             Timestamp de creación.
	 * @param \DateTimeImmutable|null $updated_at             Timestamp de última edición.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $user_id,
		public readonly ?string $employee_code,
		public readonly ?string $dni_nie,
		public readonly string $first_name,
		public readonly string $last_name,
		public readonly ?int $department_id,
		public readonly string $position,
		public readonly ?int $manager_user_id,
		public readonly ?\DateTimeImmutable $hire_date,
		public readonly ?\DateTimeImmutable $termination_date,
		public readonly ?float $weekly_hours,
		public readonly ?int $vacation_days_override,
		public readonly ?array $geo_policy_override,
		public readonly EmployeeStatus $status,
		public readonly array $meta = array(),
		public readonly ?\DateTimeImmutable $created_at = null,
		public readonly ?\DateTimeImmutable $updated_at = null
	) {}

	/**
	 * Nombre completo (trim si alguno de los campos viene vacío).
	 *
	 * @return string
	 */
	public function full_name(): string {
		return trim( $this->first_name . ' ' . $this->last_name );
	}

	/**
	 * Indica si el empleado está activo (status === active y sin fecha de baja).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return EmployeeStatus::ACTIVE === $this->status && null === $this->termination_date;
	}
}
