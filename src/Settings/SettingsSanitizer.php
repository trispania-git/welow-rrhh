<?php
/**
 * Sanitización por sección de `welow_rrhh_company_settings`.
 *
 * Cada método sanitize_<section>() recibe el array crudo del POST y devuelve
 * el array tipado y validado listo para persistir. Si encuentra inválidos
 * valores opcionales, los descarta; si encuentra inválidos requeridos,
 * fuerza el default.
 *
 * @package Welow\RRHH\Settings
 */

declare( strict_types=1 );

namespace Welow\RRHH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizador de secciones de Settings.
 */
final class SettingsSanitizer {

	/**
	 * Devuelve el listado de códigos ISO 3166-2:ES para las CCAA permitidas.
	 *
	 * @return array<string, string>
	 */
	public static function ccaa_choices(): array {
		return array(
			'ES-AN' => __( 'Andalucía', 'welow-rrhh' ),
			'ES-AR' => __( 'Aragón', 'welow-rrhh' ),
			'ES-AS' => __( 'Asturias', 'welow-rrhh' ),
			'ES-IB' => __( 'Illes Balears', 'welow-rrhh' ),
			'ES-CN' => __( 'Canarias', 'welow-rrhh' ),
			'ES-CB' => __( 'Cantabria', 'welow-rrhh' ),
			'ES-CL' => __( 'Castilla y León', 'welow-rrhh' ),
			'ES-CM' => __( 'Castilla-La Mancha', 'welow-rrhh' ),
			'ES-CT' => __( 'Cataluña', 'welow-rrhh' ),
			'ES-EX' => __( 'Extremadura', 'welow-rrhh' ),
			'ES-GA' => __( 'Galicia', 'welow-rrhh' ),
			'ES-MD' => __( 'Madrid', 'welow-rrhh' ),
			'ES-MC' => __( 'Murcia', 'welow-rrhh' ),
			'ES-NC' => __( 'Navarra', 'welow-rrhh' ),
			'ES-PV' => __( 'País Vasco', 'welow-rrhh' ),
			'ES-RI' => __( 'La Rioja', 'welow-rrhh' ),
			'ES-VC' => __( 'Comunidad Valenciana', 'welow-rrhh' ),
			'ES-CE' => __( 'Ceuta', 'welow-rrhh' ),
			'ES-ML' => __( 'Melilla', 'welow-rrhh' ),
		);
	}

	/**
	 * Roles válidos para approval_flow.
	 *
	 * @return string[]
	 */
	public static function approval_roles(): array {
		return array( 'manager_direct', 'hr', 'admin' );
	}

	/**
	 * Sanitiza la sección "company".
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return array<string, mixed>
	 */
	public static function sanitize_company( array $data ): array {
		return array(
			'name'               => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'cif'                => sanitize_text_field( (string) ( $data['cif'] ?? '' ) ),
			'address'            => sanitize_textarea_field( (string) ( $data['address'] ?? '' ) ),
			'logo_attachment_id' => ! empty( $data['logo_attachment_id'] ) ? (int) $data['logo_attachment_id'] : null,
		);
	}

	/**
	 * Sanitiza la sección "calendar".
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return array<string, mixed>
	 */
	public static function sanitize_calendar( array $data ): array {
		$tz_raw       = isset( $data['timezone'] ) ? (string) $data['timezone'] : 'Europe/Madrid';
		$tz_supported = in_array( $tz_raw, timezone_identifiers_list(), true );

		$ccaa_raw = isset( $data['ccaa'] ) ? (string) $data['ccaa'] : 'ES-MD';
		$ccaa     = array_key_exists( $ccaa_raw, self::ccaa_choices() ) ? $ccaa_raw : 'ES-MD';

		$first_day = isset( $data['first_day_of_week'] ) ? (int) $data['first_day_of_week'] : 1;
		if ( $first_day < 0 || $first_day > 6 ) {
			$first_day = 1;
		}

		return array(
			'timezone'          => $tz_supported ? $tz_raw : 'Europe/Madrid',
			'first_day_of_week' => $first_day,
			'ccaa'              => $ccaa,
		);
	}

