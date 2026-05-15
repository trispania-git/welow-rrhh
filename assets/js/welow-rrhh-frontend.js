/**
 * Welow RRHH — JS frontend.
 *
 * De momento sólo expone el namespace global y captura el nonce.
 * Las interacciones REST se añadirán cuando los módulos las requieran.
 */
( function ( $ ) {
	'use strict';

	window.WelowRrhh = window.WelowRrhh || {};
	window.WelowRrhh.config = window.welowRrhh || {};

	$( function () {
		// Hook ready para futuras inicializaciones de módulos.
		$( document ).trigger( 'welow-rrhh:ready', [ window.WelowRrhh.config ] );
	} );
} )( jQuery );
