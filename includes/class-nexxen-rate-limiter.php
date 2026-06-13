<?php
/**
 * Rate limiting i kontrola dnevne potrošnje.
 *
 * Koristi WordPress "transients" (privremene zapise sa rokom trajanja) za brojanje
 * zahteva po IP adresi i po sesiji. Za dnevnu potrošnju koristi običnu opciju.
 *
 * @package Nexxen_Asistent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nexxen_Rate_Limiter {

	/**
	 * Proveri da li je zahtev dozvoljen (po IP-u i po sesiji).
	 *
	 * @param string $ip         IP adresa korisnika.
	 * @param string $session_id Identifikator sesije (iz widgeta).
	 * @return array  ['allowed' => bool, 'retry_after' => int, 'reason' => string]
	 */
	public static function check( $ip, $session_id ) {
		$per_minute  = (int) nexxen_get_option( 'rate_per_minute', 8 );
		$per_session = (int) nexxen_get_option( 'rate_per_session', 40 );

		// --- 1) Limit po IP adresi u toku jednog minuta ---
		if ( $per_minute > 0 ) {
			$ip_key   = 'nexxen_rl_ip_' . md5( $ip );
			$ip_count = (int) get_transient( $ip_key );

			if ( $ip_count >= $per_minute ) {
				return array(
					'allowed'     => false,
					'retry_after' => 60,
					'reason'      => 'ip_per_minute',
				);
			}
			// Povećaj brojač; istek 60s (resetuje se svakog minuta).
			set_transient( $ip_key, $ip_count + 1, MINUTE_IN_SECONDS );
		}

		// --- 2) Limit po sesiji (na sat) ---
		if ( $per_session > 0 && $session_id ) {
			$sess_key   = 'nexxen_rl_sess_' . md5( $session_id );
			$sess_count = (int) get_transient( $sess_key );

			if ( $sess_count >= $per_session ) {
				return array(
					'allowed'     => false,
					'retry_after' => 0,
					'reason'      => 'session_limit',
				);
			}
			set_transient( $sess_key, $sess_count + 1, HOUR_IN_SECONDS );
		}

		return array(
			'allowed'     => true,
			'retry_after' => 0,
			'reason'      => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * DNEVNA POTROŠNJA
	 * ------------------------------------------------------------------ */

	/**
	 * Vraća današnju potrošnju.
	 *
	 * @return array ['date' => 'Y-m-d', 'cost' => float, 'messages' => int]
	 */
	public static function get_daily_usage() {
		$today = gmdate( 'Y-m-d' );
		$usage = get_option( 'nexxen_daily_usage', array() );

		// Ako je novi dan, resetuj.
		if ( empty( $usage['date'] ) || $usage['date'] !== $today ) {
			$usage = array(
				'date'     => $today,
				'cost'     => 0.0,
				'messages' => 0,
			);
		}
		return $usage;
	}

	/**
	 * Da li je dnevni limit potrošnje dostignut?
	 *
	 * @return bool
	 */
	public static function daily_limit_reached() {
		$limit = (float) nexxen_get_option( 'daily_limit_usd', 0 );
		if ( $limit <= 0 ) {
			return false; // Limit isključen.
		}
		$usage = self::get_daily_usage();
		return $usage['cost'] >= $limit;
	}

	/**
	 * Dodaj potrošnju nakon uspešnog poziva (na osnovu broja tokena).
	 *
	 * @param int    $input_tokens  Broj ulaznih tokena.
	 * @param int    $output_tokens Broj izlaznih tokena.
	 * @param string $model         ID modela.
	 */
	public static function add_usage( $input_tokens, $output_tokens, $model ) {
		$models = nexxen_supported_models();
		$rates  = isset( $models[ $model ] ) ? $models[ $model ] : array(
			'in'  => 5.0,
			'out' => 25.0,
		);

		// Cena = (tokeni / 1.000.000) * cena_po_milionu.
		$cost = ( $input_tokens / 1000000 ) * $rates['in']
			+ ( $output_tokens / 1000000 ) * $rates['out'];

		$usage              = self::get_daily_usage();
		$usage['cost']     += $cost;
		$usage['messages'] += 1;
		update_option( 'nexxen_daily_usage', $usage, false );

		// Upozorenje na 80% limita (jednom dnevno) radi pravovremene reakcije.
		$limit = (float) nexxen_get_option( 'daily_limit_usd', 0 );
		if ( $limit > 0 && $usage['cost'] >= ( $limit * 0.8 ) ) {
			$warned = get_option( 'nexxen_daily_warned', '' );
			if ( $warned !== $usage['date'] ) {
				update_option( 'nexxen_daily_warned', $usage['date'], false );
				Nexxen_Logger::warning(
					'Dnevna potrošnja je dostigla 80% limita.',
					array(
						'cost'  => round( $usage['cost'], 4 ),
						'limit' => $limit,
					)
				);
			}
		}
	}
}
