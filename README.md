# Pretty Links Dynamic Variables (v2)

**Records every Pretty Link click with full context — click ID, page, list position, operator, placement — and injects an encrypted dynamic-variable token into the outbound affiliate URL based on the link's selected software.**

## What it does

When a visitor clicks a Pretty Link, this plugin:

1. Captures click context client-side (page, the list position the visitor actually saw after any geo reordering, operator, placement) and passes it along.
2. Generates a unique 128-bit click ID and wraps it in an **encrypted, opaque token** (libsodium secretbox).
3. Records the click — **always**, even when the link has no software mapping — in a dedicated `wp_pldv_clicks` table with a `mapping_status` so no data is lost.
4. Injects the token into the correct dynamic-variable parameter for the link's selected affiliate software (so the value surfaces in that platform's own report and can be reconciled later).
5. Lets Pretty Links perform its normal redirect — its native click tracking stays intact (no early interception).

## Managed admin (Pretty Links DV menu)

- **Reports** — clicks by link / page / list position / operator, a DV-coverage view (which links are losing mapping), drill-down, and CSV export.
- **Settings** — capture scope, token/encryption, key source + rotation, IP/privacy mode, retention. Fully managed; no code edits.
- **Mappings** — per-platform dynamic-variable parameter config, seeded from the StatsDrone DV sheet and editable, with platform value constraints (numeric-only, etc.).
- **Test** — pick a link, dry-run the whole pipeline to see the generated URL, token, decrypted payload, and the exact row that would be written — or send a tagged live test click.

## Architecture

Single-responsibility classes under the `PrettyLinksDV\` namespace in `includes/`:

| File | Role |
|------|------|
| `pretty-links-dv.php` | Bootstrap, autoloader, activation |
| `class-pldv-db.php` | Clicks table + inserts |
| `class-pldv-click-id.php` | Click ID generation |
| `class-pldv-crypto.php` | Token encryption + key management |
| `class-pldv-mappings.php` | Software → DV-parameter resolution |
| `class-pldv-settings.php` | Settings option + defaults |
| `class-pldv-recorder.php` | `prli_redirect_url` record/inject + `simulate()` |
| `class-pldv-capture.php` + `assets/js/pldv-capture.js` | Click-time DOM capture |
| `class-pldv-admin.php` | Reports / Settings / Mappings / Test UI |
| `class-pldv-reports.php` | Reporting queries |
| `class-pldv-meta-box.php` | Per-link software selection |
| `data/dv-mappings.json` | DV parameter mappings (from the sheet) |

## Configuration

- **Encryption key** (recommended): add to `wp-config.php`
  ```php
  define( 'PLDV_SECRET_KEY', '...32-byte-base64-key...' );
  // optional during rotation:
  define( 'PLDV_SECRET_KEY_OLD', '...previous-key...' );
  ```
  If unset, a key is generated and stored in the options table on activation.
- **Drop click history on uninstall** (off by default):
  ```php
  define( 'PLDV_DROP_DATA_ON_UNINSTALL', true );
  ```

## Requirements

- WordPress 5.0+
- Pretty Links plugin
- PHP 7.4+ (libsodium, bundled since PHP 7.2, recommended for encryption)

## Status

- **v2.0.0-dev.** P1 (data + redirect core), P2 (capture), and P3 (managed admin) are implemented and tested. P4 (network postback/conversion reconciliation) is planned — see `BUILD-PLAN.md`.
- The legacy single-file plugin `pldv-main.php` is **superseded** and must not be active at the same time as this plugin.

## License

MIT · [StatsDrone](https://www.statsdrone.com)
