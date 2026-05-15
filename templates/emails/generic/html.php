<?php
/**
 * Cuerpo HTML por defecto.
 *
 * Variables: $vars (payload).
 *
 * @package Welow\RRHH
 */

defined( 'ABSPATH' ) || exit;

$title     = isset( $vars['title'] ) ? (string) $vars['title'] : __( 'Aviso', 'welow-rrhh' );
$body_text = isset( $vars['body'] ) ? (string) $vars['body'] : '';
$cta_url   = isset( $vars['action_url'] ) ? (string) $vars['action_url'] : '';
$cta_label = isset( $vars['action_label'] ) ? (string) $vars['action_label'] : __( 'Ver detalle', 'welow-rrhh' );
?>
<h2 style="margin:0 0 12px;font-size:20px;color:#1d2327;"><?php echo esc_html( $title ); ?></h2>
<?php if ( '' !== $body_text ) : ?>
	<div style="font-size:14px;line-height:1.5;color:#333;">
		<?php echo wp_kses_post( wpautop( $body_text ) ); ?>
	</div>
<?php endif; ?>

<?php if ( '' !== $cta_url ) : ?>
	<p style="margin:20px 0 0;">
		<a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;padding:10px 18px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;">
			<?php echo esc_html( $cta_label ); ?>
		</a>
	</p>
<?php endif; ?>
