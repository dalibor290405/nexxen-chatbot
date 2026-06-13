<?php
/**
 * Plugin Name:       Nexxen Asistent
 * Plugin URI:        https://nexxen.rs
 * Description:        AI chatbot (Claude/Anthropic) za nexxen.rs. Bezbedan backend proxy, rate limiting, obrada porudžbina (email + WooCommerce nacrt + backup u bazi).
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Nexxen
 * Text Domain:       nexxen-asistent
 * Domain Path:       /languages
 *
 * @package Nexxen_Asistent
 */

// Bezbednost: zabrani direktan pristup fajlu (van WordPress-a).
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1) KONSTANTE
 * ---------------------------------------------------------------------- */

define( 'NEXXEN_ASISTENT_VERSION', '1.0.0' );
define( 'NEXXEN_ASISTENT_FILE', __FILE__ );
define( 'NEXXEN_ASISTENT_DIR', plugin_dir_path( __FILE__ ) );   // apsolutna putanja, npr. /var/www/.../nexxen-asistent/
define( 'NEXXEN_ASISTENT_URL', plugin_dir_url( __FILE__ ) );    // URL, npr. https://sajt.rs/wp-content/plugins/nexxen-asistent/
define( 'NEXXEN_ASISTENT_OPTION', 'nexxen_asistent_settings' ); // ime opcije u bazi (jedan niz sa svim podešavanjima)
define( 'NEXXEN_ASISTENT_DB_VERSION', '1.0.0' );

/* -------------------------------------------------------------------------
 * 2) UČITAVANJE KLASA
 * ---------------------------------------------------------------------- */

require_once NEXXEN_ASISTENT_DIR . 'includes/class-nexxen-logger.php';
require_once NEXXEN_ASISTENT_DIR . 'includes/class-nexxen-rate-limiter.php';
require_once NEXXEN_ASISTENT_DIR . 'includes/class-nexxen-orders.php';
require_once NEXXEN_ASISTENT_DIR . 'includes/class-nexxen-rest.php';
require_once NEXXEN_ASISTENT_DIR . 'includes/class-nexxen-settings.php';
require_once NEXXEN_ASISTENT_DIR . 'includes/class-nexxen-widget.php';

/* -------------------------------------------------------------------------
 * 3) POMOĆNE FUNKCIJE (dostupne svuda u pluginu)
 * ---------------------------------------------------------------------- */

/**
 * Vraća podrazumevana podešavanja.
 * Sve vrednosti se mogu promeniti u admin panelu (Podešavanja → Nexxen Asistent).
 *
 * @return array
 */
function nexxen_default_settings() {
	return array(
		// --- Veza sa Anthropic-om ---
		'api_key'                       => '', // Preporuka: postaviti u wp-config.php kao NEXXEN_ANTHROPIC_API_KEY.
		'model'                         => 'claude-opus-4-8',
		'max_tokens'                    => 1024,

		// --- Obaveštenja / porudžbine ---
		'notification_email'            => 'dalibor290405@gmail.com',
		'fallback_contact'             => 'dalibor290405@gmail.com',
		'woocommerce_draft'             => 1, // 1 = kreiraj nacrt porudžbine u WooCommerce-u
		'woo_product_id'                => 0, // opcioni ID proizvoda koji se dodaje u nacrt porudžbine

		// --- Widget ---
		'widget_enabled'                => 1,
		'greeting'                      => 'Zdravo! 👋 Ja sam Nexxen asistent. Kako mogu da Vam pomognem oko NFC kartice za Google recenzije?',
		'system_prompt'                 => nexxen_default_system_prompt(),

		// --- Bezbednost / ograničenja (anti-zloupotreba i kontrola troškova) ---
		'history_limit'                 => 20,   // koliko poslednjih poruka se šalje Anthropic-u
		'max_message_length'            => 1000, // maks. dužina jedne poruke korisnika (znakova)
		'max_messages_per_conversation' => 40,   // maks. broj poruka po razgovoru
		'rate_per_minute'               => 8,    // maks. poruka po IP adresi u minuti
		'rate_per_session'              => 40,   // maks. poruka po sesiji (na sat)
		'daily_limit_usd'               => 0,    // dnevni limit potrošnje u USD (0 = isključeno)
		'allowed_origin'                => home_url(), // CORS / provera porekla zahteva
	);
}

/**
 * Podrazumevani sistem-prompt (znanje o proizvodu + ton + tok porudžbine).
 * Korisnik ga može menjati u admin panelu.
 *
 * @return string
 */
