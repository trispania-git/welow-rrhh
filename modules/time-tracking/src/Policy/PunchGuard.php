<?php
/**
 * Guarda del filtro `welow_rrhh/time_tracking/can_punch` (§7.2 / §16).
 *
 * Aplica la política resuelta para el usuario y bloquea con WP_Error
 * mensajes legibles si IP o coordenadas no cumplen. Cada rechazo se
 * registra en el audit log (acción "punch_blocked").
 *
 * @package Welow\RRHH\Modules\TimeTracking\Policy
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Policy;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Modules\TimeTracking\Data\EventType;

defined( 'ABSPATH' ) || exit;

/**
 * PunchGuard.
 */
final class PunchGuard {

	/**
	 * Resolutor de política.
	 *
	 * @var PunchPolicyResolver
	 */
	private PunchPolicyResolver $resolver;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param PunchPolicyResolver $resolver Resolver.
	 * @param AuditLogger         $audit    Audit logger.
	 */
	public function __construct( PunchPolicyResolver $resolver, AuditLogger $audit ) {
		$this->resolver = $resolver;
		$this->audit    = $audit;
	}

	/**
	 * Engancha el guard en el filtro can_punch.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'welow_rrhh/time_tracking/can_punch', array( $this, 'check' ), 10, 4 );
	}

	/**
	 * Callback del filtro can_punch.
	 *
	 * @param true|\WP_Error       $allowed    Estado previo del filtro.
	 * @param int                  $user_id    Usuario.
	 * @param EventType            $event_type Tipo de evento (no usado aquí; se aceptan todos por igual).
	 * @param array<string, mixed> $context    Contexto: ip, latitude, longitude.
	 * @return true|\WP_Error
	 */
	public function check( $allowed, int $user_id, EventType $event_type, array $context ) {
		unset( $event_type );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$policy = $this->resolver->for_user( $user_id );

		// 1) Restricción por IP.
		if ( $policy->require_ip_allowlist ) {
			$ip = isset( $context['ip'] ) && '' !== $context['ip'] ? (string) $context['ip'] : self::detect_ip();
			if ( null === $ip || ! self::ip_is_allowed( $ip, $policy->ip_allowlist ) ) {
				return $this->reject(
					$user_id,
					'welow_punch_ip_not_allowed',
					__( 'Fichaje no permitido desde esta red. Contacta con RRHH si es un error.', 'welow-rrhh' ),
					array(
						'ip'             => $ip,
						'allowlist_size' => count( $policy->ip_allowlist ),
					)
				);
			}
		}

		// 2) Restricción por geolocalización.
		if ( $policy->require_geo ) {
			$lat = isset( $context['latitude'] ) && '' !== $context['latitude'] ? (float) $context['latitude'] : null;
			$lng = isset( $context['longitude'] ) && '' !== $context['longitude'] ? (float) $context['longitude'] : null;

			if ( null === $lat || null === $lng ) {
				return $this->reject(
					$user_id,
					'welow_punch_geo_required',
					__( 'Para fichar necesitas permitir la geolocalización en tu navegador.', 'welow-rrhh' ),
					array()
				);
			}

			if ( ! self::is_inside_any_office( $lat, $lng, $policy->office_locations, $policy->default_radius_meters ) ) {
				return $this->reject(
					$user_id,
					'welow_punch_geo_out_of_range',
					__( 'Fichaje no permitido desde esta ubicación. Contacta con RRHH si es un error.', 'welow-rrhh' ),
					array(
						'lat'    => $lat,
						'lng'    => $lng,
						'radius' => $policy->default_radius_meters,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Registra el intento bloqueado y devuelve el WP_Error.
	 *
	 * @param int                  $user_id Usuario.
	 * @param string               $code    Slug del error.
	 * @param string               $message Mensaje legible.
	 * @param array<string, mixed> $context Contexto adicional.
	 * @return \WP_Error
	 */
	private function reject( int $user_id, string $code, string $message, array $context ): \WP_Error {
		$this->audit->log(
			'punch_blocked',
			'time_entry',
			null,
			array(
				'reason'  => $code,
				'user_id' => $user_id,
				'context' => $context,
			),
			$user_id
		);
		return new \WP_Error( $code, $message, $context );
	}

	/**
	 * ¿La IP coincide con la allowlist? Soporta IPv4 exactas y CIDR.
	 *
	 * Para IPv6 sólo se admite coincidencia exacta.
	 *
	 * @param string   $ip        IP a comprobar.
	 * @param string[] $allowlist Entradas permitidas.
	 * @return bool
	 */
	private static function ip_is_allowed( string $ip, array $allowlist ): bool {
		foreach ( $allowlist as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( false !== strpos( $entry, '/' ) ) {
				if ( self::ipv4_in_cidr( $ip, $entry ) ) {
					return true;
				}
				continue;
			}
			if ( $entry === $ip ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Comprueba si una IPv4 cae dentro de un CIDR.
	 *
	 * @param string $ip   IPv4.
	 * @param string $cidr CIDR (ej. 10.0.0.0/24).
	 * @return bool
	 */
	private static function ipv4_in_cidr( string $ip, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		[ $subnet, $bits_str ] = $parts;
		$bits                  = (int) $bits_str;
		if ( $bits < 0 || $bits > 32 ) {
			return false;
		}
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}
		if ( 0 === $bits ) {
			return true; // 0.0.0.0/0 — cualquier IPv4.
		}
		$mask = -1 << ( 32 - $bits );
		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	/**
	 * ¿La coordenada está dentro del radio de alguna oficina?
	 *
	 * Si la lista de oficinas está vacía y la política exige geo, asumimos
	 * que basta con haber capturado coordenadas (cualquier ubicación es
	 * válida — útil para teletrabajadores que sólo necesitan "permitir GPS").
	 *
	 * @param float                                                         $lat            Latitud actual.
	 * @param float                                                         $lng            Longitud actual.
	 * @param array<int, array{name:string,lat:float,lng:float,radius:int}> $offices Oficinas.
	 * @param int                                                           $default_radius Radio por defecto.
	 * @return bool
	 */
	private static function is_inside_any_office( float $lat, float $lng, array $offices, int $default_radius ): bool {
		if ( empty( $offices ) ) {
			return true;
		}
		foreach ( $offices as $office ) {
			$o_lat  = (float) ( $office['lat'] ?? 0 );
			$o_lng  = (float) ( $office['lng'] ?? 0 );
			$radius = isset( $office['radius'] ) ? (int) $office['radius'] : $default_radius;
			if ( self::haversine_meters( $lat, $lng, $o_lat, $o_lng ) <= $radius ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Distancia Haversine en metros entre dos puntos lat/lng.
	 *
	 * @param float $lat1 Lat 1.
	 * @param float $lng1 Lng 1.
	 * @param float $lat2 Lat 2.
	 * @param float $lng2 Lng 2.
	 * @return float Metros.
	 */
	private static function haversine_meters( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$earth_radius = 6371000.0;
		$lat1_rad     = deg2rad( $lat1 );
		$lat2_rad     = deg2rad( $lat2 );
		$delta_lat    = deg2rad( $lat2 - $lat1 );
		$delta_lng    = deg2rad( $lng2 - $lng1 );
		$a            = ( sin( $delta_lat / 2 ) ** 2 ) + ( cos( $lat1_rad ) * cos( $lat2_rad ) * ( sin( $delta_lng / 2 ) ** 2 ) );
		return 2.0 * $earth_radius * atan2( sqrt( $a ), sqrt( 1.0 - $a ) );
	}

	/**
	 * IP del request actual.
	 *
	 * @return string|null
	 */
	private static function detect_ip(): ?string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}
		$ip = filter_var(
			sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ),
			FILTER_VALIDATE_IP
		);
		return false === $ip ? null : $ip;
	}
}
