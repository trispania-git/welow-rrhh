<?php
/**
 * Asunto por defecto. Variables: $vars (payload).
 *
 * @package Welow\RRHH
 */

defined( 'ABSPATH' ) || exit;

$title = isset( $vars['title'] ) ? (string) $vars['title'] : __( 'Notificación de Welow RRHH', 'welow-rrhh' );
return $title;
