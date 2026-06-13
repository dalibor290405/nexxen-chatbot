<?php
/**
 * Čišćenje pri BRISANJU plugina (ne pri deaktivaciji).
 *
 * WordPress automatski pokreće ovaj fajl kada korisnik obriše plugin.
 * Brišemo podešavanja i privremene zapise, ali NAMERNO ZADRŽAVAMO tabelu
 * sa porudžbinama (backup) da se podaci ne bi nehotice izgubili.
 *
 * Ako želite i tabelu da obrišete, ručno izvršite u bazi:
 *   DROP TABLE wp_nexxen_orders;   (prefiks "wp_" zamenite svojim)
 *
 * @package Nexxen_Asistent
 */

// Pokreće se samo iz WordPress procesa brisanja plugina.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1) Obriši podešavanja i pomoćne opcije.
delete_option( 'nexxen_asistent_settings' );
delete_option( 'nexxen_asistent_db_version' );
delete_option( 'nexxen_daily_usage' );
delete_option( 'nexxen_daily_warned' );

// 2) Obriši preostale rate-limit "transients" (privremene zapise).
global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_nexxen\_rl\_%'
	    OR option_name LIKE '\_transient\_timeout\_nexxen\_rl\_%'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery

// NAPOMENA: tabelu {$wpdb->prefix}nexxen_orders namerno NE brišemo (čuva porudžbine).
