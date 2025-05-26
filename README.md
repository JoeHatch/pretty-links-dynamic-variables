# Pretty Links Dynamic Variables

**Converts Pretty Links into dynamic tracking URLs with unique click IDs based on selected affiliate program software.**

## Description

This WordPress plugin automatically appends a unique click ID parameter to outbound Pretty Links, customized per affiliate software. This is useful for tracking conversions, managing attribution, and improving analytics for affiliate campaigns.

Supported affiliate platforms include: Cellxpert, ReferOn, Income Access, MyAffiliates, Affise, Smartico, Post Affiliate Pro, and many more.

## Features

- Injects a unique `clickid` based on selected affiliate software
- Early interception of redirects for accurate attribution
- Full integration with Pretty Links plugin
- Admin metabox to select affiliate software per link
- Debug UI for testing and log inspection
- Fallback hook support via `prli_redirect_url` filter

## How It Works

When a visitor clicks on a Pretty Link, the plugin:

1. Intercepts the request before WordPress handles redirection
2. Looks up the configured affiliate software for that link
3. Appends a unique click ID using the correct query parameter for the selected platform
4. Redirects the user to the final URL with the appended click ID

If early interception fails, the plugin falls back to Pretty Links’ native `prli_redirect_url` filter.

## Supported Affiliate Software

| Platform             | Query Parameter       |
|----------------------|-----------------------|
| Cellxpert            | `afp1={clickid}`      |
| ReferOn              | `clickid={clickid}`   |
| Income Access        | `c={clickid}`         |
| MyAffiliates         | `payload={clickid}`   |
| MAP                  | `cid={clickid}`       |
| Mexos                | `var1={clickid}`      |
| Raventrack           | `s1={clickid}`        |
| ComeOn / Omarsys     | `var={clickid}`       |
| FirstCasinoPartners  | `clickid={clickid}`   |
| Alanbase             | `sub_id1={clickid}`   |
| Smartico / TAP       | `afp={clickid}`       |
| PostAffiliatePro     | `s1={clickid}`        |
| Affelios             | `clickid={clickid}` |
| Affise               | `sub1={clickid}`      |
| Realtime Gaming      | `subGid={clickid}`    |
| Quintessence         | `anid={clickid}`      |
| NetRefer             | `var1={clickid}`      |
| GoldenReels / PoshFriends / Superboss / Profit / Conquestador / Bons | `promo={clickid}` |

## Installation

1. Ensure [Pretty Links](https://wordpress.org/plugins/pretty-link/) is installed and active.
2. Upload this plugin to your `/wp-content/plugins/` directory.
3. Activate the plugin via the WordPress admin.
4. Edit a Pretty Link and select the appropriate affiliate software from the “Program Software” metabox.
5. Use the test links and debug UI (Settings > PL Debug) to verify functionality.

## Debugging

Go to **Settings > PL Debug** in the WordPress admin to:

- Test predefined Pretty Links
- View and clear the plugin log (`wp-content/dv-test.log`)

## Logging

The plugin writes logs to: `wp-content/dv-test.log`

Use this to trace redirect behavior, software mapping, and errors.

## Requirements

- WordPress 5.0+
- Pretty Links plugin
- PHP 7.2+

## Changelog

### v1.0
- Initial release
- Early hook-based redirect interception
- Admin UI for software selection
- Debug panel and log view

## License

MIT

## Author

[StatsDrone](https://www.statsdrone.com)
