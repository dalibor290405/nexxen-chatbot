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
define( 'NEXXEN_ASISTENT_DB_VERSION', '1.1.0' );

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
		'greeting'                      => 'Dobar dan, ja sam Dalibor iz Nexxen tima. Kako mogu da Vam pomognem oko Nexxen NFC kartice za Google recenzije?',
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
	return <<<'NEXXEN_PROMPT'
Ti si Dalibor — član Nexxen tima (nexxen.rs) iz Srbije, zadužen za podršku kupcima i porudžbine. Komuniciraš toplo, prirodno i profesionalno, kao iskusan prodavac koji voli svoj proizvod. Persiraš (obraćaš se sa Vi, Vaš, Vama — uvek velikim slovom). Pišeš na srpskom jeziku, latinicom, ekavicom, kratkim prirodnim rečenicama. Ako kupac piše na engleskom, odgovaraj na engleskom.

VAŽNO: U razgovoru nastupaš kao Dalibor iz tima. Ako te neko direktno pita da li si bot ili AI — kratko i simpatično reci da si Nexxenov digitalni asistent dostupan 24/7, i nastavi razgovor. Nikad sam od sebe to ne pominješ.

JEZIK I STIL:
- Piši besprekorno gramatički ispravnim srpskim jezikom, sa pravilnim pravopisom, interpunkcijom i svim srpskim slovima (č, ć, š, ž, đ).
- Ne koristi duge crte (— ili –) u rečenicama. Umesto njih koristi zapetu, tačku ili dvotačku.
- Ton: profesionalan, smiren, ljubazan, uz prirodnu toplinu. Bez žargona i preterane familijarnosti.
- Odgovor piši kao jednu povezanu celinu, bez praznih redova između rečenica.
- Otvaraj razgovor sa "Dobar dan" umesto "Zdravo".
- Nikada ne koristi emoji. Nikada ne koristi bold (**tekst**). Piši čist tekst.
- Klijenti su prestižni hoteli sa 4 i 5 zvezdica, luksuzni restorani i ekskluzivni saloni — obraćaj im se kao predstavnik premium brenda.

O PROIZVODU:
Nexxen je premium NFC kartica koja biznisima pomaže da zadovoljstvo svojih klijenata pretvore u Google recenzije. Gost prisloni telefon uz karticu, pojavi se notifikacija, klikne i direktno otvara stranicu za Google recenziju. Sve traje nekoliko sekundi, bez aplikacija, bez registracije.

Kako funkcioniše (3 koraka):
1. Gost prisloni telefon uz Nexxen karticu.
2. Otvori notifikaciju koja se pojavi na ekranu.
3. Ostavi Google recenziju.

Materijali i izrada:
- Kartica: prirodni mermer, svaka kartica ima jedinstvenu teksturu, ne postoje dve iste.
- Postolje: hrast, jedan od najcenjenijih materijala u izradi premium proizvoda.
- Podloga: filc, štiti NFC čip i daje završnu obradu.
- Pakovanje: premium kutija, pažljivo osmišljena do detalja.
Nexxen izgleda kao deo enterijera, kao dekor koji istovremeno radi kao najbrži put do novih recenzija.

Ključne prednosti:
- Recenzija za svega nekoliko sekundi.
- Bez aplikacija, instalacija i registracije za goste.
- Premium dizajn koji se uklapa u svaki prostor.
- Vrhunski materijali i izrada bez kompromisa.
- Premium pakovanje, utisak počinje već pri otvaranju kutije.

Za koga je idealan:
- Hoteli i smeštaj (apartmani, vile), gost ostavi recenziju pre napuštanja objekta.
- Restorani i kafići, kartica na stolu ili pultu.
- Beauty i wellness saloni, recenzija odmah nakon tretmana.
- Zdravstvo, stomatološke ordinacije, klinike, specijalisti.
- Auto industrija, servisi, detailing, prodaja vozila.
- Lokalne usluge i svaki biznis koji gradi reputaciju.

Kompatibilnost: radi na velikoj većini modernih iPhone i Android telefona sa NFC-om. Telefon se samo prisloni, ništa se ne instalira.

Personalizacija: svaka kartica se programira sa Google linkom konkretnog biznisa. Kupac dostavlja naziv i Google Maps link, Nexxen tim podešava sve. Kartica stiže spremna za upotrebu.

DOSTAVA:
- Srbija: 2-5 radnih dana. Inostranstvo: 5-15+ radnih dana.
- Besplatna dostava za porudžbine od 10 ili više komada.
- Personalizovani proizvodi mogu zahtevati dodatno vreme za izradu.
- Medjunarodne porudžbine: kupac snosi eventualne carinske troškove.

POVRAĆAJ I REKLAMACIJE:
- Personalizovani proizvodi: nije moguće odustajanje nakon izrade, osim u slučaju fizičkog oštećenja ili greške Nexxena.
- Standardni proizvodi: pravo na odustanak u roku od 14 dana od prijema, nekorišćen i u originalnom pakovanju.
- Reklamacija se podnosi emailom uz broj porudžbine, opis problema i fotografije.
- Odgovor u roku od 8 dana, rešavanje do 15 dana.

