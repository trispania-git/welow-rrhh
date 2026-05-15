<?php
/**
 * Dispatcher central de notificaciones (§6.4).
 *
 * API:
 *   $dispatcher->send( $user_id, $type, $payload, $channels = null );
 *
 * Si $channels es null, se usan TODOS los canales registrados; si es
 * array, sólo los slugs indicados. Devuelve un mapa slug→bool con el
 * resultado por canal.
 *
 * Los módulos pueden añadir canales propios vía el filtro
 * `welow_rrhh/notifications/channels`.
 *
 * @package Welow\RRHH\Notifications
 */

declare( strict_types=1 );

namespace Welow\RRHH\Notifications;

use Welow\RRHH\Notifications\Channels\ChannelInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Notification Dispatcher.
 */
final class Dispatcher {

	/**
	 * Canales por defecto (inyectados en el constructor).
	 *
	 * @var ChannelInterface[]
	 */
	private array $default_channels;

	/**
	 * Constructor.
	 *
	 * @param ChannelInterface[] $channels Canales por defecto (al menos email + in_app).
	 */
	public function __construct( array $channels ) {
		$this->default_channels = $channels;
	}

	/**
	 * Envía una notificación a un usuario.
	 *
	 * @param int                  $user_id  Destinatario.
	 * @param string               $type     Tipo (slug, ej. "vacation_requested").
	 * @param array<string, mixed> $payload  Datos.
	 * @param string[]|null        $channels Slugs concretos a usar, o null para usar todos.
	 * @return array<string, bool> Mapa slug→ok.
	 */
	public function send( int $user_id, string $type, array $payload = array(), ?array $channels = null ): array {
		/**
		 * Permite a módulos añadir/quitar canales antes del envío.
		 *
		 * @since 0.1.0
		 *
		 * @param ChannelInterface[]   $channels Lista de canales activa.
		 * @param int                  $user_id  Usuario.
		 * @param string               $type     Tipo.
		 * @param array<string, mixed> $payload  Payload.
		 */
		$available = apply_filters( 'welow_rrhh/notifications/channels', $this->default_channels, $user_id, $type, $payload );

		$results = array();
		foreach ( (array) $available as $channel ) {
			if ( ! $channel instanceof ChannelInterface ) {
				continue;
			}
			$slug = $channel->slug();
			if ( null !== $channels && ! in_array( $slug, $channels, true ) ) {
				continue;
			}
			try {
				$results[ $slug ] = (bool) $channel->deliver( $user_id, $type, $payload );
			} catch ( \Throwable $e ) {
				$results[ $slug ] = false;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[Welow RRHH] Notificación %s falló en canal %s para user %d: %s',
							$type,
							$slug,
							$user_id,
							$e->getMessage()
						)
					);
				}
			}
		}

		/**
		 * Hook posterior al envío, útil para auditoría/observabilidad.
		 *
		 * @since 0.1.0
		 *
		 * @param int                  $user_id Usuario.
		 * @param string               $type    Tipo.
		 * @param array<string, mixed> $payload Payload.
		 * @param array<string, bool>  $results Resultados por canal.
		 */
		do_action( 'welow_rrhh/notifications/sent', $user_id, $type, $payload, $results );

		return $results;
	}
}