	/**
	 * Sanitiza la sección "vacations".
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return array<string, mixed>
	 */
	public static function sanitize_vacations( array $data ): array {
		$out = array(
			'default_days_per_year'   => self::int_in_range( $data['default_days_per_year'] ?? 22, 0, 365, 22 ),
			'accrual_policy'          => self::enum_in( $data['accrual_policy'] ?? 'full_year', array( 'full_year', 'prorated' ), 'full_year' ),
			'computation'             => self::enum_in( $data['computation'] ?? 'working_days', array( 'working_days', 'natural_days' ), 'working_days' ),
			'allow_carry_over'        => ! empty( $data['allow_carry_over'] ),
			'carry_over_max_days'     => self::int_in_range( $data['carry_over_max_days'] ?? 5, 0, 365, 5 ),
			'carry_over_deadline'     => self::mm_dd( (string) ( $data['carry_over_deadline'] ?? '03-31' ), '03-31' ),
			'min_request_notice_days' => self::int_in_range( $data['min_request_notice_days'] ?? 7, 0, 365, 7 ),
			'max_consecutive_days'    => self::int_in_range( $data['max_consecutive_days'] ?? 30, 1, 365, 30 ),
			'approval_flow'           => self::sanitize_approval_flow( $data['approval_flow'] ?? array() ),
		);
		return $out;
	}

	/**
	 * Sanitiza la sección "time_tracking".
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return array<string, mixed>
	 */
	public static function sanitize_time_tracking( array $data ): array {
		return array(
			'require_geo'                 => ! empty( $data['require_geo'] ),
			'require_ip_allowlist'        => ! empty( $data['require_ip_allowlist'] ),
			'ip_allowlist'                => self::sanitize_ip_list( $data['ip_allowlist'] ?? '' ),
			'geo_radius_meters'           => self::int_in_range( $data['geo_radius_meters'] ?? 200, 10, 50000, 200 ),
			'office_locations'            => self::sanitize_office_locations( $data['office_locations'] ?? '' ),
			'auto_close_month_day'        => self::int_in_range( $data['auto_close_month_day'] ?? 5, 1, 28, 5 ),
			'max_daily_hours_warning'     => self::float_in_range( $data['max_daily_hours_warning'] ?? 10, 1, 24, 10 ),
			'mandatory_break_after_hours' => self::float_in_range( $data['mandatory_break_after_hours'] ?? 6, 1, 24, 6 ),
		);
	}

	/**
	 * Sanitiza la sección "notifications".
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return array<string, mixed>
	 */
	public static function sanitize_notifications( array $data ): array {
		$email_raw = isset( $data['email_from_address'] ) ? sanitize_email( (string) $data['email_from_address'] ) : '';
		return array(
			'email_from_name'    => sanitize_text_field( (string) ( $data['email_from_name'] ?? '' ) ),
			'email_from_address' => is_email( $email_raw ) ? $email_raw : '',
		);
	}

