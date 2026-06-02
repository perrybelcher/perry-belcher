/**
 * Lodestar AI intake — calls the enrich endpoint and pre-fills the form.
 *
 * Plain JS, no build step. The proposal is only ever written into the form
 * inputs; nothing is saved until the user submits (human-in-the-loop).
 */
( function () {
	'use strict';

	var cfg = window.lodestarIntake || {};

	function setStatus( box, msg ) {
		var el = box.querySelector( '.lodestar-intake__status' );
		if ( el ) {
			el.textContent = msg;
		}
	}

	function fill( name, value ) {
		if ( value === undefined || value === null || value === '' ) {
			return;
		}
		var el = document.querySelector( '[name="' + name + '"]' );
		if ( el ) {
			el.value = value;
		}
	}

	function applyProposal( data ) {
		fill( 'listing_title', data.title );
		fill( 'listing_content', data.description );

		if ( data.fields && typeof data.fields === 'object' ) {
			Object.keys( data.fields ).forEach( function ( key ) {
				var value = data.fields[ key ];
				if ( Array.isArray( value ) ) {
					value.forEach( function ( v ) {
						var box = document.querySelector( '[name="lodestar_fields[' + key + '][]"][value="' + v + '"]' );
						if ( box ) {
							box.checked = true;
						}
					} );
				} else {
					fill( 'lodestar_fields[' + key + ']', value );
				}
			} );
		}
	}

	function request( box, input ) {
		if ( ! cfg.ajaxUrl || ! input ) {
			return;
		}
		setStatus( box, 'Thinking…' );

		var body = new URLSearchParams();
		body.append( 'action', 'lodestar_enrich' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'type', cfg.type || '' );
		body.append( 'input', input );

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					applyProposal( res.data || {} );
					setStatus( box, 'Draft filled in — review before submitting.' );
				} else {
					setStatus( box, ( res && res.data && res.data.message ) || 'Could not generate a suggestion.' );
				}
			} )
			.catch( function () {
				setStatus( box, 'Request failed.' );
			} );
	}

	function init( box ) {
		var button = box.querySelector( '.lodestar-intake__go' );
		var input = box.querySelector( '.lodestar-intake__input' );
		if ( button && input ) {
			button.addEventListener( 'click', function () {
				request( box, input.value.trim() );
			} );
		}
	}

	function boot() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-lodestar-intake]' ),
			init
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
