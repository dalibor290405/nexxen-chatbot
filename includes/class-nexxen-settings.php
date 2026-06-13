<?php
/**
 * Admin stranica sa podešavanjima.
 *
 * Podešavanja → Nexxen Asistent. Koristi WordPress Settings API i čuva
 * sve u jednoj opciji (niz). Sva polja se čiste pre upisa, a izlaz se "escape"-uje.
 *
 * @package Nexxen_Asistent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nexxen_Settings {

	const PAGE_SLUG  = 'nexxen-asistent';
	const GROUP      = 'nexxen_asistent_group';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_nexxen_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/** Dodaj stavku u meni "Podešavanja". */
	public function add_menu() {
		add_options_page(
			'Nexxen Asistent',
			'Nexxen Asistent',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/** Registruj opciju i njen sanitizer. */
	public function register_settings() {
		register_setting(
			self::GROUP,
			NEXXEN_ASISTENT_OPTION,
			array( $this, 'sanitize' )
		);
	}

	/**
	 * Očisti sva podešavanja pre upisa u bazu.
	 *
	 * @param array $input Sirovi unos iz forme.
	 * @return array       Očišćena podešavanja.
	 */
	public function sanitize( $input ) {
		$old   = nexxen_get_settings();
		$clean = array();

		// --- API ključ: ako je polje prazno, zadrži postojeći (ne brišemo ga slučajno) ---
		$submitted_key = isset( $input['api_key'] ) ? trim( $input['api_key'] ) : '';
		$clean['api_key'] = ( '' !== $submitted_key )
			? sanitize_text_field( $submitted_key )
			: $old['api_key'];

		// --- Model: dozvoli samo poznate vrednosti ---
		$models          = nexxen_supported_models();
		$model           = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'claude-opus-4-8';
		$clean['model']  = array_key_exists( $model, $models ) ? $model : 'claude-opus-4-8';

		// --- Brojevi (sa razumnim granicama) ---
		$clean['max_tokens']                    = min( 4096, max( 128, absint( $input['max_tokens'] ?? 1024 ) ) );
		$clean['history_limit']                 = min( 60, max( 2, absint( $input['history_limit'] ?? 20 ) ) );
		$clean['max_message_length']            = min( 4000, max( 50, absint( $input['max_message_length'] ?? 1000 ) ) );
		$clean['max_messages_per_conversation'] = min( 200, max( 4, absint( $input['max_messages_per_conversation'] ?? 40 ) ) );
		$clean['rate_per_minute']               = min( 100, max( 0, absint( $input['rate_per_minute'] ?? 8 ) ) );
		$clean['rate_per_session']              = min( 500, max( 0, absint( $input['rate_per_session'] ?? 40 ) ) );
		$clean['daily_limit_usd']               = max( 0, floatval( $input['daily_limit_usd'] ?? 0 ) );
		$clean['woo_product_id']                = absint( $input['woo_product_id'] ?? 0 );

		// --- Email / kontakt ---
		$clean['notification_email'] = sanitize_email( $input['notification_email'] ?? '' );
		$clean['fallback_contact']   = sanitize_email( $input['fallback_contact'] ?? '' );
		if ( ! is_email( $clean['notification_email'] ) ) {
			$clean['notification_email'] = $old['notification_email'];
			add_settings_error( NEXXEN_ASISTENT_OPTION, 'bad_email', 'Email za obaveštenja nije ispravan — zadržana je prethodna vrednost.' );
		}

		// --- Prekidači (checkbox) ---
		$clean['widget_enabled']    = ! empty( $input['widget_enabled'] ) ? 1 : 0;
		$clean['woocommerce_draft'] = ! empty( $input['woocommerce_draft'] ) ? 1 : 0;

		// --- Tekstualna polja ---
		$clean['greeting']       = sanitize_text_field( $input['greeting'] ?? '' );
		$clean['system_prompt']  = isset( $input['system_prompt'] ) ? sanitize_textarea_field( $input['system_prompt'] ) : '';
		$clean['allowed_origin'] = esc_url_raw( $input['allowed_origin'] ?? home_url() );

		return $clean;
	}

	/** Prikaži admin stranicu. */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s          = nexxen_get_settings();
		$models     = nexxen_supported_models();
		$key_const  = nexxen_api_key_is_constant();
		$has_key    = (bool) nexxen_get_api_key();
		$woo_active = class_exists( 'WooCommerce' );
		$usage      = Nexxen_Rate_Limiter::get_daily_usage();
		$orders_cnt = $this->count_orders();
		?>
		<div class="wrap">
			<h1>Nexxen Asistent — Podešavanja</h1>

			<?php settings_errors( NEXXEN_ASISTENT_OPTION ); ?>

			<!-- STATUS PANEL -->
			<div class="notice notice-info" style="padding:12px 16px;">
				<p style="margin:0 0 6px;"><strong>Stanje:</strong></p>
				<ul style="margin:0;list-style:disc;padding-left:20px;">
					<li>API ključ: <?php echo $has_key ? '✅ podešen' . ( $key_const ? ' (preko wp-config.php)' : '' ) : '❌ nije podešen'; ?></li>
					<li>WooCommerce: <?php echo $woo_active ? '✅ aktivan' : '⚠️ nije aktivan (nacrt porudžbine se preskače)'; ?></li>
					<li>Danas: <?php echo (int) $usage['messages']; ?> poruka, procenjena potrošnja ~$<?php echo esc_html( number_format( (float) $usage['cost'], 4 ) ); ?></li>
					<li>Sačuvanih porudžbina (backup u bazi): <?php echo (int) $orders_cnt; ?></li>
				</ul>
				<p style="margin:8px 0 0;">
					<button type="button" class="button" id="nexxen-test-btn">Testiraj vezu sa Anthropic-om</button>
					<span id="nexxen-test-result" style="margin-left:10px;"></span>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); // nonce + group polja ?>

				<h2 class="title">Veza sa Anthropic-om</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nexxen_api_key">API ključ</label></th>
						<td>
							<?php if ( $key_const ) : ?>
								<p><em>Ključ je definisan u <code>wp-config.php</code> (NEXXEN_ANTHROPIC_API_KEY) i ima prioritet. Ovo polje je onemogućeno.</em></p>
							<?php else : ?>
								<input type="password" id="nexxen_api_key" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[api_key]"
									value="" autocomplete="new-password" class="regular-text"
									placeholder="<?php echo $has_key ? '•••••••• (sačuvan — ostavite prazno da ne menjate)' : 'sk-ant-...'; ?>" />
								<p class="description">Unesite samo ako menjate ključ. Najbezbednije: stavite ga u <code>wp-config.php</code>.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_model">Model</label></th>
						<td>
							<select id="nexxen_model" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[model]">
								<?php foreach ( $models as $id => $info ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['model'], $id ); ?>>
										<?php echo esc_html( $info['label'] . ' — ' . $id ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_max_tokens">Maks. tokena u odgovoru</label></th>
						<td>
							<input type="number" id="nexxen_max_tokens" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[max_tokens]"
								value="<?php echo esc_attr( $s['max_tokens'] ); ?>" min="128" max="4096" class="small-text" />
							<p class="description">Dužina odgovora. Manje = jeftinije i brže. Preporuka: 1024.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Obaveštenja i porudžbine</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nexxen_notification_email">Email za porudžbine</label></th>
						<td><input type="email" id="nexxen_notification_email" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[notification_email]"
							value="<?php echo esc_attr( $s['notification_email'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_fallback_contact">Rezervni kontakt (kad AI ne radi)</label></th>
						<td><input type="email" id="nexxen_fallback_contact" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[fallback_contact]"
							value="<?php echo esc_attr( $s['fallback_contact'] ); ?>" class="regular-text" />
							<p class="description">Prikazuje se korisniku ako asistent privremeno nije dostupan.</p></td>
					</tr>
					<tr>
						<th scope="row">WooCommerce nacrt</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[woocommerce_draft]" value="1" <?php checked( $s['woocommerce_draft'], 1 ); ?> />
								Kreiraj nacrt (pending) porudžbine u WooCommerce-u
							</label>
							<?php if ( ! $woo_active ) : ?>
								<p class="description" style="color:#b32d2e;">WooCommerce nije aktivan — ova opcija se zanemaruje dok ga ne instalirate.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_woo_product_id">ID proizvoda (opciono)</label></th>
						<td>
							<input type="number" id="nexxen_woo_product_id" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[woo_product_id]"
								value="<?php echo esc_attr( $s['woo_product_id'] ); ?>" min="0" class="small-text" />
							<p class="description">ID Nexxen proizvoda u WooCommerce-u; dodaje se u nacrt porudžbine. Ostavite 0 ako ne želite stavku.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Widget</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Prikaz widgeta</th>
						<td><label>
							<input type="checkbox" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[widget_enabled]" value="1" <?php checked( $s['widget_enabled'], 1 ); ?> />
							Uključi chatbot na sajtu
						</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_greeting">Pozdravna poruka</label></th>
						<td><input type="text" id="nexxen_greeting" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[greeting]"
							value="<?php echo esc_attr( $s['greeting'] ); ?>" class="large-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_system_prompt">Sistem-prompt (znanje i ton)</label></th>
						<td>
							<textarea id="nexxen_system_prompt" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[system_prompt]"
								rows="16" class="large-text code"><?php echo esc_textarea( $s['system_prompt'] ); ?></textarea>
							<p class="description">Uputstva za AI: opis proizvoda, cena, ton, tok porudžbine. <strong>Obavezno unesite tačnu cenu i uslove isporuke.</strong></p>
						</td>
					</tr>
				</table>

				<h2 class="title">Bezbednost i limiti</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nexxen_allowed_origin">Dozvoljeni domen (CORS)</label></th>
						<td><input type="url" id="nexxen_allowed_origin" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[allowed_origin]"
							value="<?php echo esc_attr( $s['allowed_origin'] ); ?>" class="regular-text" />
							<p class="description">Samo zahtevi sa ovog domena su dozvoljeni. Obično: <?php echo esc_html( home_url() ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_history_limit">Istorija (broj poruka)</label></th>
						<td><input type="number" id="nexxen_history_limit" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[history_limit]"
							value="<?php echo esc_attr( $s['history_limit'] ); ?>" min="2" max="60" class="small-text" />
							<p class="description">Koliko poslednjih poruka se šalje AI-ju. Manje = jeftinije. Preporuka: 20.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_max_message_length">Maks. dužina poruke</label></th>
						<td><input type="number" id="nexxen_max_message_length" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[max_message_length]"
							value="<?php echo esc_attr( $s['max_message_length'] ); ?>" min="50" max="4000" class="small-text" /> znakova</td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_max_messages_per_conversation">Maks. poruka po razgovoru</label></th>
						<td><input type="number" id="nexxen_max_messages_per_conversation" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[max_messages_per_conversation]"
							value="<?php echo esc_attr( $s['max_messages_per_conversation'] ); ?>" min="4" max="200" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_rate_per_minute">Limit po IP-u / minut</label></th>
						<td><input type="number" id="nexxen_rate_per_minute" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[rate_per_minute]"
							value="<?php echo esc_attr( $s['rate_per_minute'] ); ?>" min="0" max="100" class="small-text" />
							<p class="description">0 = isključeno.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_rate_per_session">Limit po sesiji / sat</label></th>
						<td><input type="number" id="nexxen_rate_per_session" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[rate_per_session]"
							value="<?php echo esc_attr( $s['rate_per_session'] ); ?>" min="0" max="500" class="small-text" />
							<p class="description">0 = isključeno.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxen_daily_limit_usd">Dnevni limit potrošnje (USD)</label></th>
						<td><input type="number" step="0.01" id="nexxen_daily_limit_usd" name="<?php echo esc_attr( NEXXEN_ASISTENT_OPTION ); ?>[daily_limit_usd]"
							value="<?php echo esc_attr( $s['daily_limit_usd'] ); ?>" min="0" class="small-text" />
							<p class="description">0 = bez limita. Procena na osnovu broja tokena. Na 80% se beleži upozorenje u log.</p></td>
					</tr>
				</table>

				<?php submit_button( 'Sačuvaj podešavanja' ); ?>
			</form>
		</div>

		<script>
		// Test veze sa Anthropic-om (AJAX, samo za admina).
		( function() {
			var btn = document.getElementById( 'nexxen-test-btn' );
			var out = document.getElementById( 'nexxen-test-result' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function() {
				out.textContent = 'Testiram…';
				btn.disabled = true;
				var data = new FormData();
				data.append( 'action', 'nexxen_test_connection' );
				data.append( '_nonce', '<?php echo esc_js( wp_create_nonce( 'nexxen_test' ) ); ?>' );
				fetch( '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function( r ) { return r.json(); } )
					.then( function( j ) {
						out.textContent = j.success ? ( '✅ ' + j.data.message ) : ( '❌ ' + j.data.message );
						out.style.color = j.success ? '#1a7f37' : '#b32d2e';
					} )
					.catch( function() { out.textContent = '❌ Greška pri testiranju.'; out.style.color = '#b32d2e'; } )
					.finally( function() { btn.disabled = false; } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * AJAX: pošalji minimalan poziv Anthropic-u da proveri da li ključ i model rade.
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Nedozvoljeno.' ) );
		}
		check_ajax_referer( 'nexxen_test', '_nonce' );

		$api_key = nexxen_get_api_key();
		if ( ! $api_key ) {
			wp_send_json_error( array( 'message' => 'API ključ nije podešen.' ) );
		}

		$response = wp_remote_post(
			Nexxen_REST::ANTHROPIC_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => Nexxen_REST::API_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'model'      => nexxen_get_option( 'model', 'claude-opus-4-8' ),
						'max_tokens' => 16,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Odgovori samo sa: OK',
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Mrežna greška: ' . $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			wp_send_json_success( array( 'message' => 'Veza radi! Ključ i model su ispravni.' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		$msg  = isset( $data['error']['message'] ) ? $data['error']['message'] : ( 'HTTP ' . $code );
		wp_send_json_error( array( 'message' => 'Anthropic greška (' . $code . '): ' . $msg ) );
	}

	/**
	 * Prebroj sačuvane porudžbine.
	 *
	 * @return int
	 */
	private function count_orders() {
		global $wpdb;
		$table = $wpdb->prefix . 'nexxen_orders';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		return (int) $count;
	}
}
