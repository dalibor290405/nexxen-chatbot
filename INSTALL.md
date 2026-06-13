# Nexxen Asistent — uputstvo za instalaciju

AI chatbot (Claude / Anthropic) za WordPress + WooCommerce sajt **nexxen.rs**.
Ovaj dokument vodi Vas korak po korak. Pisano je za nekoga ko prvi put postavlja plugin.

---

## 0) Šta ovaj plugin radi (ukratko)

- Dodaje plutajući chat u donji desni ugao sajta.
- Posetilac piše poruku → poruka ide na **Vaš WordPress** → Vaš server (sa tajnim ključem) zove **Anthropic** → odgovor se vraća posetiocu.
- **Anthropic API ključ nikada ne napušta server** (ne vidi se u browseru ni u „Network" tabu).
- Kada posetilac potvrdi porudžbinu, plugin: pošalje **email**, sačuva **backup u bazi**, i (opciono) napravi **nacrt porudžbine u WooCommerce-u**.

---

## 1) Nabavite Anthropic API ključ

1. Idite na **https://console.anthropic.com** i napravite nalog.
2. Dopunite kredit (Billing) — naplata je po potrošnji (tokeni).
3. Otvorite **API Keys → Create Key** i iskopirajte ključ (počinje sa `sk-ant-...`).
4. Ključ čuvajte na sigurnom — tretirajte ga kao lozinku.

> 💡 Podrazumevani model je **Claude Opus 4.8** (najkvalitetniji). Ako želite niže troškove, u podešavanjima možete izabrati **Sonnet 4.6** ili **Haiku 4.5**.

---

## 2) Instalirajte plugin iz admin panela

1. Spakovani fajl je **`nexxen-asistent.zip`**.
2. U WordPress adminu: **Dodaci (Plugins) → Dodaj novi (Add New) → Otpremi dodatak (Upload Plugin)**.
3. Izaberite `nexxen-asistent.zip` → **Instaliraj odmah (Install Now)** → **Aktiviraj (Activate)**.

Pri aktivaciji plugin automatski pravi tabelu za backup porudžbina.

---

## 3) Unesite API ključ (dva načina — izaberite jedan)

### Način A — najbezbednije: `wp-config.php`
Otvorite `wp-config.php` (u korenu WordPress instalacije) i pre linije
`/* That's all, stop editing! */` dodajte:

```php
define( 'NEXXEN_ANTHROPIC_API_KEY', 'sk-ant-OVDE-VAS-KLJUC' );
```

Ovako ključ nije u bazi i ima prioritet. U podešavanjima ćete videti poruku da je ključ podešen preko `wp-config.php`.

### Način B — iz admin panela
**Podešavanja (Settings) → Nexxen Asistent → polje „API ključ"** → nalepite ključ → **Sačuvaj**.

---

## 4) Osnovna podešavanja

Idite na **Podešavanja → Nexxen Asistent** i podesite:

| Polje | Šta da unesete |
|---|---|
| **Model** | Opus 4.8 (default) ili jeftiniji po želji |
| **Email za porudžbine** | `dalibor290405@gmail.com` (već popunjeno) |
| **Rezervni kontakt** | email koji se prikazuje posetiocu kad AI ne radi |
| **WooCommerce nacrt** | uključeno (kreira pending porudžbinu) |
| **ID proizvoda** | (opciono) ID Nexxen proizvoda u WooCommerce-u |
| **Sistem-prompt** | **OBAVEZNO unesite tačnu cenu i uslove isporuke!** |
| **Dozvoljeni domen (CORS)** | obično već tačno: `https://nexxen.rs` |

> ⚠️ **Najvažnije:** u **Sistem-promptu** zamenite red
> `[UNESITE TAČNU CENU I USLOVE ISPORUKE OVDE ...]`
> stvarnom cenom, načinom plaćanja i isporukom. Od ovoga zavisi tačnost odgovora.

Kliknite **Sačuvaj podešavanja**.

---

## 5) Testirajte vezu

Na vrhu stranice sa podešavanjima kliknite **„Testiraj vezu sa Anthropic-om"**.
- ✅ „Veza radi!" — sve je u redu.
- ❌ Greška — proverite ključ, kredit na nalogu i izabrani model.

---

## 6) Provera na sajtu (čeklista posle postavljanja)

Otvorite **nexxen.rs** (najbolje u anonimnom/incognito prozoru) i proverite:

- [ ] U donjem desnom uglu se pojavljuje plavo dugme za chat.
- [ ] Klik otvara panel i prikazuje pozdravnu poruku.
- [ ] Pošaljete „Zdravo" → asistent odgovara na srpskom i persira.
- [ ] Pitate za cenu → odgovara tačno (ono što ste uneli u sistem-prompt).
- [ ] **Bezbednost ključa:** otvorite Developer Tools (F12) → tab **Network** → pošaljite poruku → zahtev ide na `…/wp-json/nexxen/v1/chat`, a **ne** na `api.anthropic.com`. Ključ se nigde ne vidi. ✅
- [ ] **Test porudžbine:** prođite kroz porudžbinu do potvrde, pa proverite:
	- [ ] stigao email na `dalibor290405@gmail.com`,
	- [ ] u **WooCommerce → Porudžbine** postoji nova „na čekanju (pending)" porudžbina,
	- [ ] (po želji) zapis u bazi (vidi se brojač u status panelu podešavanja).

> 📧 **Ako email ne stiže:** mnogi serveri loše šalju `wp_mail`. Instalirajte SMTP plugin (npr. „WP Mail SMTP") i podesite slanje preko pravog email servisa. Porudžbina je i tada sigurna — sačuvana je u bazi i u WooCommerce-u.

---

## 7) Ako nešto ne radi (debagovanje)

- Greške se beleže u: `wp-content/uploads/nexxen-asistent/log.txt`.
- Privremeno uključite WordPress deblog u `wp-config.php`:
  ```php
  define( 'WP_DEBUG', true );
  define( 'WP_DEBUG_LOG', true );
  ```
  Greške će biti i u `wp-content/debug.log`.
- Ako koristite **keš plugin** (puno keširanje stranica), widget i dalje radi jer svež bezbednosni token (nonce) uzima posebnim pozivom na `/wp-json/nexxen/v1/token`.

---

## 8) Kontrola troškova (preporuke)

- U podešavanjima postavite **Dnevni limit potrošnje (USD)** (npr. 5). Na 80% se beleži upozorenje u log; preko limita asistent privremeno nudi rezervni kontakt.
- **Rate limiting** je već uključen (8 poruka/min po IP-u, 40 po sesiji) — menjajte po potrebi.
- Za niže troškove smanjite **Maks. tokena** i **Istoriju**, ili izaberite jeftiniji model.

---

## 9) Kasnija zamena izgleda widgeta (opciono)

Ako kasnije pošaljete svoj `nexxen-asistent.html`, dizajn se menja u:
- `assets/css/widget.css` (izgled) i
- `assets/js/widget.js` (struktura/ponašanje).

Backend (bezbednost, porudžbine, limiti) ostaje isti — widget samo nastavlja da zove `…/wp-json/nexxen/v1/chat`.

---

Ako Vam zatreba pomoć oko bilo kog koraka, javite — tu sam.
