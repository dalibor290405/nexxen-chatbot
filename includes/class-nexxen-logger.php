<?php
/**
 * Logovanje grešaka na server.
 *
 * Piše u fajl unutar wp-content/uploads/nexxen-asistent/log.txt (sa ograničenjem veličine),
 * a kao rezerva koristi standardni PHP error_log().
 *
 * @package Nexxen_Asistent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nexxen_Logger {

	/** Maksimalna veličina log fajla pre rotacije (1 MB). */
	const MAX_SIZE = 1048576;

	/**
	 * Zapiši poruku u log.
	 *
	 * @param string $message Poruka.
	 * @param string $level   Nivo: 'error', 'warning', 'info'.
	 * @param array  $context Dodatni podaci (biće serijalizovani u JSON).
	 */
	public static function log( $message, $level = 'error', $context = array() ) {
		$line = sprintf(
			'[%s] [%s] %s%s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message,
			! empty( $context ) ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : ''
		);

		// 1) Pokušaj upis u fajl.
		$file = self::log_file_path();
		if ( $file ) {
			// Rotacija: ako je fajl prevelik, preimenuj ga u .old (zadrži jednu staru kopiju).
			if ( file_exists( $file ) && filesize( $file ) > self::MAX_SIZE ) {
				@rename( $file, $file . '.old' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			// Upiši red. FILE_APPEND dodaje na kraj, LOCK_EX sprečava preplitanje istovremenih upisa.
			@file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX ); // phpcs:ignore
		}

		// 2) Uvek upiši i u standardni PHP log (rezerva ako fajl nije dostupan).
		error_log( 'Nexxen Asistent: ' . $line );
	}

	/** Skraćenica za grešku. */
	public static function error( $message, $context = array() ) {
		self::log( $message, 'error', $context );
	}

	/** Skraćenica za upozorenje. */
	public static function warning( $message, $context = array() ) {
		self::log( $message, 'warning', $context );
	}

	/** Skraćenica za info. */
	public static function info( $message, $context = array() ) {
		self::log( $message, 'info', $context );
	}

	/**
	 * Vraća putanju do log fajla i pravi direktorijum ako ne postoji.
	 * Dodaje i .htaccess + index.html da se sadržaj ne može čitati iz browsera.
	 *
	 * @return string|false
	 */
	private static function log_file_path() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'nexxen-asistent';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Zaštita: zabrani pristup direktorijumu preko browsera.
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
		}

		return $dir . '/log.txt';
	}
}
