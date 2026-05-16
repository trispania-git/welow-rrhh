/**
 * Welow RRHH — JS del módulo Fichajes (widget de Fichar).
 *
 * Engancha los botones data-event-type del tab "Fichar". Si require_geo
 * está activo, pide al navegador la geolocalización antes de POSTear al
 * endpoint REST /welow-rrhh/v1/punches.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.welowRrhh === 'undefined' ) {
		return;
	}

	const config = window.welowRrhh;
	const REST = config.restUrl;
	const NONCE = config.restNonce;

	function showFeedback( $container, message, type ) {
		const className = 'welow-tt__feedback-msg welow-tt__feedback-msg--' + ( type || 'info' );
		$container.html( '<p class="' + className + '">' + $( '<div/>' ).text( message ).html() + '</p>' );
	}

	function getCoords( requireGeo ) {
		return new Promise( function ( resolve ) {
			if ( ! requireGeo || ! navigator.geolocation ) {
				resolve( {} );
				return;
			}
			navigator.geolocation.getCurrentPosition(
				function ( pos ) {
					resolve( {
						latitude: pos.coords.latitude,
						longitude: pos.coords.longitude
					} );
				},
				function () {
					// El usuario denegó: enviamos sin coords; el guard decidirá.
					resolve( {} );
				},
				{ enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
			);
		} );
	}

	function postPunch( eventType, coords ) {
		const payload = Object.assign( { event_type: eventType }, coords );
		return $.ajax( {
			url: REST + 'punches',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify( payload ),
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', NONCE );
			},
			dataType: 'json'
		} );
	}

	$( document ).on( 'click', '.welow-tt__actions .welow-tt__btn', function ( e ) {
		e.preventDefault();
		const $btn = $( this );
		const $actions = $btn.closest( '.welow-tt__actions' );
		const $feedback = $actions.siblings( '.welow-tt__feedback' );
		const requireGeo = $actions.data( 'require-geo' ) === 1 || $actions.data( 'require-geo' ) === '1';
		const eventType = $btn.data( 'event-type' );

		$btn.prop( 'disabled', true ).addClass( 'is-loading' );
		showFeedback( $feedback, '…', 'info' );

		getCoords( requireGeo ).then( function ( coords ) {
			postPunch( eventType, coords )
				.done( function ( res ) {
					const msg = ( res && res.data && res.data.entry )
						? 'Fichaje registrado. Recargando…'
						: 'OK';
					showFeedback( $feedback, msg, 'success' );
					setTimeout( function () { window.location.reload(); }, 600 );
				} )
				.fail( function ( xhr ) {
					let msg = 'Error al registrar el fichaje.';
					if ( xhr.responseJSON ) {
						const body = xhr.responseJSON;
						if ( body.error && body.error.message ) {
							msg = body.error.message;
						} else if ( body.message ) {
							msg = body.message;
						}
					}
					showFeedback( $feedback, msg, 'error' );
					$btn.prop( 'disabled', false ).removeClass( 'is-loading' );
				} );
		} );
	} );
} )( jQuery );
