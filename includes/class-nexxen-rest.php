<?php
/**
 * REST API endpoint (backend proxy ka Anthropic-u).
 *
 * Rute:
 *   GET  /wp-json/nexxen/v1/token  → vraća svež nonce (zaštita od CSRF, otporno na keširanje)
 *   POST /wp-json/nexxen/v1/chat   → prima poruke, prosleđuje Anthropic-u, vraća odgovor
 *
 * Bezbednost: ključ je SAMO na serveru; widget nikada ne komunicira sa Anthropic-om direktno.
 *
 * @package Nexxen_Asistent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nexxen_REST {

	const NAMESPACE      = 'nexxen/v1';
	const ANTHROPIC_URL  = 'https://api.anthropic.com/v1/messages';
	const API_VERSION    = '2023-06-01'; // Zvanični "anthropic-version" header.
	const MAX_RETRIES    = 3;            // Ukupno pokušaja (1 + 2 ponavljanja) pri grešci servera.
	const HTTP_TIMEOUT   = 20;           // Timeout po pozivu (sekunde) — bezbedno ispod PHP limita.

	/** Registruj rute. */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		// Endpoint za svež nonce.
		register_rest_route(
			self::NAMESPACE,
			'/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_token' ),
				'permission_callback' => array( $this, 'check_origin' ),
			)
		);

		// Glavni chat endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_origin' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * BEZBEDNOST: provera porekla (CORS / CSRF)
	 * ------------------------------------------------------------------ */

	/**
	 * Dozvoli zahtev samo sa našeg domena.
	 * Ovo je glavna zaštita koja radi i za neulogovane posetioce i otporna je na keširanje.
	 *
	 * @return bool|WP_Error
	 */
	public function check_origin() {
		if ( ! (int) nexxen_get_option( 'widget_enabled', 1 ) ) {
			return new WP_Error( 'nexxen_disabled', 'Asistent je trenutno isključen.', array( 'status' => 403 ) );
		}

		$allowed_host = wp_parse_url( nexxen_get_option( 'allowed_origin', home_url() ), PHP_URL_HOST );
		$allowed_host = $allowed_host ? strtolower( $allowed_host ) : '';

		// Poreklo zahteva: Origin header (kod POST-a), pa Referer kao rezerva.
		$origin = '';
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ), PHP_URL_HOST );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$origin = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_HOST );
		}
		$origin = $origin ? strtolower( $origin ) : '';

		// Ako nema porekla (npr. neki preuzimači), a host se ne poklapa → odbij.
		if ( $allowed_host && $origin && $origin !== $allowed_host ) {
			Nexxen_Logger::warning( 'Odbijen zahtev sa stranog porekla.', array( 'origin' => $origin ) );
			return new WP_Error( 'nexxen_bad_origin', 'Nedozvoljeno poreklo zahteva.', array( 'status' => 403 ) );
		}

		return true;
	}

	/** Pošalji CORS zaglavlja zaključana na naš domen. */
	private function send_cors_headers() {
		$allowed_origin = esc_url_raw( nexxen_get_option( 'allowed_origin', home_url() ) );
		// Skini završni "/" da se tačno poklopi sa Origin headerom.
		$allowed_origin = untrailingslashit( $allowed_origin );
		header( 'Access-Control-Allow-Origin: ' . $allowed_origin );
		header( 'Vary: Origin' );
		header( 'Access-Control-Allow-Methods: POST, GET' );
		header( 'Access-Control-Allow-Headers: Content-Type, X-Nexxen-Nonce' );
	}

	/* ---------------------------------------------------------------------
	 * /token — svež nonce
	 * ------------------------------------------------------------------ */

	public function handle_token( $request ) {
		$this->send_cors_headers();
		nocache_headers(); // Spreči keširanje nonce-a.
		return new WP_REST_Response(
			array( 'nonce' => wp_create_nonce( 'nexxen_chat' ) ),
			200
		);
	}

	/* ---------------------------------------------------------------------
	 * /chat — glavni tok
	 * ------------------------------------------------------------------ */

	public function handle_chat( WP_REST_Request $request ) {
		$this->send_cors_headers();

		$contact = sanitize_email( nexxen_get_option( 'fallback_contact', get_option( 'admin_email' ) ) );

		// --- 1) Provera nonce-a (lagana CSRF zaštita; poreklo je već provereno) ---
		$nonce = $request->get_header( 'x-nexxen-nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'nexxen_chat' ) ) {
			// Nonce je istekao (npr. zbog keširanja stranice). Widget treba da osveži token.
			return $this->error_response(
				'Sesija je istekla. Osvežite stranicu pa pokušajte ponovo.',
				$contact,
				403,
				'bad_nonce'
			);
		}

		// --- 2) Provera API ključa ---
		$api_key = nexxen_get_api_key();
		if ( ! $api_key ) {
			Nexxen_Logger::error( 'Anthropic API ključ nije podešen.' );
			return $this->error_response(
				'Asistent trenutno nije dostupan. Molimo kontaktirajte nas direktno.',
				$contact,
				503,
				'no_api_key'
			);
		}

		// --- 3) Učitavanje i validacija ulaza ---
		$params   = $request->get_json_params();
		$messages = isset( $params['messages'] ) && is_array( $params['messages'] ) ? $params['messages'] : array();
		$session  = isset( $params['session_id'] ) ? sanitize_text_field( $params['session_id'] ) : '';

		if ( empty( $messages ) ) {
			return $this->error_response( 'Prazna poruka.', $contact, 400, 'empty' );
		}

		$max_per_conv = (int) nexxen_get_option( 'max_messages_per_conversation', 40 );
		if ( count( $messages ) > $max_per_conv ) {
			return $this->error_response(
				'Razgovor je predugačak. Molimo započnite novi razgovor.',
				$contact,
				400,
				'too_long'
			);
		}

		$clean_messages = $this->sanitize_messages( $messages );
		if ( empty( $clean_messages ) ) {
			return $this->error_response( 'Neispravan format poruka.', $contact, 400, 'invalid' );
		}

		// --- 4) Rate limiting (po IP-u i sesiji) ---
		$ip   = $this->get_client_ip();
		$rate = Nexxen_Rate_Limiter::check( $ip, $session );
		if ( ! $rate['allowed'] ) {
			$msg = ( 'session_limit' === $rate['reason'] )
				? 'Dostigli ste maksimalan broj poruka u ovom razgovoru. Hvala na razumevanju!'
				: 'Malo brže pišete od mene 🙂 Sačekajte par sekundi pa pokušajte ponovo.';
			return $this->error_response( $msg, $contact, 429, $rate['reason'], $rate['retry_after'] );
		}

		// --- 5) Dnevni limit potrošnje ---
		if ( Nexxen_Rate_Limiter::daily_limit_reached() ) {
			Nexxen_Logger::warning( 'Dnevni limit potrošnje je dostignut — zahtev odbijen.' );
			return $this->error_response(
				'Asistent je trenutno nedostupan. Molimo kontaktirajte nas direktno.',
				$contact,
				503,
				'daily_limit'
			);
		}

		// --- 6) Priprema poziva ka Anthropic-u ---
		$payload = array(
			'model'      => sanitize_text_field( nexxen_get_option( 'model', 'claude-opus-4-8' ) ),
			'max_tokens' => (int) nexxen_get_option( 'max_tokens', 1024 ),
			'system'     => (string) nexxen_get_option( 'system_prompt', '' ),
			'messages'   => $clean_messages,
		);

		// --- 7) Poziv (sa timeout-om i retry-jem) ---
		$result = $this->call_anthropic( $payload, $api_key );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$msg  = ( 'rate_limited' === $code )
				? 'Trenutno imamo veliku gužvu. Pokušajte ponovo za koji trenutak.'
				: 'Trenutno imam tehničkih poteškoća. Pokušajte ponovo za koji trenutak ili nas kontaktirajte direktno.';
			// VAŽNO: vraćamo grešku, ali NE kvarimo istoriju — korisnik samo pokuša ponovo.
			return $this->error_response( $msg, $contact, 503, $code );
		}

		// --- 8) Obrada uspešnog odgovora ---
		$reply_text = $result['text'];

		// Zabeleži potrošnju (za dnevni limit i statistiku).
		Nexxen_Rate_Limiter::add_usage(
			$result['input_tokens'],
			$result['output_tokens'],
			$payload['model']
		);

		// Ako je AI potvrdio porudžbinu → obradi je i ukloni [ORDER] blok iz prikaza.
		$order_processed = false;
		if ( Nexxen_Orders::has_order( $reply_text ) ) {
			$order_data = Nexxen_Orders::extract_order( $reply_text );
			if ( $order_data ) {
				Nexxen_Orders::process( $order_data, $ip );
				$order_processed = true;
			}
			$reply_text = Nexxen_Orders::strip_order_block( $reply_text );
		}

		return new WP_REST_Response(
			array(
				'reply'           => $reply_text,
				'order_processed' => $order_processed,
			),
			200
		);
	}

	/* ---------------------------------------------------------------------
	 * Poziv ka Anthropic-u sa retry logikom
	 * ------------------------------------------------------------------ */

	/**
	 * Pozovi Anthropic Messages API. Ponavlja pri 429/5xx i mrežnim greškama.
	 *
	 * @param array  $payload Telo zahteva.
	 * @param string $api_key Ključ.
	 * @return array|WP_Error ['text','input_tokens','output_tokens'] ili WP_Error.
	 */
	private function call_anthropic( $payload, $api_key ) {
		$args = array(
			'timeout' => self::HTTP_TIMEOUT,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
			),
			'body'    => wp_json_encode( $payload ),
		);

		$last_error = null;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_post( self::ANTHROPIC_URL, $args );

			// (a) Mrežna greška (timeout, DNS…) → ponovi.
			if ( is_wp_error( $response ) ) {
				$last_error = new WP_Error( 'network', $response->get_error_message() );
				Nexxen_Logger::warning(
					'Mrežna greška pri pozivu Anthropic-a (pokušaj ' . $attempt . ').',
					array( 'error' => $response->get_error_message() )
				);
				$this->backoff( $attempt );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			// (b) Uspeh.
			if ( 200 === $code ) {
				$data = json_decode( $body, true );
				$text = $this->extract_text( $data );

				if ( '' === $text ) {
					Nexxen_Logger::error( 'Prazan odgovor od Anthropic-a.', array( 'body' => $body ) );
					return new WP_Error( 'empty_reply', 'Prazan odgovor.' );
				}

				return array(
					'text'          => $text,
					'input_tokens'  => isset( $data['usage']['input_tokens'] ) ? (int) $data['usage']['input_tokens'] : 0,
					'output_tokens' => isset( $data['usage']['output_tokens'] ) ? (int) $data['usage']['output_tokens'] : 0,
				);
			}

			// (c) 429 (previše zahteva) ili 5xx (greška servera) → ponovi.
			if ( 429 === $code || $code >= 500 ) {
				$last_error = new WP_Error( 429 === $code ? 'rate_limited' : 'server_error', 'HTTP ' . $code );
				Nexxen_Logger::warning(
					'Anthropic je vratio HTTP ' . $code . ' (pokušaj ' . $attempt . ').',
					array( 'body' => mb_substr( (string) $body, 0, 500 ) )
				);
				// Poštuj "retry-after" header za 429 (ali ograničeno).
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$this->backoff( $attempt, $retry_after );
				continue;
			}

			// (d) 4xx (npr. neispravan ključ, loš zahtev) → nema svrhe ponavljati.
			Nexxen_Logger::error(
				'Anthropic je vratio grešku HTTP ' . $code . '.',
				array( 'body' => mb_substr( (string) $body, 0, 500 ) )
			);
			return new WP_Error( 'client_error', 'HTTP ' . $code );
		}

		// Svi pokušaji iscrpljeni.
		return $last_error ? $last_error : new WP_Error( 'unknown', 'Nepoznata greška.' );
	}

	/**
	 * Pauza između pokušaja (eksponencijalni rast: ~1s, 2s).
	 *
	 * @param int $attempt     Redni broj pokušaja.
	 * @param int $retry_after Predloženo čekanje iz "retry-after" (sekunde).
	 */
	private function backoff( $attempt, $retry_after = 0 ) {
		// Ne pauziraj posle poslednjeg pokušaja.
		if ( $attempt >= self::MAX_RETRIES ) {
			return;
		}
		$wait = $retry_after > 0 ? min( $retry_after, 5 ) : pow( 2, $attempt - 1 ); // 1s, 2s…
		sleep( max( 1, (int) $wait ) );
	}

	/* ---------------------------------------------------------------------
	 * Pomoćne funkcije
	 * ------------------------------------------------------------------ */

	/**
	 * Izvuci tekst iz Anthropic odgovora (content je niz blokova).
	 *
	 * @param array $data Dekodiran odgovor.
	 * @return string
	 */
	private function extract_text( $data ) {
		if ( empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $data['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$parts[] = $block['text'];
			}
		}
		return trim( implode( '', $parts ) );
	}

	/**
	 * Očisti i ograniči poruke koje stižu iz widgeta.
	 * Uzima poslednjih N poruka i obezbeđuje da prva bude od korisnika.
	 *
	 * @param array $messages Sirove poruke.
	 * @return array          Očišćene poruke spremne za Anthropic.
	 */
	private function sanitize_messages( $messages ) {
		$max_len = (int) nexxen_get_option( 'max_message_length', 1000 );
		$limit   = (int) nexxen_get_option( 'history_limit', 20 );

		// Uzmi samo poslednjih $limit poruka (kontrola troškova i tokena).
		$messages = array_slice( $messages, -1 * max( 1, $limit ) );

		$clean = array();
		foreach ( $messages as $msg ) {
			if ( ! isset( $msg['role'], $msg['content'] ) ) {
				continue;
			}
			$role = ( 'assistant' === $msg['role'] ) ? 'assistant' : 'user'; // dozvoljene samo 2 uloge
			$content = is_string( $msg['content'] ) ? $msg['content'] : '';

			// Ukloni HTML/skripte i ograniči dužinu.
			$content = sanitize_textarea_field( $content );
			if ( '' === trim( $content ) ) {
				continue;
			}
			if ( mb_strlen( $content ) > $max_len ) {
				$content = mb_substr( $content, 0, $max_len );
			}

			$clean[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		// Anthropic zahteva da prva poruka bude od korisnika.
		while ( ! empty( $clean ) && 'user' !== $clean[0]['role'] ) {
			array_shift( $clean );
		}

		return $clean;
	}

	/**
	 * IP adresa korisnika (REMOTE_ADDR je najpouzdaniji, ne može se lako falsifikovati).
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Standardizovan odgovor sa greškom (uvek nudi rezervni kontakt).
	 *
	 * @param string $message     Poruka za korisnika.
	 * @param string $contact     Rezervni email/kontakt.
	 * @param int    $status      HTTP status.
	 * @param string $code        Interni kod greške (za debug).
	 * @param int    $retry_after Sekunde do ponovnog pokušaja.
	 * @return WP_REST_Response
	 */
	private function error_response( $message, $contact, $status = 503, $code = 'error', $retry_after = 0 ) {
		$data = array(
			'error'    => true,
			'code'     => $code,
			'message'  => $message,
			'fallback' => $contact, // widget ovo prikazuje kao rezervni kontakt
		);
		if ( $retry_after > 0 ) {
			$data['retry_after'] = $retry_after;
		}
		return new WP_REST_Response( $data, $status );
	}
}
