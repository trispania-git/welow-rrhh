/**
 * Welow RRHH — JS del módulo Vacaciones (tabs Mis vacaciones + Aprobaciones).
 *
 * - Submit del formulario: POST /vacations/requests.
 * - Botón Cancelar: PATCH /vacations/requests/{id} action=cancel.
 * - Botones Aprobar/Rechazar: PATCH action=approve|reject.
 *
 * Reusa window.welowRrhh (restUrl + restNonce) ya inyectado por el Core.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.welowRrhh === 'undefined' ) {
		return;
	}

	const cfg    = window.welowRrhh;
	const REST   = cfg.restUrl;
	const NONCE  = cfg.restNonce;

	function feedback( $node, message, type ) {
		const cls = 'welow-vac__feedback-msg welow-vac__feedback-msg--' + ( type || 'info' );
		$node.html( '<span class="' + cls + '">' + $( '<div/>' ).text( message ).html() + '</span>' );
	}

	function reload( delay ) {
		setTimeout( function () { window.location.reload(); }, delay || 600 );
	}

	function patchRequest( id, action, note ) {
		return $.ajax( {
			url: REST + 'vacations/requests/' + id,
			method: 'PATCH',
			contentType: 'application/json',
			data: JSON.stringify( { action: action, note: note || '' } ),
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', NONCE );
			},
			dataType: 'json'
		} );
	}

	function errorMessage( xhr, fallback ) {
		if ( xhr && xhr.responseJSON ) {
			const body = xhr.responseJSON;
			if ( body.error && body.error.message ) {
				return body.error.message;
			}
			if ( body.message ) {
				return body.message;
			}
		}
		return fallback;
	}

	// 1. Crear solicitud (formulario en MyVacationsTab).
	$( document ).on( 'submit', 'form.welow-vac__form[data-action="create"]', function ( e ) {
		e.preventDefault();
		const $form    = $( this );
		const $feedback = $form.find( '.welow-vac__feedback' );
		const $submit   = $form.find( 'button[type="submit"]' );

		const payload = {
			start_date:     $form.find( '[name="start_date"]' ).val(),
			end_date:       $form.find( '[name="end_date"]' ).val(),
			start_half_day: $form.find( '[name="start_half_day"]' ).is( ':checked' ),
			end_half_day:   $form.find( '[name="end_half_day"]' ).is( ':checked' ),
			reason:         $form.find( '[name="reason"]' ).val()
		};

		$submit.prop( 'disabled', true );
		feedback( $feedback, 'Enviando…', 'info' );

		$.ajax( {
			url: REST + 'vacations/requests',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify( payload ),
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', NONCE );
			},
			dataType: 'json'
		} )
			.done( function () {
				feedback( $feedback, 'Solicitud enviada. Recargando…', 'success' );
				reload();
			} )
			.fail( function ( xhr ) {
				feedback( $feedback, errorMessage( xhr, 'No se pudo enviar la solicitud.' ), 'error' );
				$submit.prop( 'disabled', false );
			} );
	} );

	// 2. Cancelar (en Mis vacaciones).
	$( document ).on( 'click', 'button[data-action="cancel"]', function () {
		const $btn = $( this );
		const id   = $btn.data( 'request-id' );
		if ( ! window.confirm( '¿Cancelar esta solicitud?' ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		patchRequest( id, 'cancel' )
			.done( function () { reload(); } )
			.fail( function ( xhr ) {
				window.alert( errorMessage( xhr, 'No se pudo cancelar.' ) );
				$btn.prop( 'disabled', false );
			} );
	} );

	// 3. Aprobar / rechazar (en Aprobaciones).
	$( document ).on( 'click', 'button[data-action="approve"], button[data-action="reject"]', function () {
		const $btn   = $( this );
		const id     = $btn.data( 'request-id' );
		const action = $btn.data( 'action' );
		let note     = '';
		if ( 'reject' === action ) {
			note = window.prompt( 'Motivo del rechazo (opcional):', '' );
			if ( null === note ) {
				return;
			}
		}
		$btn.prop( 'disabled', true );
		patchRequest( id, action, note )
			.done( function () { reload(); } )
			.fail( function ( xhr ) {
				window.alert( errorMessage( xhr, 'No se pudo procesar la decisión.' ) );
				$btn.prop( 'disabled', false );
			} );
	} );
} )( jQuery );
