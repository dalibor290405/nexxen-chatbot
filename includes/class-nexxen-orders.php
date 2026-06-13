<?php
/**
 * Obrada porudžbina.
 *
 * 1) Pronalazi [ORDER]{...}[/ORDER] blok u odgovoru AI-ja.
 * 2) Čisti (sanitize) i validira podatke.
 * 3) Čuva u bazi (backup, da se ništa ne izgubi).
 * 4) Šalje email obaveštenje.
 * 5) Opciono kreira nacrt porudžbine u WooCommerce-u.
 *
 * @package Nexxen_Asistent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nexxen_Orders {

	/**
	 * Da li tekst sadrži [ORDER] blok?
	 *
	 * @param string $text Tekst odgovora AI-ja.
	 * @return bool
	 */
	public static function has_order( $text ) {
		return (bool) preg_match( '/\[ORDER\](.*?)\[\/ORDER\]/s', $text );
	}

	/**
	 * Ukloni [ORDER] blok iz teksta (da ga korisnik ne vidi u chatu).
	 *
	 * @param string $text Tekst.
	 * @return string Očišćen tekst.
	 */
	public static function strip_order_block( $text ) {
		$clean = preg_replace( '/\[ORDER\].*?\[\/ORDER\]/s', '', $text );
		return trim( (string) $clean );
	}

	/**
	 * Izvuci i dekodiraj JSON iz [ORDER] bloka.
	 *
	 * @param string $text Tekst.
	 * @return array|null  Niz sa podacima ili null ako nije validan.
	 */
	public static function extract_order( $text ) {
		if ( ! preg_match( '/\[ORDER\]\s*(\{.*?\})\s*\[\/ORDER\]/s', $text, $m ) ) {
			return null;
		}

		$data = json_decode( $m[1], true );
		if ( ! is_array( $data ) ) {
			Nexxen_Logger::error( 'Neuspešno parsiranje [ORDER] JSON-a.', array( 'raw' => $m[1] ) );
			return null;
		}
		return $data;
	}

	/**
	 * Glavna obrada: sačuvaj u bazu, pošalji email, kreiraj WooCommerce nacrt.
	 *
	 * @param array  $data Sirovi podaci iz [ORDER] bloka.
	 * @param string $ip   IP adresa korisnika (za zapis).
	 * @return array       Rezime obrade (za log/debug).
	 */
	public static function process( $data, $ip = '' ) {
		// --- 1) Čišćenje i validacija podataka ---
		$order = self::sanitize( $data );

		// Obavezna polja. Ako fali nešto ključno, samo logujemo i prekidamo
		// (razgovor se ne kvari, AI je verovatno poslao nepotpun blok).
		if ( '' === $order['ime'] || '' === $order['telefon'] ) {
			Nexxen_Logger::warning( 'Porudžbina bez obaveznih polja (ime/telefon).', $order );
			return array( 'saved' => false );
		}

		// --- 2) Backup u bazu (PRVO, da se podaci sigurno sačuvaju) ---
		$order_id = self::save_to_db( $order, $ip );

		// --- 3) WooCommerce nacrt (opciono) ---
		$wc_order_id = 0;
		if ( (int) nexxen_get_option( 'woocommerce_draft', 1 ) && class_exists( 'WooCommerce' ) ) {
			$wc_order_id = self::create_woocommerce_draft( $order );
			if ( $wc_order_id && $order_id ) {
				self::update_db_row( $order_id, array( 'wc_order_id' => $wc_order_id ) );
			}
		}

		// --- 4) Email obaveštenje ---
		$email_sent = self::send_email( $order, $wc_order_id );
		if ( $order_id ) {
			self::update_db_row( $order_id, array( 'email_sent' => $email_sent ? 1 : 0 ) );
		}

		Nexxen_Logger::info(
			'Porudžbina obrađena.',
			array(
				'db_id'       => $order_id,
				'wc_order_id' => $wc_order_id,
				'email_sent'  => $email_sent,
			)
		);

		return array(
			'saved'       => (bool) $order_id,
			'db_id'       => $order_id,
			'wc_order_id' => $wc_order_id,
			'email_sent'  => $email_sent,
		);
	}

	/**
	 * Očisti pojedinačna polja porudžbine.
	 *
	 * @param array $data Sirovi podaci.
	 * @return array      Očišćeni podaci.
	 */
	private static function sanitize( $data ) {
		return array(
			'ime'            => isset( $data['ime'] ) ? sanitize_text_field( $data['ime'] ) : '',
			'telefon'        => isset( $data['telefon'] ) ? sanitize_text_field( $data['telefon'] ) : '',
			'adresa'         => isset( $data['adresa'] ) ? sanitize_text_field( $data['adresa'] ) : '',
			'grad'           => isset( $data['grad'] ) ? sanitize_text_field( $data['grad'] ) : '',
			'postanski_broj' => isset( $data['postanski_broj'] ) ? sanitize_text_field( $data['postanski_broj'] ) : '',
			'kolicina'       => isset( $data['kolicina'] ) ? max( 1, absint( $data['kolicina'] ) ) : 1,
			'napomena'       => isset( $data['napomena'] ) ? sanitize_textarea_field( $data['napomena'] ) : '',
		);
	}

	/**
	 * Sačuvaj porudžbinu u našu tabelu (backup).
	 *
	 * @param array  $order Očišćeni podaci.
	 * @param string $ip    IP.
	 * @return int          ID reda u bazi (0 ako neuspešno).
	 */
	private static function save_to_db( $order, $ip ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nexxen_orders';

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'created_at'     => current_time( 'mysql', true ),
				'ime'            => $order['ime'],
				'telefon'        => $order['telefon'],
				'adresa'         => $order['adresa'],
				'grad'           => $order['grad'],
				'postanski_broj' => $order['postanski_broj'],
				'kolicina'       => $order['kolicina'],
				'napomena'       => $order['napomena'],
				'raw_json'       => wp_json_encode( $order, JSON_UNESCAPED_UNICODE ),
				'ip'             => $ip,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			Nexxen_Logger::error( 'Neuspešan upis porudžbine u bazu.', array( 'db_error' => $wpdb->last_error ) );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Ažuriraj red u bazi (npr. wc_order_id ili email_sent).
	 *
	 * @param int   $id     ID reda.
	 * @param array $fields Polja za ažuriranje.
	 */
	private static function update_db_row( $id, $fields ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nexxen_orders';
		$wpdb->update( $table, $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Pošalji email obaveštenje o porudžbini.
	 *
	 * @param array $order       Podaci.
	 * @param int   $wc_order_id ID WooCommerce porudžbine (0 ako nema).
	 * @return bool              Da li je email poslat.
	 */
	private static function send_email( $order, $wc_order_id = 0 ) {
		$to = sanitize_email( nexxen_get_option( 'notification_email', get_option( 'admin_email' ) ) );
		if ( ! is_email( $to ) ) {
			Nexxen_Logger::error( 'Neispravan email za obaveštenja.', array( 'to' => $to ) );
			return false;
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] Nova porudžbina preko chatbota', $site );

		$lines = array(
			'Nova porudžbina je primljena preko Nexxen asistenta:',
			'',
			'Ime i prezime: ' . $order['ime'],
			'Telefon: ' . $order['telefon'],
			'Adresa: ' . $order['adresa'],
			'Grad: ' . $order['grad'],
			'Poštanski broj: ' . $order['postanski_broj'],
			'Količina: ' . $order['kolicina'],
			'Napomena: ' . ( '' !== $order['napomena'] ? $order['napomena'] : '-' ),
			'',
			'Vreme: ' . current_time( 'd.m.Y. H:i' ),
		);

		if ( $wc_order_id ) {
			$lines[] = '';
			$lines[] = 'WooCommerce nacrt porudžbine: #' . $wc_order_id;
			$lines[] = admin_url( 'post.php?post=' . $wc_order_id . '&action=edit' );
		}

		$body    = implode( "\n", $lines );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $to, $subject, $body, $headers );
		if ( ! $sent ) {
			Nexxen_Logger::error( 'Slanje email obaveštenja nije uspelo. (Backup u bazi je sačuvan.)', array( 'to' => $to ) );
		}
		return (bool) $sent;
	}

	/**
	 * Kreiraj nacrt (pending) porudžbine u WooCommerce-u.
	 *
	 * @param array $order Podaci.
	 * @return int         ID WooCommerce porudžbine (0 ako neuspešno).
	 */
	private static function create_woocommerce_draft( $order ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return 0;
		}

		try {
			$wc_order = wc_create_order();

			// wc_create_order može vratiti WP_Error umesto objekta porudžbine.
			if ( is_wp_error( $wc_order ) ) {
				Nexxen_Logger::error( 'wc_create_order je vratio grešku.', array( 'error' => $wc_order->get_error_message() ) );
				return 0;
			}

			// Razdvoji ime i prezime (grubo, po prvom razmaku).
			$name_parts = explode( ' ', $order['ime'], 2 );
			$first_name = $name_parts[0];
			$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';

			$wc_order->set_address(
				array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'address_1'  => $order['adresa'],
					'city'       => $order['grad'],
					'postcode'   => $order['postanski_broj'],
					'phone'      => $order['telefon'],
					'country'    => 'RS',
				),
				'billing'
			);
			$wc_order->set_address(
				array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'address_1'  => $order['adresa'],
					'city'       => $order['grad'],
					'postcode'   => $order['postanski_broj'],
					'country'    => 'RS',
				),
				'shipping'
			);

			// Dodaj proizvod ako je podešen u admin panelu.
			$product_id = (int) nexxen_get_option( 'woo_product_id', 0 );
			if ( $product_id && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$wc_order->add_product( $product, $order['kolicina'] );
				}
			}

			// Napomena za radnike + porudžbina ide u status "na čekanju" (pending).
			$note = 'Porudžbina kreirana preko Nexxen asistenta (chatbot).';
			if ( '' !== $order['napomena'] ) {
				$note .= ' Napomena kupca: ' . $order['napomena'];
			}
			$wc_order->add_order_note( $note );

			$wc_order->calculate_totals();
			$wc_order->set_status( 'pending', 'Nacrt kreiran preko chatbota.' );
			$wc_order->save();

			return (int) $wc_order->get_id();

		} catch ( Exception $e ) {
			Nexxen_Logger::error( 'WooCommerce nacrt nije kreiran.', array( 'exception' => $e->getMessage() ) );
			return 0;
		}
	}
}
