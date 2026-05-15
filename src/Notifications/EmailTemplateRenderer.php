<?php
/**
 * Renderiza plantillas de email por tipo, con override desde el tema.
 *
 * Estructura de plantillas:
 *
 *   templates/emails/<type>/subject.php    → devuelve el asunto (string).
 *   templates/emails/<type>/html.php       → cuerpo HTML (output buffer).
 *   templates/emails/<type>/text.php       → cuerpo texto plano (opcional).
 *   templates/emails/layout.php            → wrapper HTML del cuerpo.
 *
 * Override desde el tema activo:
 *   wp-content/themes/<theme>/welow-rrhh/emails/<type>/{subject,html,text}.php
 *
 * Cada plantilla recibe `$vars` (mismo payload) y, para layout, además
 * `$body`, `$subject`, `$brand` (nombre/logo del white-label).
 *
 * @package Welow\RRHH\Notifications
 */

declare( strict_types=1 );

namespace Welow\RRHH\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Email template renderer.
 */
final class EmailTemplateRenderer {

	/**
	 * Renderiza un email completo y devuelve subject + html + text.
	 *
	 * Si no existe plantilla para el tipo, cae a la plantilla "generic".
	 *
	 * @param string               $type Tipo de notificación.
	 * @param array<string, mixed> $vars Variables disponibles dentro de la plantilla.
	 * @return array{subject: string, html: string, text: string}
	 */
	public function render( string $type, array $vars ): array {
		$subject = $this->render_subject( $type, $vars );
		$body_h  = $this->render_html( $type, $vars );
		$body_t  = $this->render_text( $type, $vars );

		$html = $this->wrap_with_layout( $body_h, $subject, $vars );

		$result = array(
			'subject' => $subject,
			'html'    => $html,
			'text'    => $body_t,
		);

		/**
		 * Permite sobrescribir el render final del email.
		 *
		 * @since 0.1.0
		 *
		 * @param array{subject:string, html:string, text:string} $result Render por defecto.
		 * @param string                                          $type   Tipo de notificación.
		 * @param array<string, mixed>                            $vars   Variables.
		 */
		return apply_filters( 'welow_rrhh/notifications/email_template', $result, $type, $vars );
	}

	/**
	 * Asunto del email.
	 *
	 * @param string               $type Tipo.
	 * @param array<string, mixed> $vars Variables.
	 * @return string
	 */
	private function render_subject( string $type, array $vars ): string {
		$path = $this->locate_template( $type, 'subject.php' );
		if ( null === $path ) {
			return self::brand_name();
		}
		// La plantilla DEBE retornar un string.
		$subject = self::scoped_include_returning( $path, $vars );
		return is_string( $subject ) ? wp_strip_all_tags( $subject ) : self::brand_name();
	}

	/**
	 * Cuerpo HTML.
	 *
	 * @param string               $type Tipo.
	 * @param array<string, mixed> $vars Variables.
	 * @return string
	 */
	private function render_html( string $type, array $vars ): string {
		$path = $this->locate_template( $type, 'html.php' );
		if ( null === $path ) {
			return self::default_html_body( $vars );
		}
		return self::scoped_include_capturing( $path, $vars );
	}

	/**
	 * Cuerpo texto plano (opcional).
	 *
	 * @param string               $type Tipo.
	 * @param array<string, mixed> $vars Variables.
	 * @return string
	 */
	private function render_text( string $type, array $vars ): string {
		$path = $this->locate_template( $type, 'text.php' );
		if ( null === $path ) {
			// Fallback a la versión HTML sin etiquetas.
			return wp_strip_all_tags( $this->render_html( $type, $vars ) );
		}
		return self::scoped_include_capturing( $path, $vars );
	}

	/**
	 * Envuelve el cuerpo HTML con el layout común (header + footer + branding).
	 *
	 * @param string               $body    Cuerpo HTML del email.
	 * @param string               $subject Asunto (también disponible en layout).
	 * @param array<string, mixed> $vars    Variables originales.
	 * @return string
	 */
	private function wrap_with_layout( string $body, string $subject, array $vars ): string {
		$path = $this->locate_template_path( 'layout.php' );
		if ( null === $path ) {
			return $body;
		}
		$brand = self::brand();
		return self::scoped_include_capturing(
			$path,
			array_merge(
				$vars,
				array(
					'body'    => $body,
					'subject' => $subject,
					'brand'   => $brand,
				)
			)
		);
	}

