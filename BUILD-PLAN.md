# Pretty Links Dynamic Variables — v2 Build Plan

Target site: cryptoslate.com · Goal: per-click tracking (click ID + page + list
position), opaque-encrypted token sent to the affiliate network, and an in-admin
reporting dashboard.

This is a **rewrite** of the redirect/data layer. The admin meta box and the
software→parameter mapping table survive; the redirect engine, data model,
encryption, capture, and reporting are all new.

---

## 0. Hard prerequisites (blockers — confirm before building)

1. **Supported network set = the StatsDrone DV sheet.** The platform an editor
   picks in the meta box drives the DV param; supported platforms are exactly those
   in `Dynamic Variables details by Software`. New/unlisted networks are added to
   that source of truth, not guessed at.
2. **Encryption key custody.** Decide where the secret key lives (wp-config
   constant vs. options table) and the rotation story (see §4).
3. **PII / GDPR.** Decide whether IP/User-Agent are stored, hashed, or dropped —
   CryptoSlate is EU-facing. Affects the schema in §2.

> **The DV master sheet closes the "does the token come back?" gate.**
> Every platform in `Dynamic Variables details by Software` documents the report,
> column, and API field where its DV value surfaces — that's the param's purpose.
> So for any listed platform, sending our token as its primary DV param means it
> *will* appear in their reporting and can be reconciled.

---

## 1. Architecture change: stop intercepting before Pretty Links

Drop the triple `muplugins_loaded`/`plugins_loaded`/`init` early-intercept and the
`exit`-based 302. It bypasses Pretty Links' own click tracking and runs DB queries
on every front-end page. Instead, do all work inside Pretty Links' redirect path:

- Primary: `prli_redirect_url` filter (already used as fallback today) to mutate
  the outbound URL.
- Recording: hook Pretty Links' click-logged action if available, else record in
  the same filter. This keeps native Pretty Links stats intact and only does work
  on actual `/go/*` clicks — zero cost on normal page views.

---

## 2. Data model — new table `wp_pldv_clicks`

One row per click. Written at redirect time.

| Column            | Type                 | Notes                                            |
|-------------------|----------------------|--------------------------------------------------|
| id                | BIGINT UNSIGNED PK   | auto-increment                                   |
| click_id          | CHAR(32) UNIQUE      | raw random id (see §3 — fix the base_convert bug)|
| token             | VARCHAR(255)         | opaque encrypted token (built even if not sent)  |
| link_id           | BIGINT UNSIGNED      | prli_links.id, indexed                           |
| link_slug         | VARCHAR(190)         | denormalised for reporting                       |
| software          | VARCHAR(64) NULL     | mapping key (null if none selected)              |
| mapping_status    | VARCHAR(20)          | tracked / no_mapping / unsupported_value / no_software, indexed |
| param_sent        | VARCHAR(64) NULL     | the actual DV param appended (null if none)      |
| page              | VARCHAR(190)         | source page slug/id (see §5), indexed            |
| clicked_position  | SMALLINT UNSIGNED    | live position the user clicked from (see §5)     |
| original_order    | SMALLINT UNSIGNED    | editorial order (`data-original-order`)          |
| placement         | VARCHAR(32) NULL     | `data-cta-placement` (table / inline / …)        |
| context           | VARCHAR(64) NULL     | `data-cta-context` (review_category / …)         |
| operator          | VARCHAR(64) NULL     | `data-cta-target` (Thrill, Jack, …)              |
| target_url        | TEXT                 | final URL we redirected to                       |
| ip_hash           | CHAR(64) NULL        | sha256(ip+salt) or null per §0.3                 |
| user_agent        | VARCHAR(255) NULL    | optional per §0.3                                |
| is_test           | TINYINT(1) DEFAULT 0 | rows from the Test tool; excluded from reports   |
| created_at        | DATETIME             | indexed                                          |

Indexes on (link_id), (page, clicked_position), (created_at), unique (click_id).
Created via `dbDelta()` on activation; versioned for migrations. Provide an
uninstall hook to drop it (opt-in).

---

## 3. Click ID generation — fix the entropy bug

Current `base_convert(bin2hex(random_bytes(8)),16,36)` loses precision above
PHP_INT_MAX (verified: `ffff…ffff` and `ffff…fffe` collapse to the same value).
Replace with direct hex/base62 of the raw bytes:

