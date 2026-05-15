<?php
/**
 * Contrato común para canales de entrega de notificaciones.
 *
 * Los módulos pueden añadir canales propios (Slack, Telegram, push) vía
 * el filtro `welow_rrhh/notifications/channels`.
 *
 * @package Welow\RRHH\Notifications\Channels
 */

declare( strict_types=1 );

namespace Welow\RRHH\Notifications\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Channel.
 */
interface ChannelInterface {

	/**
	 * Slug del canal (ej. "email", "in_app", "slack").
	 */
	public function slug(): string;

	/**
	 * Entrega una notificación.
	 *
	 * @param int                  $user_id Destinatario.
	 * @param string               $type    Tipo de notificación.
	 * @param array<string, mixed> $payload Datos.
	 * @return bool true si la entrega tuvo éxito.
	 */
	public function deliver( int $user_id, string $type, array $payload ): bool;
}