	/**
	 * Resuelve la ruta de una plantilla específica para un tipo (con fallback a "generic").
	 *
	 * @param string $type Tipo.
	 * @param string $file Nombre de archivo (ej. "subject.php").
	 * @return string|null Ruta absoluta o null si no existe.
	 */
	private function locate_template( string $type, string $file ): ?string {
		$specific = $this->locate_template_path( $type . '/' . $file );
		if ( null !== $specific ) {
			return $specific;
		}
		return $this->locate_template_path( 'generic/' . $file );
	}

	/**
	 * Busca una plantilla en (a) tema activo, (b) directorio del plugin.
	 *
	 * @param string $relative Ruta relativa a templates/emails/.
	 * @return string|null
	 */
	private function locate_template_path( string $relative ): ?string {
		$theme_path = trailingslashit( get_stylesheet_directory() ) . 'welow-rrhh/emails/' . ltrim( $relative, '/' );
		if ( is_readable( $theme_path ) ) {
			return $theme_path;
		}
		$plugin_path = WELOW_RRHH_PLUGIN_DIR . 'templates/emails/' . ltrim( $relative, '/' );
		if ( is_readable( $plugin_path ) ) {
			return $plugin_path;
		}
		return null;
	}

	/**
	 * Cuerpo HTML por defecto cuando ni el tipo ni "generic" están disponibles.
	 *
	 * @param array<string, mixed> $vars Variables.
	 * @return string
	 */
	private static function default_html_body( array $vars ): string {
		$title = isset( $vars['title'] ) ? (string) $vars['title'] : __( 'Welow RRHH', 'welow-rrhh' );
		$body  = isset( $vars['body'] ) ? (string) $vars['body'] : '';
		return sprintf(
			'<h1>%s</h1>%s',
			esc_html( $title ),
			wp_kses_post( $body )
		);
	}

	/**
	 * Información de marca para los templates (respeta WELOW_RRHH_BRAND_*).
	 *
	 * @return array{name: string, logo_url: string, primary_color: string}
	 */
	public static function brand(): array {
		$name  = defined( 'WELOW_RRHH_BRAND_NAME' ) && '' !== WELOW_RRHH_BRAND_NAME
			? (string) WELOW_RRHH_BRAND_NAME
			: get_bloginfo( 'name' );
		$logo  = defined( 'WELOW_RRHH_BRAND_LOGO_URL' ) ? (string) WELOW_RRHH_BRAND_LOGO_URL : '';
		$color = defined( 'WELOW_RRHH_BRAND_PRIMARY_COLOR' ) ? (string) WELOW_RRHH_BRAND_PRIMARY_COLOR : '#2271b1';

		return array(
			'name'          => $name,
			'logo_url'      => $logo,
			'primary_color' => $color,
		);
	}

	/**
	 * Atajo para el nombre de marca.
	 *
	 * @return string
	 */
	private static function brand_name(): string {
		return self::brand()['name'];
	}

	/**
	 * Include con scope aislado capturando el output buffer.
	 *
	 * @param string               $path Ruta absoluta al archivo.
	 * @param array<string, mixed> $vars Variables que la plantilla recibe como $vars.
	 * @return string
	 */
	private static function scoped_include_capturing( string $path, array $vars ): string {
		$render = static function () use ( $path, $vars ): string {
			ob_start();
			include $path; // phpcs:ignore WordPress.PHP.DontExtract.extract_extract — usamos $vars, no extract.
			return (string) ob_get_clean();
		};
		return $render();
	}

	/**
	 * Include con scope aislado devolviendo el valor de retorno del archivo.
	 *
	 * @param string               $path Ruta absoluta al archivo.
	 * @param array<string, mixed> $vars Variables que la plantilla recibe como $vars.
	 * @return mixed
	 */
	private static function scoped_include_returning( string $path, array $vars ) {
		$render = static function () use ( $path, $vars ) {
			return include $path;
		};
		return $render();
	}
}
