<?php
/**
 * Canal email: envía la notificación con wp_mail (Content-Type: text/html).
 *
 * Usa EmailTemplateRenderer para producir subject + html. Si wp_mail
 * falla, devuelve false; el Dispatcher loggea el fallo.
 *
 * @package Welow\RRHH\Notifications\Channels
 */

declare( strict_types=1 );

namespace Welow\RRHH\Notifications\Channels;

use Welow\RRHH\Notifications\EmailTemplateRenderer;
use Welow\RRHH\Settings\CompanySettings;

defined( 'ABSPATH' ) || exit;

/**
 * EmailChannel.
 */
final class EmailChannel implements ChannelInterface {

	/**
	 * Renderer de plantillas.
	 *
	 * @var EmailTemplateRenderer
	 */
	private EmailTemplateRenderer $renderer;

	/**
	 * Settings (para From name/email).
	 *
	 * @var CompanySettings
	 */
	private CompanySettings $settings;

	/**
	 * Constructor.
	 *
	 * @param EmailTemplateRenderer $renderer Renderer.
	 * @param CompanySettings       $settings Settings.
	 */
	public function __construct( EmailTemplateRenderer $renderer, CompanySettings $settings ) {
		$this->renderer = $renderer;
		$this->settings = $settings;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'email';
	}

	/**
	 * Renderiza la plantilla del tipo y envía el email vía wp_mail.
	 *
	 * @param int                  $user_id Destinatario.
	 * @param string               $type    Tipo de notificación.
	 * @param array<string, mixed> $payload Datos para la plantilla.
	 * @return bool
	 */
	public function deliver( int $user_id, string $type, array $payload ): bool {
		$user = get_userdata( $user_id );
		if ( false === $user || empty( $user->user_email ) ) {
			return false;
		}

		$rendered = $this->renderer->render( $type, $payload );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$from = $this->settings->section( CompanySettings::SECTION_NOTIFICATIONS );
		if ( ! empty( $from['email_from_address'] ) ) {
			$from_name = ! empty( $from['email_from_name'] ) ? (string) $from['email_from_name'] : get_bloginfo( 'name' );
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from['email_from_address'] );
		}

		return (bool) wp_mail( $user->user_email, $rendered['subject'], $rendered['html'], $headers );
	}
}
