<?php
/**
 * Učitavanje widgeta na frontu sajta.
 *
 * Učitava CSS i JS u footeru i prosleđuje widgetu konfiguraciju
 * (URL endpointa, pozdrav, ograničenja…). API ključ se NIKADA ne prosleđuje.
 *
 * @package Nexxen_Asistent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nexxen_Widget {

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Da li widget treba prikazati na trenutnoj stranici?
	 *
	 * @return bool
	 */
	private function should_display() {
		// Globalni prekidač iz podešavanja.
		if ( ! (int) nexxen_get_option( 'widget_enabled', 1 ) ) {
			return false;
		}
		// Ne prikazuj u admin/login delu.
		if ( is_admin() ) {
			return false;
		}

		/**
		 * Filter za fino podešavanje (npr. sakrij widget na određenim stranicama).
		 * Primer u functions.php:
		 *   add_filter( 'nexxen_should_display', function( $show ) {
		 *       return is_page( 'kontakt' ) ? false : $show;
		 *   } );
		 */
		return (bool) apply_filters( 'nexxen_should_display', true );
	}

	public function enqueue() {
		if ( ! $this->should_display() ) {
			return;
		}

		// CSS.
		wp_enqueue_style(
			'nexxen-asistent',
			NEXXEN_ASISTENT_URL . 'assets/css/widget.css',
			array(),
			NEXXEN_ASISTENT_VERSION
		);

		// JS (u footeru — poslednji parametar true).
		wp_enqueue_script(
			'nexxen-asistent',
			NEXXEN_ASISTENT_URL . 'assets/js/widget.js',
			array(),
			NEXXEN_ASISTENT_VERSION,
			true
		);

		// Konfiguracija dostupna u JS-u kao globalna promenljiva "NexxenConfig".
		// PAŽNJA: ovde NEMA API ključa — samo javno bezbedni podaci.
		wp_localize_script(
			'nexxen-asistent',
			'NexxenConfig',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'nexxen/v1/chat' ) ),
				'tokenUrl'      => esc_url_raw( rest_url( 'nexxen/v1/token' ) ),
				'greeting'      => (string) nexxen_get_option( 'greeting', '' ),
				'maxMessageLen' => (int) nexxen_get_option( 'max_message_length', 1000 ),
				'fallback'      => sanitize_email( nexxen_get_option( 'fallback_contact', get_option( 'admin_email' ) ) ),
				'title'         => 'Nexxen asistent',
				'strings'       => array(
					'placeholder' => 'Napišite poruku…',
					'send'        => 'Pošalji',
					'typing'      => 'Asistent kuca…',
					'error'       => 'Došlo je do greške. Pokušajte ponovo.',
					'retry'       => 'Pokušaj ponovo',
					'openAria'    => 'Otvori chat',
					'closeAria'   => 'Zatvori chat',
					'orderOk'     => 'Vaša porudžbina je zabeležena. Hvala!',
				),
			)
		);
	}
}
