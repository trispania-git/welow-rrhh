<?php
/**
 * Repositorio del log de auditoría (welow_audit_log).
 *
 * Encapsula todo acceso a $wpdb sobre esta tabla.
 *
 * @package Welow\RRHH\Audit
 */

declare( strict_types=1 );

namespace Welow\RRHH\Audit;

use Welow\RRHH\Database\Repository\AbstractRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Repositorio del audit log.
 */
final class AuditRepository extends AbstractRepository {

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->wpdb->prefix . 'welow_audit_log';
	}

	/**
	 * Inserta una entrada de auditoría.
	 *
	 * @param int|null          $actor_user_id Quién realizó la acción.
	 * @param string            $entity_type   Tipo de entidad (ej. "time_entry").
	 * @param int|null          $entity_id     ID de la entidad afectada.
	 * @param string            $action        Acción (ej. "create", "update", "approve").
	 * @param array<mixed>|null $diff         Diff antes/después (se serializa a JSON).
	 * @param string|null       $ip            IP del actor (IPv6 compatible).
	 * @param string|null       $user_agent    User-Agent (truncado a 255).
	 * @return int|false Id de la entrada o false si falló la inserción.
	 */
	public function record(
		?int $actor_user_id,
		string $entity_type,
		?int $entity_id,
		string $action,
		?array $diff,
		?string $ip,
		?string $user_agent
	) {
		$data = array(
			'actor_user_id' => $actor_user_id,
			'entity_type'   => $entity_type,
			'entity_id'     => $entity_id,
			'action'        => $action,
			'diff'          => null !== $diff ? wp_json_encode( $diff ) : null,
			'ip'            => $ip,
			'user_agent'    => $user_agent,
			'created_at'    => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		return $this->insert( $data, $formats );
	}
}
