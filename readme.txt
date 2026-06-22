=== Pretty Links Dynamic Variables ===
Contributors: statsdrone
Tags: pretty links, affiliate, click tracking, dynamic variables, subid
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.0.0
License: Proprietary (internal/private use only — see LICENSE)

Records every Pretty Link click with full context and injects an encrypted dynamic-variable token into the outbound affiliate URL based on the link's selected software.

== Description ==

Pretty Links Dynamic Variables is an add-on for the Pretty Links plugin. When a visitor clicks a Pretty Link it:

1. Captures click context client-side (page, the list position the visitor actually saw after any geo reordering, operator, placement).
2. Generates a unique 128-bit click ID and wraps it in an encrypted, opaque token (libsodium secretbox).
3. Records the click — always, even when the link has no software mapping — in a dedicated `wp_pldv_clicks` table with a `mapping_status`, so no data is lost.
4. Injects the token into the correct dynamic-variable parameter for the link's selected affiliate software, so the value surfaces in that platform's own report and can be reconciled later via the stored `sent_value`.
5. Lets Pretty Links perform its normal redirect — native click tracking stays intact.

= Managed admin (Pretty Links DV menu) =

* **Reports** — clicks by link / page / list position / operator, a DV-coverage view, drill-down, and a streamed CSV export (no row cap).
* **Settings** — capture scope, token/encryption, key source + rotation, IP/privacy mode, and row retention (enforced by a daily cron).
* **Mappings** — per-platform dynamic-variable parameter config, seeded from the StatsDrone DV sheet and editable.
* **Test** — pick a link and dry-run the whole pipeline, or send a tagged live test click.

== Requirements ==

* PHP 7.4+ (libsodium recommended; bundled with PHP 7.2+).
* The Pretty Links plugin, active. Without it the redirect filter never fires and no clicks are recorded; the plugin shows an admin notice in that case.

== Installation ==

1. Ensure Pretty Links is installed and active.
2. Upload the `pretty-links-dv` folder to `/wp-content/plugins/` (or install the ZIP via Plugins → Add New).
3. Activate "Pretty Links Dynamic Variables" through the Plugins screen.
4. (Recommended) Define an encryption key in `wp-config.php` so it lives outside the database:
   `define( 'PLDV_SECRET_KEY', '...base64 32-byte key...' );`
5. Open the **Pretty Links DV** admin menu to configure capture scope, mappings, and privacy.

= Key rotation =

To rotate the encryption key, set the new key as `PLDV_SECRET_KEY` and the previous one as `PLDV_SECRET_KEY_OLD` (or the `pldv_secret_key_old` option). Tokens issued before the rotation still decrypt.

= Uninstall =

Plugin options are removed on uninstall. Click history (the `wp_pldv_clicks` table) is preserved unless you define `PLDV_DROP_DATA_ON_UNINSTALL` as true, so an accidental delete never destroys reporting history.

== Privacy ==

IP storage defaults to a keyed HMAC (GDPR-friendly de-duplication without storing raw IPs); raw and off modes are available. User-Agent storage is off by default. Row retention can be set in days; a daily cron prunes anything older.

== Frequently Asked Questions ==

= Does this break Pretty Links' own click tracking? =

No. All work happens inside the redirect filter, so Pretty Links' native recording still runs and normal page loads cost nothing.

= What happens if libsodium is unavailable? =

The token is sent in plaintext and the admin shows an error notice so the downgrade is never silent. Define `PLDV_SECRET_KEY` and ensure PHP libsodium support to keep tokens encrypted.

= How do I reconcile a network postback back to a click? =

Look up the value the network reports against the `sent_value` column (indexed). For encrypted platforms that value is the opaque token; for numeric-only platforms it is the numeric value actually placed on the wire.

== Changelog ==

= 2.0.0 =

Full v2 rewrite into single-responsibility classes under the `PrettyLinksDV\` namespace.

* Records every click (lossless) and conditionally injects the DV token; missing mappings are recorded with a status rather than dropped.
* Encrypted, opaque tokens via libsodium secretbox, with key rotation and a plaintext-downgrade warning.
* Managed admin: Reports, Settings, Mappings, and a Test/simulate tool.
* Robust Pretty Links integration: handles both the array and `(string $url, $link)` redirect-filter contracts, records each click once, and injects idempotently.
* Adds the `sent_value` column (indexed) so network postbacks — including numeric-only platforms — can be reconciled back to a click.
* CSV export streams in pages (no silent 200-row truncation).
* Row-retention enforced by a daily prune cron.
* Fixes the v1 click-ID entropy bug (base_convert float rounding) that collapsed distinct IDs.

== Upgrade Notice ==

= 2.0.0 =
v2 rewrite. Deactivate the legacy single-file plugin (pldv-main.php) before activating. The clicks table upgrades automatically on load.
