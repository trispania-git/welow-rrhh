<?php
/**
 * Versión texto plano por defecto. Variables: $vars (payload).
 *
 * @package Welow\RRHH
 */

defined( 'ABSPATH' ) || exit;

$title     = isset( $vars['title'] ) ? (string) $vars['title'] : __( 'Aviso de Welow RRHH', 'welow-rrhh' );
$body_text = isset( $vars['body'] ) ? (string) $vars['body'] : '';
$cta_url   = isset( $vars['action_url'] ) ? (string) $vars['action_url'] : '';
$cta_label = isset( $vars['action_label'] ) ? (string) $vars['action_label'] : __( 'Ver detalle', 'welow-rrhh' );

echo $title . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — texto plano, no HTML.

if ( '' !== $body_text ) {
	echo wp_strip_all_tags( $body_text ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

if ( '' !== $cta_url ) {
	echo $cta_label . ': ' . $cta_url . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
