/**
 * SheetSync for CF7 – lead source capture (frontend).
 *
 * Reads UTM parameters and ad click IDs from the landing page URL, remembers
 * them in localStorage (only when allowed) and adds them to every Contact Form 7
 * form as the hidden field configured in ssCf7Attr.field when it is submitted.
 * No cookies, no network requests.
 */
( function () {
	'use strict';

	var cfg = window.ssCf7Attr;
	if ( ! cfg || ! cfg.field || ! window.URLSearchParams ) {
		return;
	}

	var STORAGE_KEY = 'ss_cf7_attribution';
	var MAX_AGE = ( parseInt( cfg.days, 10 ) || 0 ) * 86400000;

	/* Browser storage is used only when a duration is set and, if required, with marketing consent (WP Consent API). */
	var canStore = function () {
		if ( ! MAX_AGE ) {
			return false;
		}
		if ( cfg.consent ) {
			return typeof window.wp_has_consent === 'function' && !! window.wp_has_consent( 'marketing' );
		}
		return true;
	};

	var read = function () {
		try {
			var data = JSON.parse( window.localStorage.getItem( STORAGE_KEY ) || 'null' );
			if ( data && data.ts && Date.now() - data.ts < MAX_AGE ) {
				return data;
			}
			window.localStorage.removeItem( STORAGE_KEY );
		} catch ( e ) {}
		return null;
	};

	var write = function ( data ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( data ) );
		} catch ( e ) {}
	};

	/* Origin + path only: query strings may contain personal data. */
	var cleanUrl = function ( url ) {
		try {
			var u = new URL( url );
			return ( u.origin + u.pathname ).slice( 0, 500 );
		} catch ( e ) {
			return '';
		}
	};

	var externalReferrer = function () {
		if ( ! document.referrer ) {
			return '';
		}
		try {
			var host = new URL( document.referrer ).hostname.replace( /^www\./, '' );
			return host === window.location.hostname.replace( /^www\./, '' ) ? '' : cleanUrl( document.referrer );
		} catch ( e ) {
			return '';
		}
	};

	/* What this page view brings: tracking parameters and/or an external referrer. */
	var current = ( function () {
		var query = new URLSearchParams( window.location.search );
		var touch = { p: {}, landing: cleanUrl( window.location.href ), ref: externalReferrer(), ts: Date.now(), tagged: false };

		( cfg.params || [] ).forEach( function ( key ) {
			var value = query.get( key );
			if ( value ) {
				touch.p[ key ] = value.slice( 0, 300 );
				touch.tagged = true;
			}
		} );

		return touch;
	} )();

	var stored = canStore() ? read() : null;
	var touch;

	if ( current.tagged ) {
		// A new campaign click replaces the stored one, unless the model is "first click".
		touch = 'first' === cfg.model && stored ? stored : current;
	} else if ( stored ) {
		// Direct visits and internal navigation keep the stored campaign.
		touch = stored;
	} else {
		touch = current;
	}

	var remember = function () {
		if ( touch === current && ( current.tagged || current.ref ) && canStore() && ! ( 'first' === cfg.model && read() ) ) {
			write( current );
		}
	};
	remember();

	// Consent given later on the same page (WP Consent API).
	document.addEventListener( 'wp_listen_for_consent_change', remember );

	var fill = function ( form ) {
		var input = form.querySelector( 'input[name="' + cfg.field + '"]' );
		if ( ! input ) {
			input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = cfg.field;
			form.appendChild( input );
		}
		input.value = JSON.stringify( { v: 1, p: touch.p, landing: touch.landing, ref: touch.ref } );
	};

	var fillAll = function () {
		Array.prototype.forEach.call( document.querySelectorAll( 'form.wpcf7-form' ), fill );
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', fillAll );
	} else {
		fillAll();
	}

	// Capture phase: runs before Contact Form 7 reads the form (also for forms added later).
	document.addEventListener(
		'submit',
		function ( event ) {
			var form = event.target;
			if ( form && form.matches && form.matches( 'form.wpcf7-form' ) ) {
				fill( form );
			}
		},
		true
	);
} )();
