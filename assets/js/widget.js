/**
 * Nexxen Asistent — logika widgeta.
 *
 * Komunicira ISKLJUČIVO sa našim WordPress endpointom (NexxenConfig.restUrl).
 * Anthropic API ključ NIJE ovde i nikada ne stiže u browser.
 *
 * Tok:
 *   1) Na otvaranje uzima svež "nonce" sa /token (otporno na keširanje stranice).
 *   2) Šalje istoriju razgovora na /chat.
 *   3) Pri grešci NE kvari istoriju — korisnik može da pokuša ponovo.
 */
( function() {
	'use strict';

	// Konfiguracija dolazi iz PHP-a preko wp_localize_script.
	if ( typeof window.NexxenConfig === 'undefined' ) {
		return;
	}
	var CFG = window.NexxenConfig;
	var S   = CFG.strings || {};

	// Stanje razgovora.
	var messages  = [];     // [{role:'user'|'assistant', content:'...'}]
	var nonce     = '';     // svež CSRF token sa servera
	var sending   = false;  // da li je poziv u toku
	var sessionId = getSessionId();

	// Reference na DOM elemente (popunjavaju se u build()).
	var els = {};

	/* ------------------------------------------------------------------ */
	/* Inicijalizacija                                                     */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', build );

	function build() {
		var root = document.createElement( 'div' );
		root.className = 'nexxen-widget';

		root.innerHTML =
			'<button class="nexxen-bubble" type="button" aria-label="' + esc( S.openAria || 'Otvori chat' ) + '">' +
				'<svg viewBox="0 0 24 24"><path d="M12 3C6.5 3 2 6.6 2 11c0 2.2 1.1 4.2 3 5.6V21l3.5-2c1.1.3 2.3.5 3.5.5 5.5 0 10-3.6 10-8s-4.5-8-10-8z"/></svg>' +
			'</button>' +
			'<div class="nexxen-panel nexxen-hidden" role="dialog" aria-label="' + esc( CFG.title || 'Asistent' ) + '">' +
				'<div class="nexxen-header">' +
					'<span class="nexxen-title">' + esc( CFG.title || 'Asistent' ) + '</span>' +
					'<button class="nexxen-close" type="button" aria-label="' + esc( S.closeAria || 'Zatvori chat' ) + '">&times;</button>' +
				'</div>' +
				'<div class="nexxen-messages"></div>' +
				'<div class="nexxen-input-area">' +
					'<textarea class="nexxen-input" rows="1" placeholder="' + esc( S.placeholder || 'Napišite poruku…' ) + '" maxlength="' + ( CFG.maxMessageLen || 1000 ) + '"></textarea>' +
					'<button class="nexxen-send" type="button">' + esc( S.send || 'Pošalji' ) + '</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild( root );

		els.bubble   = root.querySelector( '.nexxen-bubble' );
		els.panel    = root.querySelector( '.nexxen-panel' );
		els.close    = root.querySelector( '.nexxen-close' );
		els.messages = root.querySelector( '.nexxen-messages' );
		els.input    = root.querySelector( '.nexxen-input' );
		els.send     = root.querySelector( '.nexxen-send' );

		// Događaji.
		els.bubble.addEventListener( 'click', togglePanel );
		els.close.addEventListener( 'click', togglePanel );
		els.send.addEventListener( 'click', onSend );
		els.input.addEventListener( 'keydown', function( e ) {
			// Enter šalje, Shift+Enter pravi novi red.
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				onSend();
			}
		} );
		// Auto-rast polja za unos.
		els.input.addEventListener( 'input', function() {
			els.input.style.height = 'auto';
			els.input.style.height = Math.min( els.input.scrollHeight, 120 ) + 'px';
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Otvaranje / zatvaranje                                              */
	/* ------------------------------------------------------------------ */

	function togglePanel() {
		var opening = els.panel.classList.contains( 'nexxen-hidden' );
		els.panel.classList.toggle( 'nexxen-hidden' );

		if ( opening ) {
			// Prikaži pozdrav samo prvi put.
			if ( 0 === messages.length && CFG.greeting ) {
				addBubble( 'bot', CFG.greeting );
			}
			// Uzmi svež token (ako ga nemamo).
			if ( ! nonce ) {
				fetchToken();
			}
			els.input.focus();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Token (CSRF zaštita, otporno na keširanje)                          */
	/* ------------------------------------------------------------------ */

	function fetchToken() {
		return fetch( CFG.tokenUrl, { method: 'GET', credentials: 'same-origin' } )
			.then( function( r ) { return r.json(); } )
			.then( function( j ) { if ( j && j.nonce ) { nonce = j.nonce; } } )
			.catch( function() { /* tiho; pokušaće ponovo pri slanju */ } );
	}

	/* ------------------------------------------------------------------ */
	/* Slanje poruke                                                       */
	/* ------------------------------------------------------------------ */

	function onSend() {
		if ( sending ) {
			return;
		}
		var text = ( els.input.value || '' ).trim();
		if ( ! text ) {
			return;
		}

		// Dodaj korisnikovu poruku u UI i istoriju.
		addBubble( 'user', text );
		messages.push( { role: 'user', content: text } );
		els.input.value = '';
		els.input.style.height = 'auto';

		sendToServer();
	}

	function sendToServer() {
		sending = true;
		els.send.disabled = true;
		var typing = showTyping();

		// Ako nemamo nonce, prvo ga uzmi, pa onda šalji.
		var ready = nonce ? Promise.resolve() : fetchToken();

		ready.then( function() {
			return fetch( CFG.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-Nexxen-Nonce': nonce
				},
				body: JSON.stringify( {
					messages: messages,
					session_id: sessionId
				} )
			} );
		} ).then( function( res ) {
			return res.json().then( function( data ) {
				return { status: res.status, data: data };
			} );
		} ).then( function( result ) {
			removeTyping( typing );

			if ( result.data && result.data.error ) {
				handleError( result.data );
				return;
			}

			var reply = ( result.data && result.data.reply ) ? result.data.reply : '';
			if ( reply ) {
				addBubble( 'bot', reply );
				// Tek sada upisujemo odgovor u istoriju (uspešan ciklus).
				messages.push( { role: 'assistant', content: reply } );
			} else {
				handleError( { message: S.error } );
			}
		} ).catch( function() {
			// Mrežna greška na strani browsera.
			removeTyping( typing );
			handleError( { message: S.error } );
		} ).finally( function() {
			sending = false;
			els.send.disabled = false;
			els.input.focus();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Greške (ne kvare istoriju — nudi ponovni pokušaj + kontakt)         */
	/* ------------------------------------------------------------------ */

	function handleError( data ) {
		// Ako je nonce istekao, obriši ga da se sledeći put uzme svež.
		if ( data && 'bad_nonce' === data.code ) {
			nonce = '';
		}

		var msg = ( data && data.message ) ? data.message : ( S.error || 'Greška.' );

		var wrap = document.createElement( 'div' );
		wrap.className = 'nexxen-msg nexxen-msg-error';

		var p = document.createElement( 'div' );
		p.textContent = msg;
		wrap.appendChild( p );

		// Rezervni kontakt (email).
		var contact = ( data && data.fallback ) ? data.fallback : CFG.fallback;
		if ( contact ) {
			var c = document.createElement( 'div' );
			c.style.marginTop = '6px';
			c.innerHTML = 'Kontakt: <a href="mailto:' + esc( contact ) + '">' + esc( contact ) + '</a>';
			wrap.appendChild( c );
		}

		// Dugme "Pokušaj ponovo" — ponovo šalje istu istoriju (ništa nije izgubljeno).
		var retry = document.createElement( 'button' );
		retry.className = 'nexxen-retry';
		retry.type = 'button';
		retry.textContent = S.retry || 'Pokušaj ponovo';
		retry.addEventListener( 'click', function() {
			wrap.remove();
			sendToServer();
		} );
		wrap.appendChild( retry );

		els.messages.appendChild( wrap );
		scrollDown();
	}

	/* ------------------------------------------------------------------ */
	/* Pomoćne funkcije za prikaz                                          */
	/* ------------------------------------------------------------------ */

	function addBubble( who, text ) {
		var div = document.createElement( 'div' );
		div.className = 'nexxen-msg ' + ( 'user' === who ? 'nexxen-msg-user' : 'nexxen-msg-bot' );
		div.textContent = text; // textContent = bezbedno (bez HTML injekcije)
		els.messages.appendChild( div );
		scrollDown();
	}

	function showTyping() {
		var t = document.createElement( 'div' );
		t.className = 'nexxen-typing';
		t.innerHTML = '<span></span><span></span><span></span>';
		els.messages.appendChild( t );
		scrollDown();
		return t;
	}

	function removeTyping( t ) {
		if ( t && t.parentNode ) {
			t.parentNode.removeChild( t );
		}
	}

	function scrollDown() {
		els.messages.scrollTop = els.messages.scrollHeight;
	}

	/* ------------------------------------------------------------------ */
	/* Sitnice                                                             */
	/* ------------------------------------------------------------------ */

	// Jednostavan ID sesije (čuva se dok je tab otvoren).
	function getSessionId() {
		try {
			var id = sessionStorage.getItem( 'nexxen_sid' );
			if ( ! id ) {
				id = 'sid-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2, 10 );
				sessionStorage.setItem( 'nexxen_sid', id );
			}
			return id;
		} catch ( e ) {
			// Ako sessionStorage nije dostupan (privatni režim).
			return 'sid-' + Date.now();
		}
	}

	// Escape za atribute (sprečava XSS u aria/oznakama).
	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

} )();
