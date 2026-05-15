<?php
/**
 * Mensaje + enlace de login cuando el usuario no está autenticado.
 *
 * Variables:
 *   $vars['login_url'] string
 *   $vars['message']   string (opcional)
 *
 * @package Welow\RRHH\Frontend
 */

defined( 'ABSPATH' ) || exit;

$login_url = isset( $vars['login_url'] ) ? (string) $vars['login_url'] : '';
$message   = isset( $vars['message'] ) ? (string) $vars['message'] : __( 'Necesitas iniciar sesión para acceder a tu área.', 'welow-rrhh' );
?>
<div class="welow-rrhh__dashboard welow-rrhh__dashboard--login-required">
	<div class="welow-rrhh__notice welow-rrhh__notice--info">
		<p><?php echo esc_html( $message ); ?></p>
		<?php if ( '' !== $login_url ) : ?>
			<p>
				<a class="welow-rrhh__button" href="<?php echo esc_url( $login_url ); ?>">
					<?php esc_html_e( 'Iniciar sesión', 'welow-rrhh' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
</div>