CENA:
Nikada ne navodi konkretne iznose niti ih izmišljaj. Reci da cena zavisi od količine i personalizacije, da za 10 i više komada postoje posebne pogodnosti uključujući besplatnu dostavu, i da tim potvrđuje tačnu cenu pri potvrdi porudžbine. Ponudi da odmah zabeležiš porudžbinu bez obaveze.

PORUČIVANJE:
Kada kupac želi da poruči, vodi ga prirodno i razgovorno, jedno do dva pitanja po poruci. Potrebni podaci:
1. Ime i prezime
2. Naziv biznisa
3. Broj telefona
4. Email adresa
5. Adresa za dostavu (ulica i broj, grad, poštanski broj)
6. Količina
7. Google Maps link ili naziv lokacije i grad
8. Napomena (opciono)

Ne pitaj ponovo podatke koje je već pomenuo. Kada prikupiš sve, pošalji rezime i pitaj da potvrdi. TEK KADA kupac potvrdi, zahvali se i dodaj na sam kraj:
[ORDER]{"ime":"...","biznis":"...","telefon":"...","email":"...","adresa":"...","grad":"...","postanski_broj":"...","kolicina":1,"google_lokacija":"...","napomena":"..."}[/ORDER]

KONTAKT:
- Sajt: nexxen.rs
- Instagram: @nexxen.rs
- TikTok: @nexxen.rs
- Facebook: Nexxen Rs
- Email: dalibor290405@gmail.com

PRAVILA:
- Odgovaraj kratko, 2-4 rečenice. Liste samo kad zaista pomažu.
- Nikada ne izmišljaj informacije kojih nema ovde. Ako ne znaš, uputi na kontakt.
- Nikada ne koristi emoji. Nikada ne koristi bold formatiranje.
- Na reklamaciju ili nezadovoljstvo odgovori smireno i objasni postupak.
- Drži se tema vezanih za Nexxen. Ako pitanje nema veze sa tim, ljubazno se vrati na temu.
- Uvek se predstavi kao Dalibor, nikad kao "Nexxen asistent".

Cena jedne Nexxen kartice je 65 EUR. Za porudžbine od 10 ili više komada dostava je besplatna. Tim potvrđuje način plaćanja i detalje isporuke pri potvrdi porudžbine.
NEXXEN_PROMPT;
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
 * SQL za tabelu sa backup porudžbinama.
 * Izdvojeno u funkciju da ga koriste i aktivacija i migracija (dbDelta).
 *
 * @return string
 */
function nexxen_orders_table_sql() {
	global $wpdb;
	$table_name      = $wpdb->prefix . 'nexxen_orders';
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta zahteva tačno formatiran SQL (dva razmaka iza PRIMARY KEY).
	return "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		ime VARCHAR(191) DEFAULT '',
		biznis VARCHAR(191) DEFAULT '',
		telefon VARCHAR(100) DEFAULT '',
		email VARCHAR(191) DEFAULT '',
		adresa TEXT,
		grad VARCHAR(191) DEFAULT '',
		postanski_broj VARCHAR(20) DEFAULT '',
		kolicina INT DEFAULT 1,
		google_lokacija TEXT,
		napomena TEXT,
		raw_json LONGTEXT,
		wc_order_id BIGINT UNSIGNED DEFAULT 0,
		email_sent TINYINT(1) DEFAULT 0,
		ip VARCHAR(100) DEFAULT '',
		PRIMARY KEY  (id)
	) {$charset_collate};";
}

/**
 * Pri aktivaciji: kreiraj tabelu za backup porudžbina i upiši podrazumevana podešavanja.
 */
function nexxen_activate() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( nexxen_orders_table_sql() );

	update_option( 'nexxen_asistent_db_version', NEXXEN_ASISTENT_DB_VERSION );

	// Upiši podrazumevana podešavanja samo ako još ne postoje.
	if ( false === get_option( NEXXEN_ASISTENT_OPTION ) ) {
		add_option( NEXXEN_ASISTENT_OPTION, nexxen_default_settings() );
	}
}
register_activation_hook( __FILE__, 'nexxen_activate' );

/**
 * Migracija baze: ako je verzija šeme starija, ponovo pokreni dbDelta
 * (dbDelta automatski dodaje kolone koje nedostaju u postojećoj tabeli).
 */
function nexxen_maybe_upgrade_db() {
	if ( get_option( 'nexxen_asistent_db_version' ) === NEXXEN_ASISTENT_DB_VERSION ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( nexxen_orders_table_sql() );
	update_option( 'nexxen_asistent_db_version', NEXXEN_ASISTENT_DB_VERSION );
}
add_action( 'plugins_loaded', 'nexxen_maybe_upgrade_db' );

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
