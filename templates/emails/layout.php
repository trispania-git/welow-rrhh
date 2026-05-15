<?php
/**
 * Layout HTML común para emails de Welow RRHH.
 *
 * Variable disponible:
 *   - $vars: array con todos los datos. Específicamente:
 *     - $vars['body']    string  Cuerpo HTML específico del tipo.
 *     - $vars['subject'] string  Asunto del email.
 *     - $vars['brand']   array{name,logo_url,primary_color}  Branding white-label.
 *     - resto: payload original de la notificación.
 *
 * @package Welow\RRHH
 */

defined( 'ABSPATH' ) || exit;

$layout_subject = isset( $vars['subject'] ) ? (string) $vars['subject'] : '';
$layout_body    = isset( $vars['body'] ) ? (string) $vars['body'] : '';
$layout_brand   = isset( $vars['brand'] ) && is_array( $vars['brand'] ) ? $vars['brand'] : array(
	'name'          => 'Welow RRHH',
	'logo_url'      => '',
	'primary_color' => '#2271b1',
);
?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $layout_subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#1d2327;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:24px 0;">
		<tr>
			<td align="center">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;">
					<tr>
						<td style="background:<?php echo esc_attr( (string) ( $layout_brand['primary_color'] ?? '#2271b1' ) ); ?>;padding:16px 24px;color:#ffffff;">
							<?php if ( ! empty( $layout_brand['logo_url'] ) ) : ?>
								<img src="<?php echo esc_url( (string) $layout_brand['logo_url'] ); ?>" alt="<?php echo esc_attr( (string) ( $layout_brand['name'] ?? '' ) ); ?>" style="max-height:36px;vertical-align:middle;" />
							<?php else : ?>
								<strong style="font-size:18px;"><?php echo esc_html( (string) ( $layout_brand['name'] ?? '' ) ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td style="padding:24px;">
							<?php echo wp_kses_post( $layout_body ); ?>
						</td>
					</tr>
					<tr>
						<td style="padding:16px 24px;background:#fafafa;border-top:1px solid #e0e0e0;font-size:12px;color:#777;">
							<?php
							printf(
								/* translators: %s: brand name. */
								esc_html__( 'Notificación automática de %s. Si lo recibiste por error, ignora este mensaje.', 'welow-rrhh' ),
								esc_html( (string) ( $layout_brand['name'] ?? '' ) )
							);
							?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