```php
$click_id = bin2hex(random_bytes(16)); // 32 chars, full 128-bit entropy
```

No `base_convert` on the whole value. If a shorter human-ish id is wanted, base62
encode per-4-byte chunk, never the whole integer at once.

---

## 4. Encryption — opaque token to the affiliate

Chosen model: encrypt `click_id|page|list_position|link_id|ts` into one opaque
token, send it as the network's subid param. Only we can decrypt; the network
echoes it back on postback, we decrypt to reconcile the conversion.

- **Cipher:** libsodium `sodium_crypto_secretbox` (bundled PHP ≥7.2). Authenticated,
  modern, no config. Output = base64url(nonce ‖ ciphertext).
- **Key:** 32-byte key. Stored as a `wp-config.php` constant `PLDV_SECRET_KEY`
  (preferred — out of the DB) with fallback to an autoloaded option generated on
  activation. Document rotation: keep `PLDV_SECRET_KEY` + `PLDV_SECRET_KEY_OLD`
  so in-flight tokens issued before rotation still decrypt.
- **Length guard:** secretbox tokens are long. Many networks truncate subids.
  Mitigation: store the full record under the raw `click_id` and send a *shorter*
  token = base64url(nonce ‖ secretbox(click_id only)); page/position/link live in
  our DB keyed by click_id, not in the token. This keeps the wire value short and
  still opaque. **Decide:** self-contained token (longer) vs. click_id-as-key
  (shorter, requires our DB at postback time). Recommend the latter for length and
  because you control the DB.

---

## 5. Capturing page + list position (my recommendation)