	/**
	 * Sanitiza approval_flow: array de items con level/role.
	 *
	 * @param mixed $raw Datos crudos (esperado array de items).
	 * @return array<int, array{level: int, role: string}>
	 */
	private static function sanitize_approval_flow( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$flow  = array();
		$level = 1;
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			// Prioridad: role_custom (permite user:ID) sobre el dropdown role.
			$role_custom = isset( $item['role_custom'] ) ? trim( (string) $item['role_custom'] ) : '';
			$role        = '' !== $role_custom
				? sanitize_text_field( $role_custom )
				: ( isset( $item['role'] ) ? sanitize_key( (string) $item['role'] ) : '' );

			if ( '' === $role ) {
				continue;
			}

			// Aceptar user:<ID> dinámico (no en la lista canónica).
			$is_user_ref = 1 === preg_match( '/^user:\d+$/', $role );
			if ( ! $is_user_ref && ! in_array( $role, self::approval_roles(), true ) ) {
				continue;
			}
			$flow[] = array(
				'level' => $level,
				'role'  => $role,
			);
			++$level;
		}
		return $flow;
	}

	/**
	 * Sanitiza textarea con una IP por línea a array.
	 *
	 * @param mixed $raw Datos crudos (string textarea o array).
	 * @return string[]
	 */
	private static function sanitize_ip_list( $raw ): array {
		if ( is_array( $raw ) ) {
			$lines = $raw;
		} else {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		}
		$out = array();
		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$ip = filter_var( trim( (string) $line ), FILTER_VALIDATE_IP );
				if ( false !== $ip ) {
					$out[] = $ip;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitiza office_locations: acepta JSON o textarea con líneas
	 * "name|lat|lng|radius".
	 *
	 * @param mixed $raw Datos crudos.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_office_locations( $raw ): array {
		if ( is_array( $raw ) ) {
			$rows = $raw;
		} elseif ( is_string( $raw ) ) {
			$trimmed = trim( $raw );
			if ( '' === $trimmed ) {
				return array();
			}
			$decoded = json_decode( $trimmed, true );
			if ( is_array( $decoded ) ) {
				$rows = $decoded;
			} else {
				$rows  = array();
				$lines = preg_split( '/\r\n|\r|\n/', $trimmed );
				foreach ( (array) $lines as $line ) {
					$parts = array_map( 'trim', explode( '|', (string) $line ) );
					if ( count( $parts ) < 3 ) {
						continue;
					}
					$rows[] = array(
						'name'   => $parts[0],
						'lat'    => (float) $parts[1],
						'lng'    => (float) $parts[2],
						'radius' => isset( $parts[3] ) ? (int) $parts[3] : 200,
					);
				}
			}
		} else {
			$rows = array();
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$lat = isset( $row['lat'] ) ? (float) $row['lat'] : null;
			$lng = isset( $row['lng'] ) ? (float) $row['lng'] : null;
			if ( null === $lat || null === $lng || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
				continue;
			}
			$out[] = array(
				'name'   => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'lat'    => $lat,
				'lng'    => $lng,
				'radius' => isset( $row['radius'] ) ? (int) $row['radius'] : 200,
			);
		}
		return $out;
	}

	/**
	 * Entero dentro de rango (con fallback).
	 *
	 * @param mixed $value    Valor.
	 * @param int   $min      Mínimo.
	 * @param int   $max      Máximo.
	 * @param int   $fallback Valor por defecto.
	 * @return int
	 */
	private static function int_in_range( $value, int $min, int $max, int $fallback ): int {
		if ( '' === $value || null === $value ) {
			return $fallback;
		}
		$int = (int) $value;
		if ( $int < $min || $int > $max ) {
			return $fallback;
		}
		return $int;
	}

	/**
	 * Float dentro de rango (con fallback).
	 *
	 * @param mixed     $value    Valor.
	 * @param int|float $min      Mínimo.
	 * @param int|float $max      Máximo.
	 * @param int|float $fallback Valor por defecto.
	 * @return float
	 */
	private static function float_in_range( $value, $min, $max, $fallback ): float {
		if ( '' === $value || null === $value ) {
			return (float) $fallback;
		}
		$float = (float) $value;
		if ( $float < $min || $float > $max ) {
			return (float) $fallback;
		}
		return $float;
	}

	/**
	 * Verifica que un valor está dentro de un set permitido.
	 *
	 * @param mixed                     $value    Valor.
	 * @param array<int|string, string> $allowed Set permitido.
	 * @param string                    $fallback Default.
	 * @return string
	 */
	private static function enum_in( $value, array $allowed, string $fallback ): string {
		$value = (string) $value;
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Valida formato MM-DD.
	 *
	 * @param string $value    Valor.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function mm_dd( string $value, string $fallback ): string {
		if ( 1 === preg_match( '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value ) ) {
			return $value;
		}
		return $fallback;
	}
}
