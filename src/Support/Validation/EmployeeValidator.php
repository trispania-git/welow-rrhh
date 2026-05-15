<?php
/**
 * Validación de entrada para creación/edición de empleados.
 *
 * Aplica reglas de formato. Las validaciones de unicidad (email, código,
 * DNI hash, manager existente) las realiza EmployeeService consultando el
 * repositorio, porque dependen del estado de la base de datos.
 *
 * @package Welow\RRHH\Support\Validation
 */

declare( strict_types=1 );

namespace Welow\RRHH\Support\Validation;

use Welow\RRHH\Support\Data\EmployeeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Validador de payload de empleado.
 */
final class EmployeeValidator {

	/**
	 * Valida un payload de creación.
	 *
	 * Exige `email`, `first_name` y `last_name`. El resto de campos son
	 * opcionales o se completan con valores por defecto.
	 *
	 * @param array<string, mixed> $data Datos de entrada.
	 * @return \WP_Error WP_Error con códigos por campo si hay errores; vacío si todo OK.
	 */
	public static function validate_create( array $data ): \WP_Error {
		$errors = new \WP_Error();

		$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			$errors->add( 'email', __( 'Email no válido.', 'welow-rrhh' ) );
		}

		self::validate_common( $data, $errors );

		return $errors;
	}

	/**
	 * Valida un payload de actualización (los campos son opcionales).
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return \WP_Error
	 */
	public static function validate_update( array $data ): \WP_Error {
		$errors = new \WP_Error();
		self::validate_common( $data, $errors );
		return $errors;
	}

	/**
	 * Validaciones compartidas entre create y update (nombre, dni, hire_date, etc.).
	 *
	 * @param array<string, mixed> $data   Datos.
	 * @param \WP_Error            $errors Errores acumulados.
	 * @return void
	 */
	private static function validate_common( array $data, \WP_Error $errors ): void {
		if ( array_key_exists( 'first_name', $data ) ) {
			$first = sanitize_text_field( (string) $data['first_name'] );
			if ( '' === $first ) {
				$errors->add( 'first_name', __( 'El nombre no puede estar vacío.', 'welow-rrhh' ) );
			}
		}

		if ( array_key_exists( 'last_name', $data ) ) {
			$last = sanitize_text_field( (string) $data['last_name'] );
			if ( '' === $last ) {
				$errors->add( 'last_name', __( 'Los apellidos no pueden estar vacíos.', 'welow-rrhh' ) );
			}
		}

		if ( ! empty( $data['dni_nie'] ) ) {
			$normalized = Dni::normalize( (string) $data['dni_nie'] );
			if ( null === $normalized ) {
				$errors->add( 'dni_nie', __( 'DNI/NIE no válido (formato o letra de control incorrecta).', 'welow-rrhh' ) );
			}
		}

		if ( ! empty( $data['hire_date'] ) ) {
			if ( false === self::parse_date( (string) $data['hire_date'] ) ) {
				$errors->add( 'hire_date', __( 'Fecha de alta no válida (formato YYYY-MM-DD).', 'welow-rrhh' ) );
			}
		}

		if ( ! empty( $data['termination_date'] ) ) {
			if ( false === self::parse_date( (string) $data['termination_date'] ) ) {
				$errors->add( 'termination_date', __( 'Fecha de baja no válida (formato YYYY-MM-DD).', 'welow-rrhh' ) );
			}
		}

		if ( isset( $data['weekly_hours'] ) && '' !== $data['weekly_hours'] ) {
			$hours = (float) $data['weekly_hours'];
			if ( $hours <= 0 || $hours > 168 ) {
				$errors->add( 'weekly_hours', __( 'Horas semanales fuera de rango (0-168).', 'welow-rrhh' ) );
			}
		}

		if ( isset( $data['vacation_days_override'] ) && '' !== $data['vacation_days_override'] ) {
			$days = (int) $data['vacation_days_override'];
			if ( $days < 0 || $days > 365 ) {
				$errors->add( 'vacation_days_override', __( 'Días de vacaciones fuera de rango (0-365).', 'welow-rrhh' ) );
			}
		}

		if ( isset( $data['status'] ) && '' !== $data['status'] ) {
			if ( null === EmployeeStatus::tryFrom( (string) $data['status'] ) ) {
				$errors->add( 'status', __( 'Estado no válido.', 'welow-rrhh' ) );
			}
		}
	}

	/**
	 * Parsea una fecha en formato YYYY-MM-DD. Devuelve DateTimeImmutable o false.
	 *
	 * @param string $value Cadena candidata.
	 * @return \DateTimeImmutable|false
	 */
	public static function parse_date( string $value ) {
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		if ( false === $dt ) {
			return false;
		}
		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			return false;
		}
		return $dt;
	}
}