You can't derive these server-side at click time, so the link markup must carry
them. The CryptoSlate listing markup already carries everything we need, and the
list is **reordered client-side by geo** (restricted operators sink — see the
`data-review-geo-badge` / `data-restricted-countries` attrs and the "See US
alternatives above" tooltip). So the position a visitor *sees* differs from the
server-rendered order. **Therefore capture must be JS-primary, at click time.**

**Primary — JS click handler.** Enqueue a tiny script that listens for clicks on
the CTAs (`a[data-review-cta="visit"]`, or `a.cta-btn[href*="/go/"]`). On click,
read straight from the existing DOM — no markup changes required:

| Field            | Source in the live markup                                        |
|------------------|------------------------------------------------------------------|
| slug             | `href` / `data-cta-url` → `/go/<slug>`                           |
| clicked_position | live visual index among *currently visible* rows at click time  |
| original_order   | row's `data-original-order` (+ `data-review-pagination-index`)   |
| placement        | `data-cta-placement` (e.g. `table`)                             |
| context          | `data-cta-context` (e.g. `review_category`)                     |
| operator         | `data-cta-target` (e.g. `Thrill`)                              |
| page             | `window.location.pathname` (or a body data-attr)                |

The handler rewrites the href just-in-time (links are `target="_blank"`, so mutate
on `mousedown`/`click` before navigation) to append
`?pldv_pg=…&pldv_pos=…&pldv_ord=…&pldv_pl=…&pldv_ctx=…&pldv_op=…`. Cheap, accurate,
and **cache-proof** — it reads the DOM the user actually sees, so full-page caching
(very likely on a site this heavy) doesn't poison the data the way a server-side
render-order filter would.

**Fallback — `the_content` filter** for pretty links inside article body text
(not in the table component), where no data attributes exist: append
`pldv_pg=<page>&pldv_pos=<render-index>` server-side. Lower fidelity, but those
inline links aren't geo-reordered so render order is fine there.

The redirect handler (§1) reads the `pldv_*` params from the incoming request,
validates them (whitelist chars, clamp position/order to a sane max, map operator
to known set), records them, and **strips them from the outbound URL** so they
never leak to the affiliate network.

**Why not Referer:** frequently stripped by browsers/privacy modes and never
carries position, placement, or operator. The DOM already has all of it.

Edge cases: same pretty link twice on a page (per-occurrence rows — each click is
its own event, so this resolves naturally with JS); pagination/sorting changing
visible order (we capture the order *at click*, which is what we want); AMP/feed
contexts (skip script). Confirm the table component isn't inside a Shadow DOM or
re-rendered after our listener binds (use event delegation on `document`).

**Capture scope: all clicks (per your call).** Every Pretty Link click is recorded
with all available data points; missing data points are simply null and the row
still lands (with `mapping_status`). The Settings page (§7.2) can narrow this to
software/CTA-flagged links if the volume on cryptoslate.com ever warrants it, but
the default is lossless.

---

## 6. Redirect handler flow (per click)

**Principle: always record, conditionally inject.** Recording a click and injecting
a DV param are separate steps. A missing/unknown software mapping (or no software
at all) never drops the click — it's recorded with a status flag so no data is lost
and coverage gaps become visible.

1. `prli_redirect_url($url, $link)` fires.
2. Read + validate `pldv_pg/pos/ord/pl/ctx/op` from request.
3. Generate `click_id` (§3); build opaque `token` (§4). (Always — even with no
   mapping, so the click has a stable id and the token is ready if a mapping is
   added later.)
4. Resolve `_pldv_software` and look up its mapping. Set `mapping_status`:
   - `tracked` — software set + known, report-surfaced param exists.
   - `no_mapping` — software set but not in the sheet / no usable param.
   - `unsupported_value` — mapping exists but rejects our token (e.g. Real Time
     Gaming numeric-only). Recorded, param skipped.
   - `no_software` — none selected on the link.
5. Insert `wp_pldv_clicks` row (click_id, token, software, mapping_status, link,
   page, clicked_position, original_order, placement, context, operator, target,
   ts, optional ip_hash) — **for every status**.
6. Only if `mapping_status === tracked`: append the network's param with value =
   `token`, correct `?`/`&` separator. Always strip `pldv_*` params from `$url` so
   they never leak. Otherwise return `$url` unchanged (but the click is logged).
7. Return final URL. Pretty Links performs its normal redirect + its own logging.

Failure mode: any exception → log, return original `$url` unchanged (never break a
redirect for a tracking failure). A recording failure must also never block the
redirect — wrap the insert so a DB error degrades to "redirect works, click
unlogged," not a broken link.

---

## 7. Managed admin application

One top-level "Pretty Links DV" admin menu with tabbed screens. All behavior is
configurable in-UI — no code edits to change params, format, or capture rules.
Every screen: capability-gated, nonce-protected, fully escaped. Config persists in
a single autoloaded option (`pldv_settings`), mappings in their own option/table.

### 7.1 Reports
Backed by `wp_pldv_clicks`.
- Filters: date range, link, software/network, page, position, placement, context,
  operator, **mapping_status**.
- Views: clicks per link / page / list position / network / operator; totals and
  time series; **original_order vs clicked_position** (did rank or brand drive the
  click?).
- **DV coverage view:** breakdown by `mapping_status` surfacing `no_mapping` /
  `no_software` links so gaps are actionable, never lost.
- Row drill-down: click_id, token, decrypted payload (admin-only), final URL, all
  data points, ts. CSV export.
- Perf: paginated, indexed queries; no `SELECT *`; short-TTL transient caches for
  aggregates.

### 7.2 Settings (fully managed)
- **Capture scope:** record all Pretty Link clicks (default) ↔ only software-tagged
  / CTA-flagged. Per-data-point toggles (which of the data points in §2 to store).
- **Token & format** (drives §4): encryption on/off; cipher/encoding (secretbox +
  base64url default; hex/base62 options); token shape (short click_id-as-key ↔
  self-contained payload); which fields go *into* the token; max length /
  truncation policy; field separators; per-platform param-name override.
- **Style:** value casing/prefix/affixes, separator style, and report display prefs
  (timezone, default date range, columns shown).
- **Keys:** show active key source (`PLDV_SECRET_KEY` const vs option), generate /
  rotate (keeps `_OLD` for in-flight tokens), never prints the raw key.
- **Privacy/retention:** IP store mode (off / salted-hash / raw), UA on/off, row
  retention window + prune cron.
- **Logging:** level + destination (off / file-guarded / table), size cap.
- Validation on save; "reset to defaults"; export/import settings JSON.

### 7.3 Mappings editor (data-driven, editable)
Seeded from the StatsDrone sheet, editable in-UI.
- **Canonical schema:** `Var1–Var5, SubId, PubId, ClickId1, ClickId2`. Each platform
  = `{ slug, params:[{urlParam, canonical}], reportName, reportColumn, apiField,
  notes, valueConstraint }`. Imported from the CSV as source-of-truth, then editable.
- Configure, per platform, **which canonical slot(s) carry which tracked parameter**
  (e.g. token→ClickId1, page→Var1, position→Var2) and which param name to emit.
- **Platform quirks captured as data:** CellXpert merges `afp1–5`→one "AFP";
  ComeOn/Omarsys comma-joins `&var=v1,v2,…`; **Real Time Gaming numeric-only** (a
  base64 token breaks it → constraint flag forces numeric or skips injection);
  PartnerMatrix appends to an existing `btag` prefix; NetRefer param varies per
  program. `valueConstraint` encodes these so injection respects them.
- Add/edit/disable platforms in-UI; unlisted networks added here before use, never
  guessed. Re-import from an updated sheet without losing local edits (merge).
- Per-link meta box: pick software, flag tracked, show supported slots + live URL
  preview.

### 7.4 Test / simulate tool (test fully on specific links)
Replaces the old debug page with a real harness.
- Pick any specific Pretty Link (autocomplete) and inject simulated context
  (page, position, operator, placement, etc.).
- **Dry-run:** show resolved software + mapping, generated click_id, built token,
  the exact final outbound URL, the **decrypted** payload (verify round-trip), the
  `mapping_status`, and the exact `wp_pldv_clicks` row that *would* be written — all
  **without redirecting or persisting**.
- **Live test:** optionally send a real test click (tagged `is_test=1` so it's
  excluded from reports by default) and view the recorded row + the live redirect.
- Surfaces validation problems (length truncation, numeric-only violations, missing
  mapping) before they hit production traffic.
- System status: Pretty Links active, table present, key configured, libsodium
  available, cron scheduled.

### 7.5 (Phase 2) Postback / conversion loop
REST route the network calls on conversion with the token → decrypt → mark click
converted → reports show conversions next to clicks. Closes attribution.

---

## 9. Cross-cutting hardening

- **Logging:** move off the web-readable `wp-content/*.log`. Use a table or
  `wp-content/uploads/pldv/` with `.htaccess`/index guard; rotate/cap size.
- **Caching:** memoize `table_exists`/mappings per request; never `SHOW TABLES`
  on the hot path.
- **Security:** keep nonce+cap+post-type checks (already good); add capability
  checks + nonces on the new dashboard actions and CSV export; escape all output;
  prepared statements everywhere.
- **i18n, uninstall.php, readme.txt** (WP.org header), semantic version bump to 2.0.
- **Tests:** unit-test token encrypt/decrypt round-trip, click_id entropy, URL
  param merging; integration-test the filter against a seeded prli_links row.

---

## 10. Phasing

- **P1 — data + redirect core:** clicks table, click_id fix, canonical mapping
  imported from the sheet, `prli_redirect_url` always-record/conditional-inject,
  token builder. Platform-agnostic — no blockers.
- **P2 — capture:** JS-primary page/position/context/operator capture (all data
  points) + validation + `the_content` fallback.
- **P3 — managed admin:** Settings (§7.2), Mappings editor (§7.3), Test/simulate
  tool (§7.4), Reports + coverage (§7.1) + CSV. This is the bulk of the build.
- **P4 — postback loop:** REST endpoint, conversion reconciliation (optional).
- **P5 — hardening/release:** logging, caching, i18n, uninstall, docs, version 2.0.

---

## Open questions for you

1. Token shape: self-contained (longer) vs. click_id-as-key (shorter)? — see §4.
   *(Recommend short.)*
2. Store IP/UA at all? Hashed or raw? — GDPR.
4. ~~Server-side vs JS capture~~ — **RESOLVED:** JS-primary (§5), because the
   listing is geo-reordered client-side. Confirm only: is the table component ever
   wrapped in Shadow DOM / re-rendered after load (affects listener binding)?
5. ~~Same link twice on a page~~ — **RESOLVED:** per-occurrence rows, natural with
   per-click JS events (§5).
6. "Position" definition for the report: clicked-position (what the user saw) is
   primary; we also store original_order. Confirm both are wanted.
