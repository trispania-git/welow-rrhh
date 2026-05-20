<?php
/**
 * PHPUnit bootstrap del plugin Welow RRHH.
 *
 * Carga el test framework de WordPress (wp-phpunit) y registra el plugin
 * para que esté activo durante los tests de integración.
 *
 * Configuración (en orden de precedencia):
 *   1. Variable de entorno WP_TESTS_DIR (apuntando a la carpeta `tests/phpunit`
 *      del repositorio de WordPress, o a la del paquete wp-phpunit/wp-phpunit).
 *   2. Path por defecto en /tmp/wordpress-tests-lib (estilo bin/install-wp-tests.sh).
 *
 * Los tests "unit" puros que no requieran WP deben evitar el ciclo de carga
 * (no se incluye nada de WP si están en tests/unit/).
 *
 * @package Welow\RRHH\Tests
 */

declare( strict_types=1 );

// Sólo cargamos WordPress para los tests de integración. Si PHPUnit ejecuta
// la suite "unit" exclusivamente, podemos saltar todo esto y dejar el
// autoload de Composer disponible para los tests puros.
require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/unit/bootstrap-stubs.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$candidate = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	if ( is_dir( $candidate ) ) {
		$_tests_dir = $candidate;
	}
}
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Si el framework de tests de WP no existe, sólo los tests "unit" puros
// podrán ejecutarse. PHPUnit fallará al descubrir los integration tests
// si no hay WP_UnitTestCase disponible — devolvemos pista clara.
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"[Welow RRHH tests] No se encontró el WordPress test framework en {$_tests_dir}.\n"
		. "Instálalo con `composer require --dev wp-phpunit/wp-phpunit` o ejecuta\n"
		. "`bin/install-wp-tests.sh wordpress_test root '' localhost latest` y reintenta.\n"
		. "(Los tests/unit puros sí pueden ejecutarse sin esto.)\n\n"
	);
	return;
}

require_once $_tests_dir . '/includes/functions.php';

// Carga del plugin justo antes de que WP se inicialice (estándar de WP tests).
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/welow-rrhh.php';
	}
);

// Arranca el test bootstrap de WP.
require $_tests_dir . '/includes/bootstrap.php';