function nexxen_default_system_prompt() {
	return <<<PROMPT
Vi ste „Nexxen asistent", ljubazni prodajni i korisnički asistent za sajt nexxen.rs.

O PROIZVODU:
Nexxen je NFC kartica za Google recenzije. Kada kupac prisloni telefon uz karticu, automatski se otvara stranica za ostavljanje Google recenzije date firme. Tako firme (kafići, restorani, saloni, frizeri, radnje…) brzo i lako skupljaju više pozitivnih recenzija i poboljšavaju svoj rejting na Google mapama.

KAKO KOMUNICIRATE:
- Uvek persirate (Vi, Vaš, Vama) i pišete na srpskom jeziku.
- Ljubazni ste, kratki i jasni. Ne izmišljate informacije i ne obećavate ono u šta niste sigurni.
- Ako ne znate odgovor ili je pitanje van teme (reklamacije, tehnička podrška naloga…), ljubazno uputite korisnika da kontaktira tim na dalibor290405@gmail.com.

CENA I ISPORUKA:
- [UNESITE TAČNU CENU I USLOVE ISPORUKE OVDE — ovo je primer koji treba da izmenite u podešavanjima.]

TOK PORUDŽBINE:
Kada korisnik želi da poruči, prikupite redom ove podatke: ime i prezime, broj telefona, adresu (ulica i broj), grad, poštanski broj, željenu količinu i opcionu napomenu.
Kada prikupite sve podatke, NAJPRE ih ukratko ponovite korisniku radi potvrde.
Tek kada korisnik POTVRDI porudžbinu, na samom KRAJU svoje poruke dodajte tačno ovaj blok (korisniku prikažite normalnu, lepu potvrdu — ovaj blok služi isključivo sistemu i korisnik ga ne vidi):

[ORDER]
{"ime":"...","telefon":"...","adresa":"...","grad":"...","postanski_broj":"...","kolicina":1,"napomena":"..."}
[/ORDER]

PRAVILA ZA [ORDER] BLOK:
- Dodajte ga ISKLJUČIVO kada je porudžbina potvrđena i kada su prisutni svi obavezni podaci: ime, telefon, adresa, grad i količina.
- Nikada ga ne dodajte u običnom razgovoru ili dok prikupljate podatke.
- JSON mora biti validan (dvostruki navodnici, bez komentara). „kolicina" je broj.
PROMPT;
}

/**
 * Vraća sva podešavanja (spojena sa podrazumevanim vrednostima).
 *
 * @return array
 */
function nexxen_get_settings() {
	$saved = get_option( NEXXEN_ASISTENT_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, nexxen_default_settings() );
}

/**
 * Vraća jedno podešavanje po ključu.
 *
 * @param string $key     Ključ.
 * @param mixed  $default Podrazumevana vrednost ako ključ ne postoji.
 * @return mixed
 */
function nexxen_get_option( $key, $default = null ) {
	$settings = nexxen_get_settings();
	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
}

/**
 * Vraća Anthropic API ključ.
 * Prioritet: konstanta u wp-config.php (NEXXEN_ANTHROPIC_API_KEY) → opcija u bazi.
 * Ovo omogućava da se ključ drži van baze, što je najbezbednije.
 *
 * @return string
 */
function nexxen_get_api_key() {
	if ( defined( 'NEXXEN_ANTHROPIC_API_KEY' ) && NEXXEN_ANTHROPIC_API_KEY ) {
		return (string) NEXXEN_ANTHROPIC_API_KEY;
	}
	return (string) nexxen_get_option( 'api_key', '' );
}

/**
 * Da li je ključ definisan preko wp-config.php konstante?
 *
 * @return bool
 */
function nexxen_api_key_is_constant() {
	return defined( 'NEXXEN_ANTHROPIC_API_KEY' ) && NEXXEN_ANTHROPIC_API_KEY;
}

/**
 * Lista podržanih modela sa cenama (USD po milion tokena: ulaz, izlaz).
 * Cene služe za procenu dnevne potrošnje.
 *
 * @return array
 */
function nexxen_supported_models() {
	return array(
		'claude-opus-4-8'   => array(
			'label' => 'Claude Opus 4.8 (najpametniji)',
			'in'    => 5.0,
			'out'   => 25.0,
		),
		'claude-sonnet-4-6' => array(
			'label' => 'Claude Sonnet 4.6 (balans)',
			'in'    => 3.0,
			'out'   => 15.0,
		),
		'claude-haiku-4-5'  => array(
			'label' => 'Claude Haiku 4.5 (najjeftiniji)',
			'in'    => 1.0,
			'out'   => 5.0,
		),
	);
}

/* -------------------------------------------------------------------------
 * 4) AKTIVACIJA / DEAKTIVACIJA
 * ---------------------------------------------------------------------- */

/**
 * Pri aktivaciji: kreiraj tabelu za backup porudžbina i upiši podrazumevana podešavanja.
 */
function nexxen_activate() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'nexxen_orders';
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta zahteva tačno formatiran SQL (dva razmaka, mala/velika slova kako WP očekuje).
	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		ime VARCHAR(191) DEFAULT '',
		telefon VARCHAR(100) DEFAULT '',
		adresa TEXT,
		grad VARCHAR(191) DEFAULT '',
		postanski_broj VARCHAR(20) DEFAULT '',
		kolicina INT DEFAULT 1,
		napomena TEXT,
		raw_json LONGTEXT,
		wc_order_id BIGINT UNSIGNED DEFAULT 0,
		email_sent TINYINT(1) DEFAULT 0,
		ip VARCHAR(100) DEFAULT '',
		PRIMARY KEY  (id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'nexxen_asistent_db_version', NEXXEN_ASISTENT_DB_VERSION );

	// Upiši podrazumevana podešavanja samo ako još ne postoje.
	if ( false === get_option( NEXXEN_ASISTENT_OPTION ) ) {
		add_option( NEXXEN_ASISTENT_OPTION, nexxen_default_settings() );
	}
}
register_activation_hook( __FILE__, 'nexxen_activate' );

/* -------------------------------------------------------------------------
 * 5) POKRETANJE PLUGINA
 * ---------------------------------------------------------------------- */

/**
 * Inicijalizacija svih delova plugina.
 */
function nexxen_init() {
	// Prevodi (ako kasnije dodate .mo fajlove u /languages).
	load_plugin_textdomain( 'nexxen-asistent', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Admin podešavanja.
	if ( is_admin() ) {
		( new Nexxen_Settings() )->init();
	}

	// REST endpoint (radi i na frontu i u adminu).
	( new Nexxen_REST() )->init();

	// Widget na frontu.
	( new Nexxen_Widget() )->init();
}
add_action( 'plugins_loaded', 'nexxen_init' );
