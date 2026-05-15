<?php
/**
 * Servicio de auditoría: API de alto nivel para registrar acciones sensibles.
 *
 * Toda escritura/borrado en entidades del plugin (empleados, fichajes,
 * solicitudes de vacaciones, aprobaciones, cierres de mes, cambios de
 * configuración) debe registrarse a través de este servicio (§14).
 *
 * @package Welow\RRHH\Audit
 */

declare( strict_types=1 );

namespace Welow\RRHH\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Logger de auditoría.
 */
final class AuditLogger {

	/**
	 * Repositorio subyacente.
	 *
	 * @var AuditRepository
	 */
	private AuditRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param AuditRepository $repository Repositorio del log.
	 */
	public function __construct( AuditRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Registra una acción.
	 *
	 * El actor se infiere del usuario logueado salvo que se pase explícito
	 * (útil para procesos en background / WP-Cron, donde no hay usuario).
	 *
	 * @param string            $action        Acción semántica (create, update, delete, approve, reject…).
	 * @param string            $entity_type   Tipo de entidad afectada.
	 * @param int|null          $entity_id     ID de la entidad afectada (null si no aplica).
	 * @param array<mixed>|null $diff         Diff antes/después u objeto contextual.
	 * @param int|null          $actor_user_id Forzar actor (opcional).
	 * @return int|null Id de la entrada o null si falló.
	 */
	public function log(
		string $action,
		string $entity_type,
		?int $entity_id = null,
		?array $diff = null,
		?int $actor_user_id = null
	): ?int {
		$actor = $actor_user_id ?? get_current_user_id();
		$actor = $actor > 0 ? $actor : null;

		$result = $this->repository->record(
			$actor,
			$entity_type,
			$entity_id,
			$action,
			$diff,
			self::detect_ip(),
			self::detect_user_agent()
		);

		return false === $result ? null : (int) $result;
	}

	/**
	 * IP del request actual. Por seguridad solo usa REMOTE_ADDR (no confiamos
	 * en X-Forwarded-For por defecto; configurable en futuro).
	 *
	 * @return string|null
	 */
	private static function detect_ip(): ?string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}
		$raw = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );
		return false === $ip ? null : $ip;
	}

	/**
	 * User agent del request actual (truncado a 255 caracteres).
	 *
	 * @return string|null
	 */
	private static function detect_user_agent(): ?string {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return null;
		}
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		$ua = mb_substr( $ua, 0, 255 );
		return '' === $ua ? null : $ua;
	}
}
